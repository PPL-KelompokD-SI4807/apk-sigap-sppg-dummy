<?php

namespace App\Services\Ai\Tools;

/**
 * Memisahkan argumen TOOL TULIS yang tidak lolos skema menjadi [argumen yang lolos, galat per kolom], agar masukan
 * salah (mis. `lemak: "5 ml"`) menjadi draf "perlu diperbaiki" di Mini App alih-alih dikembalikan ke LLM untuk
 * ditanyakan balik (GalatDraf).
 *
 * Aturan (fail closed, tidak pernah melonggarkan validasi):
 * - Argumen yang tidak ada di skema (tingkat atas atau di dalam baris) = seluruh panggilan ditolak (null), seperti
 *   sebelumnya: itu perilaku LLM/injeksi (mis. menyisipkan status/total), bukan salah ketik pengguna.
 * - Kolom wajib skema tidak diperiksa di sini; Form Request modul tetap memeriksanya saat dry-run.
 * - Kolom tingkat atas yang salah dibuang + dicatat; baris larik (mis. `bahan.1`) yang salah dibuang + dicatat pada
 *   kolom lariknya. `keyakinan` dan `target_id` dibuang tanpa galat kolom (bukan isian formulir).
 * - Hasil akhir divalidasi ulang dengan validator yang sama; galat yang tidak dapat dipetakan ke kolom (struktur
 *   terlalu dalam, nama argumen tidak sah di dalam baris) = null (ditolak seperti sebelumnya).
 * Pesan galat tidak pernah memuat nilai masukan.
 */
final class PemisahArgumen
{
    private const TANPA_GALAT = ['keyakinan', 'target_id'];

    /**
     * @param  array<string, mixed>  $schema
     * @param  array<string, mixed>  $arguments
     * @return array{0: array<string, mixed>, 1: array<string, list<string>>}|null
     */
    public static function pisahkan(ArgumentValidator $validator, array $schema, array $arguments): ?array
    {
        $properti = (array) ($schema['properties'] ?? []);
        $skema = $schema;
        $skema['required'] = [];
        $galat = [];

        // Argumen di luar skema (mis. status/total/versi yang dicoba disisipkan lewat injeksi) bukan salah ketik
        // pengguna: seluruh panggilan tetap ditolak seperti sebelumnya, tanpa draf.
        foreach (array_keys($arguments) as $kunci) {
            if (! is_string($kunci) || ! array_key_exists($kunci, $properti)) {
                return null;
            }
        }

        for ($putaran = 0; $putaran < 4; $putaran++) {
            $errors = $validator->validate($skema, $arguments);

            if ($errors === []) {
                return [$arguments, $galat];
            }

            $hapusBaris = [];

            foreach ($errors as $error) {
                if (preg_match('/^([A-Za-z0-9_]{1,64})((?:\.[A-Za-z0-9_]{1,64}){0,6}): (.{1,300})$/su', $error, $m) !== 1 || ! array_key_exists($m[1], $arguments)
                    || str_contains($m[3], 'argumen tidak dikenal') || str_contains($m[3], 'kata kunci tidak didukung') || str_contains($m[3], 'format skema')) {
                    return null;
                }

                [, $atas, $sisa, $pesan] = $m;
                $catat = ! in_array($atas, self::TANPA_GALAT, true);

                if ($sisa !== '' && is_array($arguments[$atas]) && array_is_list($arguments[$atas]) && preg_match('/^\.(\d{1,2})(?:\.|$)/', $sisa, $n) === 1) {
                    $hapusBaris[$atas][(int) $n[1]] = true;

                    if ($catat) {
                        $galat[$atas][] = 'Baris ke-'.((int) $n[1] + 1).' tidak sesuai format; lengkapi di formulir.';
                    }

                    continue;
                }

                unset($arguments[$atas]);

                if ($catat) {
                    $galat[$atas][] = self::pesan($pesan);
                }
            }

            foreach ($hapusBaris as $atas => $indeks) {
                if (is_array($arguments[$atas] ?? null)) {
                    $arguments[$atas] = array_values(array_diff_key($arguments[$atas], $indeks));
                }
            }

            $galat = array_map(fn (array $p) => array_values(array_unique($p)), $galat);
        }

        return null;
    }

    /** Pesan ramah dari pesan validator (pesan validator tidak pernah memuat nilai masukan). */
    public static function pesan(string $pesan): string
    {
        $pesan = trim($pesan);

        return match (true) {
            str_starts_with($pesan, 'tipe harus') && (str_contains($pesan, 'number') || str_contains($pesan, 'integer')) => 'Harus berupa angka tanpa satuan.',
            str_starts_with($pesan, 'tipe harus') && str_contains($pesan, 'boolean') => 'Harus ya atau tidak.',
            str_starts_with($pesan, 'tipe harus') => 'Format isian tidak sesuai.',
            str_starts_with($pesan, 'tidak boleh null') => 'Wajib diisi.',
            default => mb_strtoupper(mb_substr($pesan, 0, 1)).mb_substr($pesan, 1),
        };
    }

    /**
     * Galat dari jalur argumen yang ditolak penyedia LLM (JSON pointer tanpa nilai, sudah disaring JalurSkema), mis.
     * `/lemak` -> lemak; `/bahan/0/jumlah` -> bahan.
     *
     * @param  list<string>  $jalur
     * @return array<string, list<string>>
     */
    public static function dariJalurPenyedia(array $jalur): array
    {
        $galat = [];

        foreach ($jalur as $j) {
            $segmen = explode('/', ltrim($j, '/'));
            $atas = $segmen[0];

            if ($atas === '' || in_array($atas, self::TANPA_GALAT, true)) {
                continue;
            }

            $galat[$atas] = [count($segmen) > 1
                ? 'Sebagian baris tidak sesuai format; periksa kembali.'
                : 'Format isian tidak sesuai (mis. satuan yang tidak dikenal); isi ulang.'];
        }

        return $galat;
    }
}
