<?php

namespace App\Services\DataUji;

use App\Enums\HasilMutu;
use App\Enums\JenisMutasi;
use App\Enums\JenisPemasok;
use App\Enums\JenisTitikLayanan;
use App\Enums\KategoriBahan;
use App\Enums\KategoriKeluhan;
use App\Enums\KategoriPengeluaran;
use App\Enums\KelompokSasaran;
use App\Enums\PrioritasKeluhan;
use App\Enums\StatusArmada;
use App\Enums\StatusJadwalDistribusi;
use App\Enums\StatusJadwalProduksi;
use App\Enums\StatusKeluhan;
use App\Enums\StatusKirim;
use App\Enums\StatusPembelianBahan;
use App\Enums\StatusPengajuanPengeluaran;
use App\Enums\StatusPerjalanan;
use App\Enums\StatusPersetujuanMenu;
use App\Enums\StatusPersetujuanPengeluaran;
use App\Enums\SumberKeluhan;
use App\Models\Armada;
use App\Models\BahanBaku;
use App\Models\BuktiDistribusi;
use App\Models\FeedbackKeluhan;
use App\Models\HargaPemasok;
use App\Models\JadwalDistribusi;
use App\Models\JadwalProduksi;
use App\Models\LogProduksi;
use App\Models\Menu;
use App\Models\MutasiStok;
use App\Models\NotifikasiPerjalanan;
use App\Models\Pemasok;
use App\Models\PembelianBahan;
use App\Models\PemeriksaanMutu;
use App\Models\PencairanDana;
use App\Models\PenerimaManfaat;
use App\Models\PengajuanMenu;
use App\Models\Pengeluaran;
use App\Models\Pengemudi;
use App\Models\PersetujuanPengeluaran;
use App\Models\Sppg;
use App\Models\StandarGizi;
use App\Models\TitikLayanan;
use App\Services\Perjalanan\TripNotificationService;
use App\Services\Persetujuan\BiayaMenuService;
use App\Services\Rekap\DailySummaryService;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;

/**
 * Mengisi satu SPPG fiktif "(Uji)" yang koheren untuk uji staging. Deterministik (tanpa data acak) dan tanpa pustaka pembangkit data,
 * tanpa NIK, tanpa pengguna (semua kolom FK ke users bersifat nullable dan dibiarkan null), dan tanpa ID Telegram.
 *
 * Penulisan memakai Eloquent biasa di dalam Model::withoutEvents: tidak ada baris audit_logs palsu dan tidak ada
 * event domain (notifikasi Telegram, keluhan kritis, dsb.) yang terpancar. Mode kering menjalankan jalur kode yang
 * sama tetapi tidak menyentuh basis data, sehingga hitungan baris yang dilaporkan sama dengan hasil penulisan.
 *
 * Rancangan angka: kapasitas 1200 porsi, target harian 1000 porsi (3 titik: 320 + 280 + 400); anggaran bulan
 * berjalan Rp100.000.000 dengan pengeluaran disetujui Rp82.560.000 (82,56%, melewati ambang 80%, tanpa baris
 * peringatan_anggarans agar persetujuan berikutnya memicu notifikasi ambang). Tidak ada berkas nota/foto yang dibuat.
 */
final class PengisiDataUji
{
    private bool $tulis = false;

    private int $idSemu = 0;

    /** @var array<string, int> */
    private array $hitung = [];

    private CarbonImmutable $hari;

    private int $sppgId = 0;

    /** [nama, jenis, jarak km, sasaran, PIC, telepon PIC, jam berangkat, jam rencana tiba, indeks armada/pengemudi, estimasi menit] */
    private const TITIK = [
        ['SDN Uji 1 (Uji)', JenisTitikLayanan::Sekolah, 1.5, 320, 'PIC Satu (Uji)', '0812-0000-9001', '09:00:00', '09:20:00', 0, 20],
        ['PAUD Tunas Uji (Uji)', JenisTitikLayanan::Paud, 3.2, 280, 'PIC Dua (Uji)', '0812-0000-9002', '09:00:00', '09:30:00', 1, 30],
        ['SDN Uji 2 (Uji)', JenisTitikLayanan::Sekolah, 4.0, 400, 'PIC Tiga (Uji)', '0812-0000-9003', '10:00:00', '10:40:00', 0, 40],
    ];

    /** nama => [kategori, satuan, masuk, keluar, minimum, indeks pemasok, hari kedaluwarsa, harga satuan] */
    private const BAHAN = [
        'Beras' => [KategoriBahan::Karbohidrat, 'kg', 600, 120, 100, 0, 180, 13000],
        'Ayam' => [KategoriBahan::ProteinHewani, 'kg', 150, 60, 40, 1, 20, 38000],
        'Telur' => [KategoriBahan::ProteinHewani, 'kg', 80, 30, 20, 0, 12, 28000],
        // Di bawah stok minimum dan hampir kedaluwarsa.
        'Ikan Lele' => [KategoriBahan::ProteinHewani, 'kg', 100, 85, 30, 1, 2, 32000],
        'Tahu' => [KategoriBahan::ProteinNabati, 'kg', 60, 20, 15, 0, 3, 12000],
        'Tempe' => [KategoriBahan::ProteinNabati, 'kg', 60, 20, 15, 0, 10, 14000],
        'Bayam' => [KategoriBahan::Sayur, 'kg', 50, 15, 10, 1, 1, 9000],
        'Wortel' => [KategoriBahan::Sayur, 'kg', 70, 20, 10, 1, 14, 11000],
        // Sudah kedaluwarsa kemarin.
        'Pisang' => [KategoriBahan::Buah, 'kg', 90, 30, 20, 1, -1, 15000],
        // Di bawah stok minimum.
        'Minyak Goreng' => [KategoriBahan::Lainnya, 'liter', 80, 70, 25, 0, 120, 18000],
    ];

    /** Penerimaan tambahan hari ini dari pemasok (mengisi widget pengiriman pemasok). */
    private const MASUK_HARI_INI = ['Telur' => 10, 'Wortel' => 15];

    public function __construct(
        private readonly BiayaMenuService $biayaMenu,
        private readonly DailySummaryService $rekap,
    ) {}

    /**
     * @param  bool  $tulis  false = mode kering (tanpa akses tulis ke basis data)
     * @return array<string, int> jumlah baris per tabel
     */
    public function isi(bool $tulis): array
    {
        $this->tulis = $tulis;
        $this->idSemu = 0;
        $this->hitung = [];
        $this->hari = CarbonImmutable::now((string) config('operasional.timezone', 'Asia/Jakarta'))->startOfDay();

        if (! $tulis) {
            $this->bangun();

            return $this->hitung;
        }

        Model::withoutEvents(fn () => DB::transaction(fn () => $this->bangun()));

        return $this->hitung;
    }

    private function bangun(): void
    {
        $sppg = $this->simpan(Sppg::class, [
            'kode' => PenandaDataUji::KODE_SPPG,
            'nama' => 'SPPG Uji Staging (Uji)',
            'alamat' => 'Jl. Contoh Uji No. 1, Kota Contoh',
            'mitra_yayasan' => 'Yayasan Fiktif Uji (Uji)',
            'telepon' => '0812-0000-0100',
            'kapasitas_porsi_harian' => 1200,
            'status_operasional' => 'aktif',
        ]);
        $this->sppgId = (int) $sppg->getKey();

        $titik = $this->titikDanPenerima();
        $pemasoks = $this->pemasok();
        $bahan = $this->bahanDanStok($pemasoks);
        $this->standarGizi();
        $menus = $this->menu($bahan);
        $this->hargaDanPengajuan($pemasoks, $bahan, $menus[2]);
        [$armadas, $pengemudis] = $this->armadaDanPengemudi();
        $jadwalHariIni = $this->operasional($titik, $menus, $armadas, $pengemudis);
        $this->keluhan($titik, $jadwalHariIni);
        $this->keuangan($pemasoks, $bahan);
        $this->rekapHarian();
    }

    /**
     * @param  class-string<Model>  $kelas
     * @param  array<string, mixed>  $atribut
     */
    private function simpan(string $kelas, array $atribut): Model
    {
        $model = new $kelas;
        $model->forceFill($this->sppgId > 0 && $kelas !== Sppg::class ? ['sppg_id' => $this->sppgId] + $atribut : $atribut);
        $this->hitung[$model->getTable()] = ($this->hitung[$model->getTable()] ?? 0) + 1;

        if ($this->tulis) {
            $model->save();
        } else {
            $model->setAttribute($model->getKeyName(), ++$this->idSemu);
        }

        return $model;
    }

    private function hitungLangsung(string $tabel, int $jumlah = 1): void
    {
        $this->hitung[$tabel] = ($this->hitung[$tabel] ?? 0) + $jumlah;
    }

    private function waktu(CarbonImmutable $hari, string $jam): CarbonImmutable
    {
        return $hari->setTimeFromTimeString($jam);
    }

    /** @return list<TitikLayanan> */
    private function titikDanPenerima(): array
    {
        $titik = [];

        foreach (self::TITIK as $i => [$nama, $jenis, $jarak, $sasaran, $pic, $telepon]) {
            /** @var TitikLayanan $t */
            $t = $this->simpan(TitikLayanan::class, [
                'nama' => $nama,
                'jenis' => $jenis,
                'alamat' => 'Jl. Uji No. '.($i + 1).', Kota Contoh',
                'jarak_km' => $jarak,
                'jumlah_sasaran' => $sasaran,
                'pic_nama' => $pic,
                'pic_telepon' => $telepon,
                // Tanpa ID chat Telegram: notifikasi perjalanan dialihkan ke Kepala SPPG (uji pengalihan).
                'pic_telegram_chat_id' => null,
                'is_active' => true,
            ]);
            $titik[] = $t;

            $kelompok = match ($jenis) {
                JenisTitikLayanan::Paud => KelompokSasaran::Balita,
                default => KelompokSasaran::Siswa,
            };

            // Agregat saja: 6 penerima per titik (di atas ambang penyamaran k=5), tanpa nama asli/kelas/alergi.
            for ($n = 1; $n <= 6; $n++) {
                $this->simpan(PenerimaManfaat::class, [
                    'titik_layanan_id' => $t->getKey(),
                    'nama' => sprintf('Penerima Uji %d-%02d (Uji)', $i + 1, $n),
                    'kelompok_sasaran' => $kelompok,
                    'kelas_keterangan' => null,
                    'catatan_alergi' => null,
                    'is_active' => true,
                ]);
            }
        }

        return $titik;
    }

    /** @return list<Pemasok> */
    private function pemasok(): array
    {
        $daftar = [
            ['Koperasi Desa Sejahtera (Uji)', JenisPemasok::Koperasi, 'Beras, minyak goreng, telur, tahu, tempe', '0812-0000-8001'],
            ['Kelompok Tani Subur (Uji)', JenisPemasok::Petani, 'Sayur, buah, ayam, ikan', '0812-0000-8002'],
        ];

        $hasil = [];

        foreach ($daftar as [$nama, $jenis, $jenisBahan, $kontak]) {
            $hasil[] = $this->simpan(Pemasok::class, [
                'nama' => $nama,
                'jenis' => $jenis,
                'jenis_bahan' => $jenisBahan,
                'kontak' => $kontak,
                'alamat' => 'Desa Contoh, Kabupaten Contoh',
                'is_active' => true,
            ]);
        }

        return $hasil;
    }

    /**
     * @param  list<Pemasok>  $pemasoks
     * @return array<string, BahanBaku>
     */
    private function bahanDanStok(array $pemasoks): array
    {
        $bahan = [];
        $no = 1;

        foreach (self::BAHAN as $nama => [$kategori, $satuan, $masuk, $keluar, $minimum, $idx, $hari]) {
            $kedaluwarsa = $this->hari->addDays($hari)->toDateString();
            $tambahan = self::MASUK_HARI_INI[$nama] ?? 0;

            $b = $this->simpan(BahanBaku::class, [
                'pemasok_id' => $pemasoks[$idx]->getKey(),
                'kode' => sprintf('UJI-BB-%02d', $no++),
                'nama' => $nama,
                'kategori' => $kategori,
                'satuan' => $satuan,
                // Stok = saldo mutasi (masuk - keluar), sesuai aturan StockService.
                'stok' => $masuk + $tambahan - $keluar,
                'stok_minimum' => $minimum,
                'kedaluwarsa_terdekat' => $kedaluwarsa,
            ]);

            $this->simpan(MutasiStok::class, [
                'bahan_baku_id' => $b->getKey(),
                'pemasok_id' => $pemasoks[$idx]->getKey(),
                'jenis' => JenisMutasi::Masuk,
                'jumlah' => $masuk,
                'harga_satuan' => self::BAHAN[$nama][7],
                'tanggal_mutasi' => $this->hari->subDays(5)->toDateString(),
                'tanggal_kedaluwarsa' => $kedaluwarsa,
                'catatan' => 'Penerimaan awal (uji)',
            ]);
            $this->simpan(MutasiStok::class, [
                'bahan_baku_id' => $b->getKey(),
                'jenis' => JenisMutasi::Keluar,
                'jumlah' => $keluar,
                'tanggal_mutasi' => $this->hari->subDays(2)->toDateString(),
                'catatan' => 'Pemakaian dapur (uji)',
            ]);

            if ($tambahan > 0) {
                $this->simpan(MutasiStok::class, [
                    'bahan_baku_id' => $b->getKey(),
                    'pemasok_id' => $pemasoks[$idx]->getKey(),
                    'jenis' => JenisMutasi::Masuk,
                    'jumlah' => $tambahan,
                    'harga_satuan' => self::BAHAN[$nama][7],
                    'tanggal_mutasi' => $this->hari->toDateString(),
                    'tanggal_kedaluwarsa' => $kedaluwarsa,
                    'catatan' => 'Penerimaan hari ini (uji)',
                ]);
            }

            $bahan[$nama] = $b;
        }

        return $bahan;
    }

    private function standarGizi(): void
    {
        $rentang = [
            [KelompokSasaran::Siswa, [550, 700], [18, 28], [15, 25], [80, 100], [6, 12]],
            [KelompokSasaran::Balita, [300, 450], [8, 15], [10, 18], [45, 65], [3, 7]],
            [KelompokSasaran::IbuHamil, [600, 800], [22, 32], [18, 28], [85, 110], [8, 14]],
        ];

        foreach ($rentang as [$kelompok, $e, $p, $l, $k, $s]) {
            $this->simpan(StandarGizi::class, [
                'kelompok_sasaran' => $kelompok,
                'energi_min' => $e[0], 'energi_max' => $e[1],
                'protein_min' => $p[0], 'protein_max' => $p[1],
                'lemak_min' => $l[0], 'lemak_max' => $l[1],
                'karbohidrat_min' => $k[0], 'karbohidrat_max' => $k[1],
                'serat_min' => $s[0], 'serat_max' => $s[1],
            ]);
        }
    }

    /**
     * Dua menu disetujui (dapat dijadwalkan) dan satu menu diajukan ke investor.
     *
     * @param  array<string, BahanBaku>  $bahan
     * @return list<Menu>
     */
    private function menu(array $bahan): array
    {
        $daftar = [
            ['Nasi, Ayam Goreng, Tumis Bayam, Pisang (Uji)', StatusPersetujuanMenu::Disetujui, 'Siklus 1', [640, 24, 19, 92, 9], ['Beras' => 90, 'Ayam' => 60, 'Bayam' => 40, 'Pisang' => 50, 'Minyak Goreng' => 8]],
            ['Nasi, Ikan Lele Goreng, Sup Wortel, Tempe (Uji)', StatusPersetujuanMenu::Disetujui, 'Siklus 1', [610, 26, 18, 88, 8], ['Beras' => 90, 'Ikan Lele' => 60, 'Wortel' => 45, 'Tempe' => 30, 'Minyak Goreng' => 8]],
            ['Nasi, Ayam Panggang, Sup Wortel (Uji)', StatusPersetujuanMenu::Diajukan, 'Siklus 2', [630, 23, 18, 91, 8], ['Beras' => 90, 'Ayam' => 60, 'Wortel' => 45, 'Minyak Goreng' => 6]],
        ];

        $menus = [];

        foreach ($daftar as [$nama, $status, $siklus, $gizi, $komposisi]) {
            /** @var Menu $menu */
            $menu = $this->simpan(Menu::class, [
                'nama_menu' => $nama,
                'kelompok_sasaran' => KelompokSasaran::Siswa,
                'tanggal_sajian' => null,
                'siklus' => $siklus,
                'energi_kkal' => $gizi[0],
                'protein_g' => $gizi[1],
                'lemak_g' => $gizi[2],
                'karbohidrat_g' => $gizi[3],
                'serat_g' => $gizi[4],
                'status_persetujuan' => $status,
                'versi' => 1,
            ]);

            foreach ($komposisi as $namaBahan => $gram) {
                $this->hitungLangsung('menu_bahan');

                if ($this->tulis) {
                    DB::table('menu_bahan')->insert([
                        'menu_id' => $menu->getKey(),
                        'bahan_baku_id' => $bahan[$namaBahan]->getKey(),
                        'gram_per_porsi' => $gram,
                    ]);
                }
            }

            $menus[] = $menu;
        }

        return $menus;
    }

    /**
     * @param  list<Pemasok>  $pemasoks
     * @param  array<string, BahanBaku>  $bahan
     */
    private function hargaDanPengajuan(array $pemasoks, array $bahan, Menu $menuDiajukan): void
    {
        foreach ($bahan as $nama => $b) {
            $this->simpan(HargaPemasok::class, [
                'pemasok_id' => $pemasoks[self::BAHAN[$nama][5]]->getKey(),
                'bahan_baku_id' => $b->getKey(),
                'harga' => self::BAHAN[$nama][7],
                'gram_per_satuan' => null,
                'berlaku_mulai' => $this->hari->subDays(20)->toDateString(),
                'catatan' => 'Harga contoh (uji)',
            ]);
        }

        // Rincian biaya dihitung BiayaMenuService dari harga di atas; mode kering hanya menghitung barisnya.
        if (! $this->tulis) {
            $this->hitungLangsung('pengajuan_menus');

            return;
        }

        $hitung = $this->biayaMenu->hitung($menuDiajukan->fresh());

        $this->simpan(PengajuanMenu::class, [
            'menu_id' => $menuDiajukan->getKey(),
            'nomor' => 1,
            'menu_versi' => 1,
            'status' => 'diajukan',
            'rincian' => [
                'menu' => ['nama_menu' => $menuDiajukan->nama_menu, 'kelompok_sasaran' => 'siswa', 'gizi_per_porsi' => []],
                'bahan' => $hitung['bahan'],
                'tanggal_acuan' => $hitung['tanggal_acuan'],
            ],
            'biaya_per_porsi' => $hitung['biaya_per_porsi'],
            'jumlah_porsi' => $hitung['jumlah_porsi'],
            'sumber_porsi' => $hitung['sumber_porsi'],
            'keterangan_asumsi' => $hitung['keterangan_asumsi'],
            'total_biaya' => $hitung['total_biaya'],
            'catatan_pengaju' => 'Contoh pengajuan untuk uji persetujuan investor (uji).',
            'diajukan_pada' => now(),
        ]);
    }

    /** @return array{0: list<Armada>, 1: list<Pengemudi>} */
    private function armadaDanPengemudi(): array
    {
        $armadas = [];

        // Armada 1 bertugas hari ini (distribusi berstatus berangkat), armada 3 dalam servis.
        foreach ([
            ['B 9101 UJI', 'Mobil Boks', 600, StatusArmada::Bertugas],
            ['B 9102 UJI', 'Pickup', 500, StatusArmada::Tersedia],
            ['B 9103 UJI', 'Van', 400, StatusArmada::Servis],
        ] as [$plat, $jenis, $box, $status]) {
            $armadas[] = $this->simpan(Armada::class, ['plat_nomor' => $plat, 'jenis' => $jenis, 'kapasitas_box' => $box, 'status' => $status]);
        }

        $pengemudis = [];

        foreach (['Pengemudi Satu (Uji)', 'Pengemudi Dua (Uji)', 'Pengemudi Tiga (Uji)'] as $i => $nama) {
            $pengemudis[] = $this->simpan(Pengemudi::class, [
                'nama' => $nama,
                'no_hp' => sprintf('0812-0000-700%d', $i + 1),
                'no_sim' => null,
                'sim_berlaku_hingga' => $this->hari->addYear()->toDateString(),
                'is_active' => true,
            ]);
        }

        return [$armadas, $pengemudis];
    }

    /**
     * Jadwal 7 hari (6 hari ke belakang sampai hari ini): produksi, log, mutu, distribusi, bukti, notifikasi perjalanan.
     *
     * @param  list<TitikLayanan>  $titik
     * @param  list<Menu>  $menus
     * @param  list<Armada>  $armadas
     * @param  list<Pengemudi>  $pengemudis
     */
    private function operasional(array $titik, array $menus, array $armadas, array $pengemudis): JadwalProduksi
    {
        $target = array_sum(array_column(self::TITIK, 3));
        $jadwalHariIni = null;

        for ($offset = -6; $offset <= 0; $offset++) {
            $tanggal = $this->hari->addDays($offset);
            $lampau = $offset < 0;

            /** @var JadwalProduksi $jadwal */
            $jadwal = $this->simpan(JadwalProduksi::class, [
                'menu_id' => $menus[abs($offset) % 2]->getKey(),
                'tanggal_produksi' => $tanggal->toDateString(),
                'jam_mulai_masak' => '03:00:00',
                'target_porsi' => $target,
                'status' => StatusJadwalProduksi::Selesai,
                'catatan' => 'Jadwal contoh (uji)',
            ]);

            $this->simpan(LogProduksi::class, [
                'jadwal_produksi_id' => $jadwal->getKey(),
                'kode_batch' => 'UJI-'.$tanggal->format('Ymd'),
                'realisasi_porsi' => $lampau ? $target - 10 : $target,
                'waktu_mulai' => '03:00:00',
                'sisa_makanan_kg' => $lampau ? 3.5 : 2.0,
                'jam_selesai' => $lampau ? '08:15:00' : '07:45:00',
                'kendala' => $offset === -2 ? 'Gas elpiji hampir habis, tabung diganti saat memasak (uji)' : null,
            ]);

            if ($offset === -3) {
                // Pemeriksaan awal tidak layak, lalu diperiksa ulang dan layak (hasil terbaru yang menentukan).
                $this->simpan(PemeriksaanMutu::class, [
                    'jadwal_produksi_id' => $jadwal->getKey(),
                    'waktu_pemeriksaan' => $this->waktu($tanggal, '07:30:00'),
                    'suhu_makanan_c' => 52.0,
                    'kebersihan_dapur' => true,
                    'kebersihan_petugas' => true,
                    'rasa_baik' => true,
                    'aroma_baik' => false,
                    'tampilan_baik' => true,
                    'organoleptik_baik' => false,
                    'catatan_organoleptik' => 'Aroma kurang segar (uji)',
                    'sampel_disimpan' => false,
                    'hasil' => HasilMutu::TidakLayak,
                    'tindakan_korektif' => 'Panaskan ulang lauk dan periksa ulang (uji)',
                ]);
            }

            $this->simpan(PemeriksaanMutu::class, [
                'jadwal_produksi_id' => $jadwal->getKey(),
                'waktu_pemeriksaan' => $this->waktu($tanggal, $lampau ? '08:30:00' : '07:50:00'),
                'suhu_makanan_c' => 68.5,
                'kebersihan_dapur' => true,
                'kebersihan_petugas' => true,
                'rasa_baik' => true,
                'aroma_baik' => true,
                'tampilan_baik' => true,
                'organoleptik_baik' => true,
                'catatan_organoleptik' => 'Rasa, aroma, tampilan baik (uji)',
                'sampel_disimpan' => true,
                'hasil' => HasilMutu::Layak,
            ]);

            foreach (self::TITIK as $i => [, , , $sasaran, , , $jamBerangkat, $jamTiba, $idx, $menitEstimasi]) {
                $statusHariIni = [StatusJadwalDistribusi::Berangkat, StatusJadwalDistribusi::Siap, StatusJadwalDistribusi::Menunggu][$i];

                /** @var JadwalDistribusi $dist */
                $dist = $this->simpan(JadwalDistribusi::class, [
                    'jadwal_produksi_id' => $jadwal->getKey(),
                    'titik_layanan_id' => $titik[$i]->getKey(),
                    'armada_id' => $armadas[$idx]->getKey(),
                    'pengemudi_id' => $pengemudis[$idx]->getKey(),
                    'tanggal_distribusi' => $tanggal->toDateString(),
                    'jam_berangkat' => $jamBerangkat,
                    'jam_rencana_tiba' => $jamTiba,
                    'jumlah_porsi' => $sasaran,
                    'status' => $lampau ? StatusJadwalDistribusi::Diterima : $statusHariIni,
                ]);

                if ($lampau) {
                    $terlambat = $offset === -4 && $i === 2;
                    $selisih = $offset === -2 && $i === 1;
                    $acuan = $this->waktu($tanggal, $jamTiba);

                    $this->simpan(BuktiDistribusi::class, [
                        'jadwal_distribusi_id' => $dist->getKey(),
                        'waktu_berangkat' => $this->waktu($tanggal, $jamBerangkat),
                        'waktu_tiba' => $terlambat ? $acuan->addMinutes(40) : $acuan,
                        'acuan_tiba' => $acuan,
                        'waktu_ambil_foto' => null,
                        'porsi_diterima' => $selisih ? $sasaran - 5 : $sasaran,
                        'nama_pic' => self::TITIK[$i][4],
                        'no_berita_acara' => sprintf('BA-UJI-%s-%d', $tanggal->format('md'), $i + 1),
                        'catatan_selisih' => $selisih ? 'Selisih 5 porsi (data uji)' : null,
                        'terlambat' => $terlambat,
                        'menit_keterlambatan' => $terlambat ? 40 : null,
                    ]);
                }

                if ($i === 0) {
                    $this->notifikasi($dist, $titik[$i], $tanggal, $jamBerangkat, $jamTiba, $menitEstimasi, $lampau);
                }
            }

            if (! $lampau) {
                $jadwalHariIni = $jadwal;
            }
        }

        return $jadwalHariIni;
    }

    /**
     * Notifikasi perjalanan titik pertama tiap hari. PIC tidak terhubung ke Telegram sehingga notifikasi dialihkan
     * ke Kepala SPPG dan berstatus tidak_terkirim (tidak ada pesan sungguhan yang dikirim).
     */
    private function notifikasi(JadwalDistribusi $dist, TitikLayanan $titik, CarbonImmutable $tanggal, string $jamBerangkat, string $jamTiba, int $menit, bool $lampau): void
    {
        $berangkat = $this->waktu($tanggal, $jamBerangkat);
        $estimasi = $this->waktu($tanggal, $jamTiba);

        $this->simpan(NotifikasiPerjalanan::class, [
            'jadwal_distribusi_id' => $dist->getKey(),
            'titik_layanan_id' => $titik->getKey(),
            'dipicu_oleh' => null,
            'penerima_chat_id' => null,
            'dialihkan_ke_kepala' => true,
            'waktu_kirim' => null,
            'estimasi_tiba' => $estimasi,
            'status_kirim' => StatusKirim::TidakTerkirim,
            'pesan' => sprintf('Mobil SPPG berangkat menuju %s, estimasi tiba %s WIB.', $titik->nama, substr($jamTiba, 0, 5)),
            'error' => 'Tidak ada penerima Telegram.',
            'status_perjalanan' => $lampau ? StatusPerjalanan::Tiba : StatusPerjalanan::DalamPerjalanan,
            'waktu_berangkat' => $berangkat,
            'waktu_tiba' => $lampau ? $estimasi : null,
            'estimasi_menit' => $menit,
            'estimasi_sumber' => TripNotificationService::SUMBER_JARAK,
            'created_at' => $berangkat,
            'updated_at' => $berangkat,
        ]);
    }

    /** @param  list<TitikLayanan>  $titik */
    private function keluhan(array $titik, JadwalProduksi $jadwalHariIni): void
    {
        $sekarang = now();

        // Satu kritis (belum ditangani), satu sedang diproses, satu selesai.
        $this->simpan(FeedbackKeluhan::class, [
            'titik_layanan_id' => $titik[0]->getKey(),
            'jadwal_produksi_id' => $jadwalHariIni->getKey(),
            'sumber' => SumberKeluhan::Guru,
            'kategori' => KategoriKeluhan::MakananBasi,
            'prioritas' => PrioritasKeluhan::Kritis,
            'status' => StatusKeluhan::Baru,
            'deskripsi' => 'Lauk ayam dilaporkan berbau kurang segar (data uji)',
            'dieskalasi_pada' => $sekarang,
            'created_at' => $sekarang,
            'updated_at' => $sekarang,
        ]);

        $kemarin = $this->hari->subDay()->setTime(10, 0);
        $this->simpan(FeedbackKeluhan::class, [
            'titik_layanan_id' => $titik[1]->getKey(),
            'sumber' => SumberKeluhan::OrangTua,
            'kategori' => KategoriKeluhan::PorsiKurang,
            'prioritas' => PrioritasKeluhan::Sedang,
            'status' => StatusKeluhan::Diproses,
            'deskripsi' => 'Porsi nasi dirasa kurang untuk anak usia lebih besar (data uji)',
            'tindak_lanjut' => 'Sedang dihitung ulang porsi per kelompok (uji)',
            'created_at' => $kemarin,
            'updated_at' => $kemarin,
        ]);

        $tigaHari = $this->hari->subDays(3)->setTime(11, 0);
        $this->simpan(FeedbackKeluhan::class, [
            'titik_layanan_id' => $titik[2]->getKey(),
            'sumber' => SumberKeluhan::Relawan,
            'kategori' => KategoriKeluhan::Kemasan,
            'prioritas' => PrioritasKeluhan::Rendah,
            'status' => StatusKeluhan::Selesai,
            'deskripsi' => 'Kemasan lauk bocor saat tiba (data uji)',
            'tindak_lanjut' => 'Kemasan diganti jenis tahan bocor (uji)',
            'selesai_pada' => $tigaHari->addDay(),
            'created_at' => $tigaHari,
            'updated_at' => $tigaHari,
        ]);
    }

    /**
     * @param  list<Pemasok>  $pemasoks
     * @param  array<string, BahanBaku>  $bahan
     */
    private function keuangan(array $pemasoks, array $bahan): void
    {
        $awalBulan = $this->hari->startOfMonth();
        // Tanggal pengeluaran tidak boleh jatuh sebelum awal bulan periode anggaran.
        $tanggal = fn (int $hariLalu): string => ($this->hari->subDays($hariLalu)->lessThan($awalBulan) ? $awalBulan : $this->hari->subDays($hariLalu))->toDateString();

        /** @var PencairanDana $pencairan */
        $pencairan = $this->simpan(PencairanDana::class, [
            'periode' => $this->hari->format('Y-m'),
            'sumber_dana' => 'BGN Tahap I (Uji)',
            'nominal' => 100_000_000,
            'tanggal_cair' => $awalBulan->toDateString(),
            'no_referensi' => 'UJI-REF-'.$this->hari->format('Ym'),
            'catatan' => 'Dana contoh untuk uji ambang anggaran (uji)',
        ]);

        // Total disetujui Rp82.560.000 (termasuk pembelian terverifikasi di bawah) = 82,56% dari pencairan.
        $disetujui = [
            [KategoriPengeluaran::BahanBaku, 'Belanja bahan pasar mingguan (uji)', 50_000_000, 5, $pemasoks[1]->getKey()],
            [KategoriPengeluaran::InsentifRelawan, 'Insentif relawan dapur (uji)', 18_000_000, 4, null],
            [KategoriPengeluaran::Energi, 'Gas elpiji dan listrik (uji)', 9_000_000, 3, null],
            [KategoriPengeluaran::Transportasi, 'Bahan bakar armada distribusi (uji)', 5_000_000, 1, null],
        ];

        foreach ($disetujui as [$kategori, $uraian, $nominal, $hariLalu, $pemasokId]) {
            $this->pengeluaran($pencairan, $kategori, $uraian, $nominal, $tanggal($hariLalu), StatusPersetujuanPengeluaran::Disetujui, $pemasokId);
        }

        // Pembelian bahan: satu menunggu verifikasi, satu terverifikasi (beserta pengeluaran disetujui).
        $koperasi = $pemasoks[0];
        $this->pembelian($bahan['Beras'], $koperasi, 50, 12500, $this->hari->toDateString(), StatusPembelianBahan::Diajukan);

        $tanggalTelur = $tanggal(1);
        $telur = $this->pembelian($bahan['Telur'], $koperasi, 20, 28000, $tanggalTelur, StatusPembelianBahan::Terverifikasi);
        $pengeluaranTelur = $this->pengeluaran($pencairan, KategoriPengeluaran::BahanBaku, 'Pembelian Telur 20 kg (uji)', 560_000, $tanggalTelur, StatusPersetujuanPengeluaran::Disetujui, $koperasi->getKey());
        $pengeluaranTelur->forceFill(['pembelian_bahan_id' => $telur->getKey()]);
        $telur->forceFill(['pengeluaran_id' => $pengeluaranTelur->getKey()]);

        if ($this->tulis) {
            $pengeluaranTelur->save();
            $telur->save();
        }

        // Pengeluaran menunggu keputusan investor, dengan riwayat pengajuan. Tanpa foto nota.
        $menunggu = $this->pengeluaran($pencairan, KategoriPengeluaran::Energi, 'Isi ulang gas elpiji (uji)', 750_000, $this->hari->toDateString(), StatusPersetujuanPengeluaran::Diajukan, null);
        $this->simpan(PersetujuanPengeluaran::class, [
            'pengeluaran_id' => $menunggu->getKey(),
            'nomor' => 1,
            'pengeluaran_versi' => 1,
            'status' => StatusPengajuanPengeluaran::Diajukan,
            'kategori' => KategoriPengeluaran::Energi->value,
            'nominal' => 750_000,
            'tanggal' => $this->hari->toDateString(),
            'ambang_persetujuan' => 0,
            'diajukan_pada' => now(),
        ]);
    }

    private function pengeluaran(PencairanDana $pencairan, KategoriPengeluaran $kategori, string $uraian, int $nominal, string $tanggal, StatusPersetujuanPengeluaran $status, ?int $pemasokId): Pengeluaran
    {
        /** @var Pengeluaran */
        return $this->simpan(Pengeluaran::class, [
            'pencairan_dana_id' => $pencairan->getKey(),
            'pemasok_id' => $pemasokId,
            'tanggal' => $tanggal,
            'kategori' => $kategori,
            'uraian' => $uraian,
            'nominal' => $nominal,
            'melebihi_anggaran' => false,
            // Tidak ada berkas nota yang dibuat; bukti nota dapat diunggah penguji lewat Mini App.
            'nota_path' => null,
            'status_persetujuan' => $status,
            'versi' => 1,
        ]);
    }

    private function pembelian(BahanBaku $bahan, Pemasok $pemasok, int $jumlah, int $harga, string $tanggal, StatusPembelianBahan $status): PembelianBahan
    {
        $verifikasi = $status === StatusPembelianBahan::Diajukan;

        /** @var PembelianBahan */
        return $this->simpan(PembelianBahan::class, [
            'bahan_baku_id' => $bahan->getKey(),
            'pemasok_id' => $pemasok->getKey(),
            'tanggal_beli' => $tanggal,
            'jumlah' => $jumlah,
            'satuan' => $bahan->satuan,
            'harga_satuan' => $harga,
            'total' => $jumlah * $harga,
            'catatan' => 'Pembelian contoh (uji)',
            'status' => $status,
            'versi' => 1,
            'diverifikasi_pada' => $verifikasi ? null : now(),
        ]);
    }

    /** Rekap 6 hari ke belakang disusun DailySummaryService dari data di atas (hari ini disusun scheduler 17.00). */
    private function rekapHarian(): void
    {
        if (! $this->tulis) {
            $this->hitungLangsung('rekap_harians', 6);

            return;
        }

        for ($offset = -6; $offset <= -1; $offset++) {
            $rekap = $this->rekap->susun($this->sppgId, $this->hari->addDays($offset)->toDateString());
            $this->hitungLangsung('rekap_harians');
            // Tidak pernah dikirim ke Telegram: jangan tampil sebagai terkirim.
            $rekap->forceFill(['status_kirim' => StatusKirim::TidakTerkirim, 'dikirim_pada' => null])->save();
        }
    }
}
