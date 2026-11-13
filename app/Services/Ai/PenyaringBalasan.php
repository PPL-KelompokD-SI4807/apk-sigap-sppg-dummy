<?php

namespace App\Services\Ai;

/**
 * Penyaring jawaban teks model SEBELUM dikirim ke Telegram (temuan staging 2026-11: model menyalin pembungkus
 * <hasil_tool_xxxx>{JSON argumen tool}</hasil_tool_xxxx> ke jawaban alih-alih memanggil tool tulis).
 *
 * Dibuang: blok tag internal (hasil_tool, data_pengguna, tool_call/tool_result/function) lengkap, bersarang, huruf
 * besar/kecil, akhiran acak, spasi/tanda hubung di nama tag, dan tag pembuka yang tidak ditutup (sampai akhir teks);
 * blok kode / objek JSON (objek atau larik berisi pasangan "kunci": nilai).
 * Fail-safe: bila SETELAH penyaringan masih ada jejak internal (tag yatim, tag di-escape, JSON terpotong, nama kolom
 * `*_id`, skor keyakinan), saring() mengembalikan null dan pemanggil mengganti SELURUH teks dengan pesan server.
 * Kelas murni (tanpa I/O), tidak pernah mencatat isi teks.
 */
final class PenyaringBalasan
{
    /** Nama tag internal: hasil_tool, data_pengguna, tool_call(s), tool_result, function(_call). */
    private const NAMA_TAG = '(?:hasil[\s_-]*tool|data[\s_-]*pengguna|tool[\s_-]*calls?|tool[\s_-]*results?|function(?:[\s_-]*call)?)';

    /** Pasangan "kunci": nilai bergaya JSON (nilai diawali kutip, angka, kurung, true/false/null). */
    private const KUNCI_JSON = '~["\']\s*[\p{L}_][\p{L}\p{N}_ ]{0,40}["\']\s*:\s*(?:["\'\[{\-0-9]|true\b|false\b|null\b)~iu';

    /** Batas putaran pembuangan blok bersarang (masukan sudah dibatasi panjangnya). */
    private const MAKS_PUTARAN = 25;

    /**
     * @return array{0: ?string, 1: int} [teks bersih atau null (fail-safe: ganti seluruh teks), jumlah bagian dibuang]
     */
    public static function saring(string $teks): array
    {
        // Karakter format tak terlihat (zero-width, kendali bidi) dapat menyamarkan nama tag: dibuang lebih dulu.
        $teks = (string) preg_replace('/[\p{Cf}]/u', '', $teks);
        $dibuang = 0;

        // 1. Blok lengkap, dari yang terdalam: <tag ...> isi tanpa tag internal lain </tag ...>.
        $blok = '~<\s*'.self::NAMA_TAG.'[^<>]*>(?:(?!<\s*/?\s*'.self::NAMA_TAG.').)*?<\s*/\s*'.self::NAMA_TAG.'[^<>]*>~isu';

        for ($i = 0; $i < self::MAKS_PUTARAN; $i++) {
            $baru = (string) preg_replace($blok, ' ', $teks, -1, $n);
            $teks = $baru;
            $dibuang += $n;

            if ($n === 0) {
                break;
            }
        }

        // 2. Tag pembuka tanpa penutup (atau terpotong tanpa '>'): semua sesudahnya dianggap hasil tool -> dibuang.
        $teks = (string) preg_replace('~<\s*'.self::NAMA_TAG.'(?:[^<>]*>|[^<>]*$).*$~isu', ' ', $teks, -1, $n);
        $dibuang += $n;

        // 3. Blok kode Markdown berisi JSON / data terstruktur.
        $teks = (string) preg_replace_callback('~```[^\n`]*\n?(.*?)```~su', function (array $m) use (&$dibuang): string {
            if (self::miripJson($m[1])) {
                $dibuang++;

                return ' ';
            }

            return $m[0];
        }, $teks);

        // 4. Objek/larik JSON seimbang yang memuat "kunci": nilai.
        [$teks, $n] = self::buangJson($teks);
        $dibuang += $n;

        $teks = trim((string) preg_replace("/[ \t]{2,}/u", ' ', (string) preg_replace("/\n{3,}/u", "\n\n", $teks)));

        return [self::adaJejakInternal($teks) ? null : $teks, $dibuang];
    }

    /**
     * True bila teks memuat jejak data internal: nama tag internal (termasuk yatim/di-escape), pasangan "kunci": nilai,
     * nama kolom `*_id`, kata kunci status tool, atau skor keyakinan/confidence.
     */
    public static function adaJejakInternal(string $teks): bool
    {
        $teks = (string) preg_replace('/[\p{Cf}]/u', '', $teks);

        return preg_match('~hasil[\s_-]*tool|data[\s_-]*pengguna|(?:<|&lt;)\s*/?\s*(?:tool[\s_-]*calls?|tool[\s_-]*results?|function)\b~iu', $teks) === 1
            || preg_match(self::KUNCI_JSON, $teks) === 1
            || preg_match('~\b[a-z]+(?:_[a-z]+)*_id\b~u', $teks) === 1
            || preg_match('~\b(?:draf_dibuat|perlu_dicek|perlu_diperbaiki|galat_tool|target_id)\b~iu', $teks) === 1
            || preg_match('~\b(?:keyakinan|confidence)\b\W{0,4}(?:\p{L}+\W{0,3}){0,3}[0-9]~iu', $teks) === 1;
    }

    /**
     * Klaim model bahwa draf sudah dibuat/disiapkan (atau data sudah tersimpan/dicatat). Dipakai server: klaim tanpa
     * AiDraft baru pada giliran itu tidak boleh diteruskan ke pengguna.
     */
    public static function klaimDraf(string $teks): bool
    {
        $kerja = '(?:siap|siapkan|disiapkan|menyiapkan|dibuat|dibuatkan|membuat|buat|buatkan|bikin|dibikin|susun|disusun|menyusun|catat|dicatat|mencatat|simpan|disimpan|menyimpan|tersimpan|terbentuk|terbuat)';

        // Per kalimat: kalimat tanya atau berisi penyangkalan ("belum dibuat", "tidak dapat membuat draf") bukan klaim.
        foreach (preg_split('~(?<=[.!?])\s+|\n+~u', $teks) ?: [] as $kalimat) {
            $kalimat = trim($kalimat);

            if ($kalimat === '' || str_ends_with($kalimat, '?')
                || preg_match('~\b(?:tidak|belum|gagal|bukan|tak|jangan|batal|tanpa|apakah)\b~iu', $kalimat) === 1) {
                continue;
            }

            if (preg_match('~\bdra(?:f|ft)\b.{0,80}?\b'.$kerja.'\b~iu', $kalimat) === 1
                || preg_match('~\b'.$kerja.'\b.{0,40}?\bdra(?:f|ft)\b~iu', $kalimat) === 1
                || preg_match('~\b(?:sudah|telah)\s+saya\s+(?:catat|simpan|buat|buatkan|siapkan|susun)\b|\bberhasil\s+(?:di)?(?:simpan|catat|buat)\b~iu', $kalimat) === 1) {
                return true;
            }
        }

        return false;
    }

    /** Isi blok kode tampak seperti JSON / data terstruktur. */
    private static function miripJson(string $isi): bool
    {
        $isi = trim($isi);

        return $isi !== '' && (preg_match('~^[\[{]~', $isi) === 1 || preg_match(self::KUNCI_JSON, $isi) === 1);
    }

    /**
     * Buang objek/larik seimbang ({...} / [...]) yang memuat "kunci": nilai. Pemindaian sadar-string (tanda kurung
     * di dalam "..." tidak dihitung). Teks lain (mis. "[catatan]") dibiarkan.
     *
     * @return array{0: string, 1: int}
     */
    private static function buangJson(string $teks): array
    {
        $hasil = '';
        $dibuang = 0;
        $panjang = strlen($teks);
        $i = 0;

        while ($i < $panjang) {
            $c = $teks[$i];

            if ($c !== '{' && $c !== '[') {
                $hasil .= $c;
                $i++;

                continue;
            }

            $akhir = self::akhirSeimbang($teks, $i);

            if ($akhir !== null && preg_match(self::KUNCI_JSON, substr($teks, $i, $akhir - $i + 1)) === 1) {
                $hasil .= ' ';
                $dibuang++;
                $i = $akhir + 1;

                continue;
            }

            $hasil .= $c;
            $i++;
        }

        return [$hasil, $dibuang];
    }

    /** Posisi kurung penutup yang menyeimbangkan kurung di $mulai, atau null (tidak seimbang / terpotong). */
    private static function akhirSeimbang(string $teks, int $mulai): ?int
    {
        $tumpukan = [];
        $dalamString = null;
        $panjang = strlen($teks);

        for ($i = $mulai; $i < $panjang; $i++) {
            $c = $teks[$i];

            if ($dalamString !== null) {
                if ($c === '\\') {
                    $i++;
                } elseif ($c === $dalamString) {
                    $dalamString = null;
                }

                continue;
            }

            if ($c === '"') {
                $dalamString = $c;
            } elseif ($c === '{' || $c === '[') {
                $tumpukan[] = $c === '{' ? '}' : ']';
            } elseif ($c === '}' || $c === ']') {
                if (array_pop($tumpukan) !== $c) {
                    return null;
                }

                if ($tumpukan === []) {
                    return $i;
                }
            }
        }

        return null;
    }
}
