<?php

namespace App\Services\Ai\Catalog;

use App\Models\AiDraft;
use App\Models\User;
use App\Services\Ai\Eksekusi\RingkasanDraf;
use App\Services\Ai\Referensi\HasilResolusi;
use App\Services\Ai\Referensi\JenisReferensi;
use App\Services\Ai\Referensi\KandidatReferensi;
use App\Services\Ai\Referensi\KonversiSatuan;
use App\Services\Ai\Referensi\MemakaiPilihanReferensi;
use App\Services\Ai\Referensi\PilihanReferensiDraf;
use App\Services\Ai\Referensi\ResolverReferensi;
use App\Services\Ai\Referensi\TakaranPerPorsi;
use App\Services\Ai\Tools\ToolResult;
use App\Support\Rbac;

/**
 * `buat_menu` / `ubah_menu`: komposisi bahan dirujuk dengan NAMA (atau ID) + jumlah + satuan; pemetaan nama -> ID
 * bahan baku dilakukan SERVER (ResolverReferensi, SPPG pengguna, hak baca bahan-baku) sehingga pengguna tidak pernah
 * diminta ID dan LLM tidak perlu memanggil tool cari lebih dulu.
 *
 * - Tepat satu cocok -> dipakai; takaran dikonversi ke `gram_per_porsi` hanya untuk pasangan satuan pasti
 *   (TakaranPerPorsi); tak kompatibel -> TIDAK ada draf, model menanyakan ulang.
 * - Ambigu -> draf dengan baris placeholder (tanpa bahan_baku_id; gagal Form Request sehingga tidak dapat disimpan
 *   tanpa memilih) + pilihan tertunda `_pilihan`; pengguna memilih lewat tombol Bot atau di Mini App.
 * - Tidak ditemukan / ID bukan milik SPPG pengguna -> TIDAK ada draf; bahan tidak pernah dibuat otomatis.
 */
class MenuWriteTool extends CatalogWriteTool implements MemakaiPilihanReferensi
{
    public const KOLOM = 'bahan';

    public const MAKS_BARIS_RINGKASAN = 20;

    public function __construct(ResourceSpec $spec, string $kind)
    {
        parent::__construct($spec, $kind);

        if ($kind === 'C') {
            $this->description = 'Siapkan DRAF menu baru; tersimpan setelah pengguna menekan Simpan di Mini App. Bahan cukup ditulis namanya, ID dicari sistem.';
        } elseif ($kind === 'U') {
            $this->description = 'Siapkan DRAF perubahan menu (target_id dari cari_menu); tersimpan setelah pengguna menyimpan di Mini App. Bahan cukup ditulis namanya, ID dicari sistem.';
        }
    }

    protected function fields(): array
    {
        $fields = parent::fields();

        if (isset($fields['properties'][self::KOLOM])) {
            $fields['properties'][self::KOLOM] = [
                'type' => 'array',
                'maxItems' => 50,
                'description' => $this->action === 'U' ? 'Komposisi LENGKAP per porsi (mengganti komposisi lama).' : 'Komposisi per porsi.',
                // Toleran terhadap bentuk keluaran model yang sah maknanya (Groq memvalidasi tool call terhadap skema ini
                // dan menolak seluruh permintaan dengan HTTP 400 tool_use_failed bila tidak cocok): `nama` cukup, ID opsional
                // dan boleh null/0 (diabaikan bila nama diisi; ResolverReferensi tetap memverifikasi ID milik SPPG).
                'items' => [
                    'type' => 'object',
                    'properties' => [
                        'nama' => ['type' => ['string', 'null'], 'maxLength' => ResolverReferensi::MAKS_PANJANG, 'description' => 'Nama bahan seperti disebut pengguna.'],
                        'bahan_baku_id' => ['type' => ['integer', 'null'], 'description' => 'Opsional; tidak perlu bila nama diisi.'],
                        'jumlah' => ['type' => 'number', 'minimum' => 0, 'maximum' => 100000],
                        'satuan' => ['type' => 'string', 'maxLength' => 20, 'description' => 'g, kg, ml, atau l.'],
                    ],
                    'required' => ['jumlah', 'satuan'],
                    'additionalProperties' => false,
                ],
            ];
        }

        return $fields;
    }

    protected function rujukan(User $user, array $arguments): ToolResult|array
    {
        if (! array_key_exists(self::KOLOM, $arguments) || ! is_array($arguments[self::KOLOM])) {
            return [$arguments, [], []];
        }

        $jenis = JenisReferensi::bahanBaku();
        $resolver = app(ResolverReferensi::class);

        if (! $resolver->bolehMembaca($user, $jenis)) {
            return ToolResult::denied('Pengguna tidak berhak membaca data bahan baku, jadi komposisi tidak dapat diisi lewat chat. Sarankan mengisi komposisi di Mini App.');
        }

        $bahan = [];
        $slot = [];
        $info = [];
        $tidakAda = [];
        $masalah = [];
        $dipakai = [];
        $daftar = [];

        // Lintasan 1: resolusi semua baris lebih dulu, agar kandidat ambigu yang sudah dipakai baris TEPAT (di mana pun
        // posisinya) dapat disaring sebelum slot pilihan dibuat.
        foreach (array_values($arguments[self::KOLOM]) as $n => $item) {
            $baris = 'Bahan ke-'.($n + 1);

            if (! is_array($item)) {
                $masalah[] = $baris.' tidak valid.';

                continue;
            }

            $angka = KonversiSatuan::angka($item['jumlah'] ?? null);
            $satuan = ResolverReferensi::normalisasi((string) ($item['satuan'] ?? ''));

            if ($angka === null) {
                $masalah[] = $baris.': jumlah harus angka lebih dari 0 (maks 6 desimal).';

                continue;
            }

            $daftar[] = [$baris, $angka, $satuan, $resolver->resolusi($user, $jenis, $item['nama'] ?? null, $item['bahan_baku_id'] ?? null)];
        }

        $idTepat = [];

        foreach ($daftar as [, , , $hasil]) {
            if ($hasil->tepat()) {
                $idTepat[$hasil->item->id] = true;
            }
        }

        foreach ($daftar as [$baris, $angka, $satuan, $hasil]) {
            switch ($hasil->status) {
                case HasilResolusi::TEPAT:
                    /** @var KandidatReferensi $k */
                    $k = $hasil->item;
                    [$nilai, $pesan] = TakaranPerPorsi::hitung($angka, $satuan, $k);

                    if ($pesan !== null) {
                        $masalah[] = $pesan;
                    } elseif (isset($dipakai[$k->id])) {
                        $masalah[] = $k->label().' disebut lebih dari sekali.';
                    } else {
                        $dipakai[$k->id] = true;
                        $bahan[] = ['bahan_baku_id' => $k->id, 'gram_per_porsi' => $nilai];
                    }
                    break;

                case HasilResolusi::AMBIGU:
                    $kenal = KonversiSatuan::kenali($satuan);
                    $dasar = $kenal === null ? null : KonversiSatuan::geser($angka, $kenal[1]);

                    if ($dasar === null || KonversiSatuan::jumlahDesimal($dasar) > 2 || (float) $dasar <= 0 || (float) $dasar > TakaranPerPorsi::MAKS) {
                        $masalah[] = 'Takaran "'.self::kutip($hasil->dicari).'" belum jelas: sebutkan dalam gram atau kg (ml atau liter untuk bahan cair), maks 2 desimal.';
                        break;
                    }

                    // Kandidat yang pasti tidak dapat dipakai tidak ditawarkan: satuan tak kompatibel dengan takaran,
                    // atau bahan sudah dipakai baris lain yang tepat (duplikat ditolak Form Request).
                    $layak = [];
                    $pesanSatuan = null;
                    $duplikat = [];

                    foreach ($hasil->kandidat as $c) {
                        [, $pesan] = TakaranPerPorsi::hitung($angka, $satuan, $c);

                        if ($pesan !== null) {
                            $pesanSatuan ??= $pesan;
                        } elseif (isset($idTepat[$c->id])) {
                            $duplikat[] = $c->label();
                        } else {
                            $layak[] = $c;
                        }
                    }

                    if ($layak === []) {
                        $masalah[] = $pesanSatuan !== null
                            ? $pesanSatuan
                            : 'Semua bahan yang cocok dengan "'.self::kutip($hasil->dicari).'" sudah ada di komposisi ini ('.implode(', ', array_slice($duplikat, 0, 3)).').';
                        break;
                    }

                    // Placeholder TANPA bahan_baku_id: gagal Form Request, sehingga tidak dapat tersimpan sebelum dipilih.
                    // Satu kandidat layak tetap menjadi pilihan (dikonfirmasi pengguna lewat tombol, tidak ditebak).
                    $bahan[] = ['gram_per_porsi' => KonversiSatuan::keJson($dasar)];
                    $slot[] = [
                        'jenis' => $jenis->kunci,
                        'kolom' => self::KOLOM,
                        'indeks' => count($bahan) - 1,
                        'dicari' => self::kutip($hasil->dicari),
                        'jumlah' => $angka,
                        'satuan' => mb_substr($satuan, 0, 20),
                        'kandidat' => array_map(fn (KandidatReferensi $c) => $c->id, $layak),
                        'lebih_banyak' => $hasil->lebihBanyak,
                    ];
                    // Untuk LLM cukup nama + satuan (ID hanya di draf/tombol; LLM tidak perlu dan tidak boleh memilih).
                    $info[] = [
                        'dicari' => self::kutip($hasil->dicari),
                        'pilihan' => array_map(fn (KandidatReferensi $c) => ['nama' => $c->nama, 'satuan' => $c->satuan], $layak),
                        'lebih_banyak' => $hasil->lebihBanyak,
                    ];
                    break;

                case HasilResolusi::TIDAK_ADA:
                    $tidakAda[] = '"'.self::kutip($hasil->dicari).'"';
                    break;

                case HasilResolusi::DITOLAK:
                    return ToolResult::denied('Pengguna tidak berhak membaca data bahan baku.');

                default:
                    $masalah[] = $baris.': sebutkan nama bahannya.';
            }
        }

        if ($tidakAda !== [] || $masalah !== []) {
            $pesan = 'Draf belum dibuat.';

            if ($tidakAda !== []) {
                $pesan .= ' Bahan baku tidak ditemukan di SPPG pengguna: '.implode(', ', array_slice($tidakAda, 0, 10)).'. Sampaikan dengan jelas; jangan menebak bahan lain dan jangan meminta ID.'
                    .(Rbac::can($user, $jenis->modul, 'C')
                        ? ' Tawarkan membuat bahan baku itu lewat draf terpisah ('.$jenis->toolBuat.') HANYA bila pengguna menyetujuinya.'
                        : ' Sarankan meminta Staf Gudang menambahkan bahan baku itu lebih dulu.');
            }

            if ($masalah !== []) {
                $pesan .= ' Tanyakan ke pengguna: '.implode(' ', array_slice($masalah, 0, 6));
            }

            return ToolResult::invalid($pesan);
        }

        $arguments[self::KOLOM] = $bahan;

        return [$arguments, $slot, $info];
    }

    public function terapkanPilihan(User $user, array $payload, array $slot, KandidatReferensi $item): array|string
    {
        $bahan = $payload[self::KOLOM] ?? null;
        $indeks = $slot['indeks'] ?? null;

        if (! is_array($bahan) || ! is_int($indeks) || ! isset($bahan[$indeks]) || ! is_array($bahan[$indeks]) || isset($bahan[$indeks]['bahan_baku_id'])) {
            return 'Draf sudah berubah. Ulangi permintaan Anda.';
        }

        foreach ($bahan as $i => $b) {
            if ($i !== $indeks && is_array($b) && ($b['bahan_baku_id'] ?? null) === $item->id) {
                return $item->label().' sudah ada di komposisi menu ini. Pilih bahan lain atau lengkapi di Mini App.';
            }
        }

        $angka = KonversiSatuan::angka($slot['jumlah'] ?? null);

        if ($angka === null) {
            return 'Takaran tidak valid. Ulangi permintaan Anda.';
        }

        [$nilai, $pesan] = TakaranPerPorsi::hitung($angka, (string) ($slot['satuan'] ?? ''), $item);

        if ($pesan !== null) {
            return $pesan.' Pilih bahan lain, lengkapi di Mini App, atau ulangi permintaan.';
        }

        $bahan[$indeks] = ['bahan_baku_id' => $item->id, 'gram_per_porsi' => $nilai];
        $payload[self::KOLOM] = $bahan;

        return $payload;
    }

    public function keteranganSlot(array $slot): string
    {
        $jumlah = str_replace('.', ',', (string) ($slot['jumlah'] ?? ''));

        return trim($jumlah.' '.($slot['satuan'] ?? '')).' per porsi';
    }

    /** Ringkasan server: kolom menu + satu baris per bahan dengan NAMA dan SATUAN dari basis data (SPPG pengguna). */
    public function ringkasan(User $user, AiDraft $draft): RingkasanDraf
    {
        $payload = (array) $draft->payload;
        $salinan = clone $draft;
        $salinan->payload = array_diff_key($payload, [self::KOLOM => true]);
        $dasar = parent::ringkasan($user, $salinan);

        if (! is_array($payload[self::KOLOM] ?? null)) {
            return $dasar;
        }

        $bahan = array_values(array_filter($payload[self::KOLOM], 'is_array'));
        $ids = array_values(array_filter(array_map(fn (array $b) => $b['bahan_baku_id'] ?? null, $bahan), 'is_int'));
        $nama = app(ResolverReferensi::class)->banyakId($user, JenisReferensi::bahanBaku(), $ids);
        $tertunda = [];

        foreach (PilihanReferensiDraf::tertunda($draft) as $slot) {
            $tertunda[$slot['indeks']] = $slot;
        }

        $baris = $dasar->baris;

        if ($bahan === []) {
            $baris[] = 'Bahan: (kosong)';
        }

        foreach ($bahan as $i => $b) {
            if ($i >= self::MAKS_BARIS_RINGKASAN) {
                $baris[] = 'Bahan: ... dan '.(count($bahan) - $i).' bahan lain (lihat di Mini App)';
                break;
            }

            $id = $b['bahan_baku_id'] ?? null;
            $nilai = is_int($b['gram_per_porsi'] ?? null) || is_float($b['gram_per_porsi'] ?? null) ? str_replace('.', ',', (string) $b['gram_per_porsi']) : '-';

            if (is_int($id) && isset($nama[$id])) {
                $baris[] = 'Bahan: '.$nama[$id]->label().' - '.$nilai.' '.TakaranPerPorsi::labelNilai($nama[$id]->satuan).' per porsi';
            } elseif (isset($tertunda[$i])) {
                $baris[] = 'Bahan: (belum dipilih untuk "'.$tertunda[$i]['dicari'].'") - '.$this->keteranganSlot($tertunda[$i]);
            } else {
                $baris[] = 'Bahan: (tidak ditemukan'.(is_int($id) ? ' #'.$id : '').') - '.$nilai.' per porsi';
            }
        }

        return new RingkasanDraf($dasar->judul, $baris, $dasar->target, $dasar->peringatan);
    }

    private static function kutip(string $teks): string
    {
        return str_replace('"', "'", mb_substr(ResolverReferensi::normalisasi($teks), 0, KandidatReferensi::MAKS_NAMA));
    }
}
