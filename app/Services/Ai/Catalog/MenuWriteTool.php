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
use App\Services\Ai\Tools\ToolContext;
use App\Services\Ai\Tools\ToolResult;

/**
 * `buat_menu` / `ubah_menu`: komposisi bahan dirujuk dengan NAMA (atau ID) + jumlah + satuan; pemetaan nama -> ID
 * bahan baku dilakukan SERVER (ResolverReferensi, SPPG pengguna, hak baca bahan-baku) sehingga pengguna tidak pernah
 * diminta ID dan LLM tidak perlu memanggil tool cari lebih dulu.
 *
 * - Tepat satu cocok -> dipakai; takaran dikonversi ke `gram_per_porsi` hanya untuk pasangan satuan pasti
 *   (TakaranPerPorsi); tak kompatibel/tak dikenal -> baris tanpa gram_per_porsi + galat kolom (draf "perlu diperbaiki").
 * - Ambigu -> draf dengan baris placeholder (tanpa bahan_baku_id; gagal Form Request sehingga tidak dapat disimpan
 *   tanpa memilih) + pilihan tertunda `_pilihan`; pengguna memilih lewat tombol Bot atau di Mini App.
 * - Tidak ditemukan / ID bukan milik SPPG pengguna -> baris tanpa bahan_baku_id + galat kolom; pengguna memilih bahan
 *   yang ada di Mini App. Bahan tidak pernah dibuat otomatis dan LLM tidak diminta bertanya ulang.
 */
class MenuWriteTool extends CatalogWriteTool implements MemakaiPilihanReferensi
{
    public const KOLOM = 'bahan';

    public const MAKS_BARIS_RINGKASAN = 20;

    public const PESAN_JUMLAH = 'Jumlah harus angka lebih dari 0 (maks 6 desimal).';

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
                        'nama' => ['type' => ['string', 'null'], 'maxLength' => ResolverReferensi::MAKS_PANJANG, 'description' => 'Nama persis seperti ditulis pengguna.'],
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

    protected function rujukan(User $user, array $arguments, ToolContext $context): ToolResult|array
    {
        if (! array_key_exists(self::KOLOM, $arguments) || ! is_array($arguments[self::KOLOM])) {
            return [$arguments, [], [], []];
        }

        $jenis = JenisReferensi::bahanBaku();
        $resolver = app(ResolverReferensi::class);

        if (! $resolver->bolehMembaca($user, $jenis)) {
            return ToolResult::denied('Pengguna tidak berhak membaca data bahan baku, jadi komposisi tidak dapat diisi lewat chat. Sarankan mengisi komposisi di Mini App.');
        }

        $daftar = [];
        $galat = [];

        // Lintasan 1: resolusi semua baris lebih dulu, agar kandidat ambigu yang sudah dipakai baris TEPAT (di mana pun
        // posisinya) dapat disaring sebelum slot pilihan dibuat.
        foreach (array_values($arguments[self::KOLOM]) as $n => $item) {
            if (! is_array($item)) {
                $galat[self::KOLOM][] = 'Bahan ke-'.($n + 1).' tidak valid.';

                continue;
            }

            $daftar[] = [
                KonversiSatuan::angka($item['jumlah'] ?? null),
                ResolverReferensi::normalisasi((string) ($item['satuan'] ?? '')),
                $resolver->resolusi($user, $jenis, $item['nama'] ?? null, $item['bahan_baku_id'] ?? null, $context->teksPengguna),
            ];
        }

        $idTepat = [];

        foreach ($daftar as [, , $hasil]) {
            if ($hasil->tepat()) {
                $idTepat[$hasil->item->id] = true;
            }
        }

        // Lintasan 2: setiap baris tetap menjadi satu baris draf (urutan dipertahankan). Bagian yang valid diisi; bagian
        // yang salah dikosongkan dan dicatat sebagai galat per kolom (`bahan.<i>.bahan_baku_id` / `.gram_per_porsi`),
        // diperbaiki pengguna di Mini App. Tidak ada pertanyaan balik lewat LLM dan bahan tidak pernah dibuat otomatis.
        $bahan = [];
        $slot = [];
        $info = [];
        $dipakai = [];

        foreach ($daftar as [$angka, $satuan, $hasil]) {
            $i = count($bahan);
            $kunci = self::KOLOM.'.'.$i;

            if ($hasil->status === HasilResolusi::DITOLAK) {
                return ToolResult::denied('Pengguna tidak berhak membaca data bahan baku.');
            }

            if ($hasil->tepat()) {
                /** @var KandidatReferensi $k */
                $k = $hasil->item;
                [$nilai, $pesan] = $angka === null ? [null, self::PESAN_JUMLAH] : TakaranPerPorsi::hitung($angka, $satuan, $k);
                $baris = [];

                if (isset($dipakai[$k->id])) {
                    $galat[$kunci.'.bahan_baku_id'][] = $k->label().' disebut lebih dari sekali.';
                } else {
                    $dipakai[$k->id] = true;
                    $baris['bahan_baku_id'] = $k->id;
                }

                if ($pesan !== null) {
                    $galat[$kunci.'.gram_per_porsi'][] = $pesan;
                } else {
                    $baris['gram_per_porsi'] = $nilai;
                }

                $bahan[] = $baris;

                continue;
            }

            [$dasar, $pesanTakaran] = self::takaranTanpaBahan($angka, $satuan);
            $baris = $dasar === null ? [] : ['gram_per_porsi' => KonversiSatuan::keJson($dasar)];

            if ($pesanTakaran !== null) {
                $galat[$kunci.'.gram_per_porsi'][] = $pesanTakaran;
            }

            if ($hasil->status !== HasilResolusi::AMBIGU) {
                $galat[$kunci.'.bahan_baku_id'][] = $hasil->status === HasilResolusi::TIDAK_ADA
                    ? 'Bahan baku "'.self::kutip($hasil->dicari).'" tidak ditemukan di SPPG ini. Pilih bahan yang ada.'
                    : 'Sebutkan atau pilih bahan bakunya.';
                $bahan[] = $baris;

                continue;
            }

            // Ambigu: kandidat yang sudah dipakai baris lain yang tepat disaring (duplikat ditolak Form Request); bila
            // takaran valid, kandidat bersatuan tak kompatibel juga disaring.
            $layak = [];
            $pesanSatuan = null;
            $duplikat = [];

            foreach ($hasil->kandidat as $c) {
                $pesan = $angka === null || $pesanTakaran !== null ? null : TakaranPerPorsi::hitung($angka, $satuan, $c)[1];

                if (isset($idTepat[$c->id])) {
                    $duplikat[] = $c->label();
                } elseif ($pesan !== null) {
                    $pesanSatuan ??= $pesan;
                } else {
                    $layak[] = $c;
                }
            }

            if ($layak === []) {
                if ($pesanSatuan !== null) {
                    $galat[$kunci.'.gram_per_porsi'][] = $pesanSatuan;
                    unset($baris['gram_per_porsi']);
                } else {
                    $galat[$kunci.'.bahan_baku_id'][] = 'Semua bahan yang cocok dengan "'.self::kutip($hasil->dicari).'" sudah ada di komposisi ini ('.implode(', ', array_slice($duplikat, 0, 3)).').';
                }

                $galat[$kunci.'.bahan_baku_id'][] = 'Pilih bahan bakunya.';
                $bahan[] = $baris;

                continue;
            }

            // Placeholder TANPA bahan_baku_id: gagal Form Request, sehingga tidak dapat tersimpan sebelum dipilih.
            // Satu kandidat layak tetap menjadi pilihan (dikonfirmasi pengguna lewat tombol/Mini App, tidak ditebak).
            $bahan[] = $baris;
            $slot[] = [
                'jenis' => $jenis->kunci,
                'kolom' => self::KOLOM,
                'indeks' => $i,
                'dicari' => self::kutip($hasil->dicari),
                'jumlah' => $angka ?? '',
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
        }

        $arguments[self::KOLOM] = $bahan;

        return [$arguments, $slot, $info, $galat];
    }

    /**
     * Takaran baris yang bahannya belum pasti (ambigu/tidak ditemukan): dikonversi ke satuan dasar (gram atau ml) bila
     * satuannya dikenal dan hasilnya sah; selain itu [null, pesan galat].
     *
     * @return array{0: ?string, 1: ?string}
     */
    private static function takaranTanpaBahan(?string $angka, string $satuan): array
    {
        if ($angka === null) {
            return [null, self::PESAN_JUMLAH];
        }

        $kenal = KonversiSatuan::kenali($satuan);
        $dasar = $kenal === null ? null : KonversiSatuan::geser($angka, $kenal[1]);

        if ($dasar === null || KonversiSatuan::jumlahDesimal($dasar) > 2 || (float) $dasar <= 0 || (float) $dasar > TakaranPerPorsi::MAKS) {
            return [null, 'Takaran belum jelas: isi dalam gram (ml untuk bahan cair), maks 2 desimal.'];
        }

        return [$dasar, null];
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
