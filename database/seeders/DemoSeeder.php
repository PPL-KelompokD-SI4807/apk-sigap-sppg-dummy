<?php

namespace Database\Seeders;

use App\Enums\HasilMutu;
use App\Enums\JenisPemasok;
use App\Enums\JenisTitikLayanan;
use App\Enums\KategoriBahan;
use App\Enums\KategoriKeluhan;
use App\Enums\KategoriPengeluaran;
use App\Enums\KelompokSasaran;
use App\Enums\PrioritasKeluhan;
use App\Enums\RelawanUnit;
use App\Enums\Role;
use App\Enums\StatusJadwalDistribusi;
use App\Enums\StatusJadwalProduksi;
use App\Enums\StatusKeluhan;
use App\Enums\StatusKirim;
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
use App\Models\PemeriksaanMutu;
use App\Models\PencairanDana;
use App\Models\PenerimaManfaat;
use App\Models\PengajuanMenu;
use App\Models\Pengeluaran;
use App\Models\Pengemudi;
use App\Models\RekapHarian;
use App\Models\Sppg;
use App\Models\StandarGizi;
use App\Models\TitikLayanan;
use App\Models\User;
use App\Services\Persetujuan\BiayaMenuService;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

/**
 * Data demo fiktif dan koheren untuk 2 SPPG. TIDAK memuat NIK; semua nama berakhiran "(Demo)"
 * dan nomor telepon berpola 0812-0000-xxxx.
 *
 * Idempotent: SPPG yang kodenya sudah ada dilewati (seluruh data satu SPPG dibuat dalam satu transaksi).
 * Hanya untuk local/testing (lihat DatabaseSeeder).
 */
class DemoSeeder extends Seeder
{
    public function run(): void
    {
        $this->adminLintasSppg();

        foreach ($this->profil() as $indeks => $profil) {
            if (Sppg::where('kode', $profil['kode'])->exists()) {
                continue;
            }

            DB::transaction(fn () => $this->buatSppg($indeks + 1, $profil));
        }

        DB::transaction(fn () => $this->investorDemo());
    }

    /**
     * R-06a: investor fiktif. "Investor Satu" memiliki SPPG-DEMO-01 dan -02; "Investor Dua" hanya
     * memiliki SPPG-DEMO-03 (SPPG ringkas tanpa data operasional) untuk uji akses lintas investor.
     * Investor selalu sppg_id null; kepemilikan hanya lewat pivot investor_sppg.
     */
    private function investorDemo(): void
    {
        $ketiga = Sppg::firstOrCreate(['kode' => 'SPPG-DEMO-03'], [
            'nama' => 'SPPG Anggrek Demo',
            'alamat' => 'Jl. Uji Coba No. 3, Kota Contoh',
            'mitra_yayasan' => 'Yayasan Contoh Lestari',
            'telepon' => '0812-0000-0300',
            'kapasitas_porsi_harian' => 1000,
            'status_operasional' => 'aktif',
        ]);

        $milik = [
            [9_000_000_901, 'investor_satu_demo', 'Investor Satu (Demo)', '0812-0000-0901', ['SPPG-DEMO-01', 'SPPG-DEMO-02']],
            [9_000_000_902, 'investor_dua_demo', 'Investor Dua (Demo)', '0812-0000-0902', [$ketiga->kode]],
        ];

        foreach ($milik as [$telegramId, $username, $nama, $telepon, $kode]) {
            $investor = User::withoutGlobalScopes()->where('telegram_id', $telegramId)->first()
                ?? User::factory()->role(Role::Investor)->create([
                    'sppg_id' => null,
                    'telegram_id' => $telegramId,
                    'telegram_username' => $username,
                    'name' => $nama,
                    'phone' => $telepon,
                ]);

            $investor->sppgDimiliki()->syncWithoutDetaching(Sppg::whereIn('kode', $kode)->pluck('id')->all());
        }
    }

    /** @return list<array<string, mixed>> */
    private function profil(): array
    {
        return [
            [
                'kode' => 'SPPG-DEMO-01',
                'nama' => 'SPPG Melati Demo',
                'alamat' => 'Jl. Contoh Raya No. 1, Kota Contoh',
                'yayasan' => 'Yayasan Demo Sejahtera',
                'kapasitas' => 2000,
                'sasaran' => [300, 250, 400, 200],
                'pencairan' => ['nominal' => 100_000_000, 'persen' => 80],
                'mutu_hari_ini' => HasilMutu::Layak,
            ],
            [
                'kode' => 'SPPG-DEMO-02',
                'nama' => 'SPPG Kenanga Demo',
                'alamat' => 'Jl. Percobaan No. 2, Kabupaten Contoh',
                'yayasan' => 'Yayasan Fiktif Mandiri',
                'kapasitas' => 1500,
                'sasaran' => [200, 350, 150, 300],
                'pencairan' => ['nominal' => 50_000_000, 'persen' => 100],
                'mutu_hari_ini' => HasilMutu::TidakLayak,
            ],
        ];
    }

    private function adminLintasSppg(): void
    {
        if (User::withoutGlobalScopes()->where('telegram_id', 9_000_000_001)->exists()) {
            return;
        }

        User::factory()->admin()->create([
            'telegram_id' => 9_000_000_001,
            'telegram_username' => 'admin_demo',
            'name' => 'Admin Pusat (Demo)',
            'phone' => '0812-0000-0001',
        ]);
    }

    /** @param  array<string, mixed>  $p */
    private function buatSppg(int $no, array $p): void
    {
        $sppg = Sppg::create([
            'kode' => $p['kode'],
            'nama' => $p['nama'],
            'alamat' => $p['alamat'],
            'mitra_yayasan' => $p['yayasan'],
            'telepon' => sprintf('0812-0000-%02d00', $no),
            'kapasitas_porsi_harian' => $p['kapasitas'],
            'status_operasional' => 'aktif',
        ]);

        $users = $this->buatPengguna($sppg, $no);
        $titik = $this->buatTitikLayanan($sppg, $p['sasaran'], $users);
        $this->buatPenerima($sppg, $titik);
        $pemasoks = $this->buatPemasok($sppg);
        $bahan = $this->buatBahanDanStok($sppg, $pemasoks, $users['staf_gudang']);
        $this->buatStandarGizi($sppg);
        $menus = $this->buatMenu($sppg, $bahan);
        $this->buatHargaDanPengajuan($sppg, $pemasoks, $bahan, $users);
        [$armadas, $pengemudis] = $this->buatArmada($sppg);
        $this->buatOperasional($sppg, $p, $users, $titik, $menus, $armadas, $pengemudis);
        $this->buatKeuangan($sppg, $p['pencairan'], $users['akuntan'], $pemasoks);
    }

    /**
     * @return array<string, User>
     */
    private function buatPengguna(Sppg $sppg, int $no): array
    {
        $dasar = 9_000_000_000 + $no * 100;
        $definisi = [
            'admin' => ['Admin SPPG', Role::Admin, null],
            'kepala_sppg' => ['Kepala SPPG', Role::KepalaSppg, null],
            'ahli_gizi' => ['Ahli Gizi', Role::AhliGizi, null],
            'akuntan' => ['Akuntan', Role::Akuntan, null],
            'staf_gudang' => ['Staf Gudang', Role::StafGudang, null],
            'relawan_dapur' => ['Relawan Dapur', Role::Relawan, RelawanUnit::Dapur],
            'relawan_distribusi' => ['Relawan Distribusi', Role::Relawan, RelawanUnit::Distribusi],
        ];

        $users = [];
        $urut = 1;
        foreach ($definisi as $kunci => [$label, $role, $unit]) {
            $users[$kunci] = User::factory()->role($role, $unit)->create([
                'sppg_id' => $sppg->id,
                'telegram_id' => $dasar + $urut,
                'telegram_username' => "demo_{$kunci}_{$no}",
                'name' => "{$label} {$no} (Demo)",
                'phone' => sprintf('0812-0000-%d%02d', $no, $urut),
            ]);
            $urut++;
        }

        return $users;
    }

    /**
     * @param  list<int>  $sasaran
     * @param  array<string, User>  $users
     * @return list<TitikLayanan>
     */
    private function buatTitikLayanan(Sppg $sppg, array $sasaran, array $users): array
    {
        $daftar = [
            ['SDN Contoh 1', JenisTitikLayanan::Sekolah, 1.5],
            ['SDN Contoh 2', JenisTitikLayanan::Sekolah, 3.2],
            ['PAUD Tunas Demo', JenisTitikLayanan::Paud, 4.0],
            ['Posyandu Melati Demo', JenisTitikLayanan::Posyandu, 2.4],
        ];

        $titik = [];
        foreach ($daftar as $i => [$nama, $jenis, $jarak]) {
            $titik[] = TitikLayanan::factory()->create([
                'sppg_id' => $sppg->id,
                'nama' => $nama,
                'jenis' => $jenis,
                'alamat' => 'Jl. Demo No. '.($i + 1).', Kota Contoh',
                'jarak_km' => $jarak,
                'jumlah_sasaran' => $sasaran[$i],
                'pic_nama' => 'PIC '.($i + 1).' (Demo)',
                'pic_telepon' => sprintf('0812-0000-9%03d', ($sppg->id * 10) + $i),
                // PIC pertama sudah menghubungkan Telegram; sisanya belum (uji pengalihan ke Kepala SPPG).
                'pic_telegram_chat_id' => $i === 0 ? 9_100_000_000 + $sppg->id : null,
            ]);
        }

        return $titik;
    }

    /** @param  list<TitikLayanan>  $titik */
    private function buatPenerima(Sppg $sppg, array $titik): void
    {
        foreach ($titik as $t) {
            $kelompok = match ($t->jenis) {
                JenisTitikLayanan::Paud => KelompokSasaran::Balita,
                JenisTitikLayanan::Posyandu => KelompokSasaran::IbuHamil,
                default => KelompokSasaran::Siswa,
            };

            PenerimaManfaat::factory()->count(5)->create([
                'sppg_id' => $sppg->id,
                'titik_layanan_id' => $t->id,
                'kelompok_sasaran' => $kelompok,
            ]);
        }
    }

    /** @return list<Pemasok> */
    private function buatPemasok(Sppg $sppg): array
    {
        $daftar = [
            ['Kelompok Tani Subur (Demo)', JenisPemasok::Petani, 'Sayur dan buah'],
            ['Peternakan Ayam Makmur (Demo)', JenisPemasok::Peternak, 'Ayam dan telur'],
            ['Nelayan Bahari (Demo)', JenisPemasok::Nelayan, 'Ikan'],
            ['UMKM Tahu Tempe Sari (Demo)', JenisPemasok::Umkm, 'Tahu dan tempe'],
            ['Koperasi Desa Sejahtera (Demo)', JenisPemasok::Koperasi, 'Beras dan minyak goreng'],
        ];

        return array_map(fn ($d, $i) => Pemasok::factory()->create([
            'sppg_id' => $sppg->id,
            'nama' => $d[0],
            'jenis' => $d[1],
            'jenis_bahan' => $d[2],
            'kontak' => sprintf('0812-0000-8%03d', ($sppg->id * 10) + $i),
        ]), $daftar, array_keys($daftar));
    }

    /**
     * @param  list<Pemasok>  $pemasoks
     * @return array<string, BahanBaku>
     */
    private function buatBahanDanStok(Sppg $sppg, array $pemasoks, User $stafGudang): array
    {
        // nama => [kategori, satuan, masuk, keluar, minimum, indeks pemasok, hari kedaluwarsa]
        $daftar = [
            'Beras' => [KategoriBahan::Karbohidrat, 'kg', 600, 120, 100, 0, 180],
            'Ayam' => [KategoriBahan::ProteinHewani, 'kg', 150, 60, 40, 1, 20],
            'Telur' => [KategoriBahan::ProteinHewani, 'kg', 80, 30, 20, 1, 12],
            'Ikan Lele' => [KategoriBahan::ProteinHewani, 'kg', 100, 85, 30, 2, 2],
            'Tahu' => [KategoriBahan::ProteinNabati, 'kg', 60, 20, 15, 3, 3],
            'Tempe' => [KategoriBahan::ProteinNabati, 'kg', 60, 20, 15, 3, 4],
            'Bayam' => [KategoriBahan::Sayur, 'kg', 50, 15, 10, 0, 5],
            'Wortel' => [KategoriBahan::Sayur, 'kg', 70, 20, 10, 0, 14],
            'Pisang' => [KategoriBahan::Buah, 'kg', 90, 30, 20, 0, 6],
            'Minyak Goreng' => [KategoriBahan::Lainnya, 'liter', 80, 70, 25, 1, 120],
        ];

        $bahan = [];
        foreach ($daftar as $nama => [$kategori, $satuan, $masuk, $keluar, $min, $idx, $hari]) {
            $b = BahanBaku::factory()->create([
                'sppg_id' => $sppg->id,
                'pemasok_id' => $pemasoks[$idx]->id,
                'nama' => $nama,
                'kode' => 'BB-'.$sppg->id.'-'.strtoupper(substr(md5($nama), 0, 4)),
                'kategori' => $kategori,
                'satuan' => $satuan,
                'stok' => 0,
                'stok_minimum' => $min,
                'kedaluwarsa_terdekat' => now()->addDays($hari)->toDateString(),
            ]);

            MutasiStok::factory()->masuk()->create([
                'sppg_id' => $sppg->id,
                'bahan_baku_id' => $b->id,
                'pemasok_id' => $pemasoks[$idx]->id,
                'dicatat_oleh' => $stafGudang->id,
                'jumlah' => $masuk,
                'tanggal_mutasi' => now()->subDays(5)->toDateString(),
                'tanggal_kedaluwarsa' => now()->addDays($hari)->toDateString(),
            ]);
            MutasiStok::factory()->keluar()->create([
                'sppg_id' => $sppg->id,
                'bahan_baku_id' => $b->id,
                'dicatat_oleh' => $stafGudang->id,
                'jumlah' => $keluar,
                'tanggal_mutasi' => now()->subDays(2)->toDateString(),
                'catatan' => 'Pemakaian dapur',
            ]);

            // Stok = saldo mutasi (masuk - keluar), sesuai aturan StockService.
            $b->forceFill(['stok' => $masuk - $keluar])->save();
            $bahan[$nama] = $b;
        }

        return $bahan;
    }

    private function buatStandarGizi(Sppg $sppg): void
    {
        $rentang = [
            [KelompokSasaran::Siswa, [550, 700], [18, 28], [15, 25], [80, 100], [6, 12]],
            [KelompokSasaran::Balita, [300, 450], [8, 15], [10, 18], [45, 65], [3, 7]],
            [KelompokSasaran::IbuHamil, [600, 800], [22, 32], [18, 28], [85, 110], [8, 14]],
        ];

        foreach ($rentang as [$kelompok, $e, $p, $l, $k, $s]) {
            StandarGizi::factory()->create([
                'sppg_id' => $sppg->id,
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
     * @param  array<string, BahanBaku>  $bahan
     * @return list<Menu>
     */
    private function buatMenu(Sppg $sppg, array $bahan): array
    {
        // [nama, gizi (energi, protein, lemak, karbo, serat), komposisi nama bahan => gram/porsi]
        $daftar = [
            ['Nasi, Ayam Goreng, Tumis Bayam, Pisang', [640, 24, 19, 92, 9], ['Beras' => 90, 'Ayam' => 60, 'Bayam' => 40, 'Pisang' => 50, 'Minyak Goreng' => 8]],
            ['Nasi, Ikan Lele Goreng, Sup Wortel, Tempe', [610, 26, 18, 88, 8], ['Beras' => 90, 'Ikan Lele' => 60, 'Wortel' => 45, 'Tempe' => 30, 'Minyak Goreng' => 8]],
            ['Nasi, Telur Balado, Capcay, Tahu', [600, 22, 20, 90, 10], ['Beras' => 90, 'Telur' => 55, 'Wortel' => 30, 'Bayam' => 30, 'Tahu' => 40]],
            ['Nasi, Ayam Kecap, Orek Tempe, Pisang', [650, 25, 20, 94, 8], ['Beras' => 90, 'Ayam' => 60, 'Tempe' => 35, 'Pisang' => 50]],
            // Sengaja di luar rentang standar (energi rendah) untuk menampilkan peringatan gizi.
            ['Nasi, Tahu Kukus, Lalapan', [480, 14, 9, 80, 5], ['Beras' => 80, 'Tahu' => 50, 'Bayam' => 30]],
        ];

        $menus = [];
        foreach ($daftar as $i => [$nama, $gizi, $komposisi]) {
            $menu = Menu::factory()->gizi(...$gizi)->create([
                'sppg_id' => $sppg->id,
                'nama_menu' => $nama,
                'kelompok_sasaran' => KelompokSasaran::Siswa,
                'tanggal_sajian' => now()->addDays($i - 3)->toDateString(),
                'siklus' => 'Siklus 1',
            ]);

            foreach ($komposisi as $namaBahan => $gram) {
                $menu->bahanBakus()->attach($bahan[$namaBahan]->id, ['gram_per_porsi' => $gram]);
            }
            $menus[] = $menu;
        }

        return $menus;
    }

    /**
     * R-06b-1: riwayat harga pemasok (termasuk koperasi) dan satu menu draf yang diajukan ke investor.
     * Pemasok utama bahan diutamakan; koperasi menyediakan harga beras dan minyak (lebih murah, entri terbaru).
     *
     * @param  list<Pemasok>  $pemasoks
     * @param  array<string, BahanBaku>  $bahan
     * @param  array<string, User>  $users
     */
    private function buatHargaDanPengajuan(Sppg $sppg, array $pemasoks, array $bahan, array $users): void
    {
        $harga = [
            'Beras' => 13000, 'Ayam' => 38000, 'Telur' => 28000, 'Ikan Lele' => 32000, 'Tahu' => 12000,
            'Tempe' => 14000, 'Bayam' => 9000, 'Wortel' => 11000, 'Pisang' => 15000, 'Minyak Goreng' => 18000,
        ];

        foreach ($bahan as $nama => $b) {
            HargaPemasok::factory()->create([
                'sppg_id' => $sppg->id,
                'pemasok_id' => $b->pemasok_id,
                'bahan_baku_id' => $b->id,
                'harga' => $harga[$nama],
                'berlaku_mulai' => now()->subDays(20)->toDateString(),
                'dicatat_oleh' => $users['staf_gudang']->id,
            ]);
        }

        $koperasi = end($pemasoks);
        foreach (['Beras' => 12500, 'Minyak Goreng' => 17500] as $nama => $nilai) {
            HargaPemasok::factory()->create([
                'sppg_id' => $sppg->id,
                'pemasok_id' => $koperasi->id,
                'bahan_baku_id' => $bahan[$nama]->id,
                'harga' => $nilai,
                'berlaku_mulai' => now()->subDays(3)->toDateString(),
                'catatan' => 'Harga koperasi (contoh)',
                'dicatat_oleh' => $users['staf_gudang']->id,
            ]);
        }

        $menu = Menu::factory()->gizi(630, 23, 18, 91, 8)->diajukan()->create([
            'sppg_id' => $sppg->id,
            'nama_menu' => 'Nasi, Ayam Panggang, Sup Wortel (Diajukan)',
            'kelompok_sasaran' => KelompokSasaran::Siswa,
            'siklus' => 'Siklus 2',
        ]);
        foreach (['Beras' => 90, 'Ayam' => 60, 'Wortel' => 45, 'Minyak Goreng' => 6] as $nama => $gram) {
            $menu->bahanBakus()->attach($bahan[$nama]->id, ['gram_per_porsi' => $gram]);
        }

        $hitung = app(BiayaMenuService::class)->hitung($menu->fresh());
        PengajuanMenu::factory()->create([
            'sppg_id' => $sppg->id,
            'menu_id' => $menu->id,
            'menu_versi' => $menu->versi,
            'rincian' => ['menu' => ['nama_menu' => $menu->nama_menu, 'kelompok_sasaran' => 'siswa', 'gizi_per_porsi' => []], 'bahan' => $hitung['bahan'], 'tanggal_acuan' => $hitung['tanggal_acuan']],
            'biaya_per_porsi' => $hitung['biaya_per_porsi'],
            'jumlah_porsi' => $hitung['jumlah_porsi'],
            'sumber_porsi' => $hitung['sumber_porsi'],
            'keterangan_asumsi' => $hitung['keterangan_asumsi'],
            'total_biaya' => $hitung['total_biaya'],
            'catatan_pengaju' => 'Contoh pengajuan untuk demo persetujuan investor.',
            'diajukan_oleh' => $users['ahli_gizi']->id,
        ]);
    }

    /** @return array{0: list<Armada>, 1: list<Pengemudi>} */
    private function buatArmada(Sppg $sppg): array
    {
        $armadas = [
            Armada::factory()->create(['sppg_id' => $sppg->id, 'plat_nomor' => "B 1{$sppg->id}01 DMO", 'jenis' => 'Mobil Boks', 'kapasitas_box' => 800]),
            Armada::factory()->create(['sppg_id' => $sppg->id, 'plat_nomor' => "B 1{$sppg->id}02 DMO", 'jenis' => 'Pickup', 'kapasitas_box' => 500]),
            Armada::factory()->servis()->create(['sppg_id' => $sppg->id, 'plat_nomor' => "B 1{$sppg->id}03 DMO", 'jenis' => 'Van', 'kapasitas_box' => 400]),
        ];

        $pengemudis = [];
        foreach (['Pengemudi Satu', 'Pengemudi Dua', 'Pengemudi Tiga'] as $i => $nama) {
            $pengemudis[] = Pengemudi::factory()->create([
                'sppg_id' => $sppg->id,
                'nama' => "{$nama} (Demo)",
                'no_hp' => sprintf('0812-0000-7%03d', ($sppg->id * 10) + $i),
            ]);
        }

        return [$armadas, $pengemudis];
    }

    /**
     * Jadwal 7 hari (3 hari lalu s.d. 3 hari ke depan) lengkap dengan log, mutu, distribusi, bukti, dan keluhan.
     *
     * @param  array<string, mixed>  $p
     * @param  array<string, User>  $users
     * @param  list<TitikLayanan>  $titik
     * @param  list<Menu>  $menus
     * @param  list<Armada>  $armadas
     * @param  list<Pengemudi>  $pengemudis
     */
    private function buatOperasional(Sppg $sppg, array $p, array $users, array $titik, array $menus, array $armadas, array $pengemudis): void
    {
        $totalSasaran = array_sum($p['sasaran']);
        $hariIni = now()->startOfDay();
        $jadwalHariIni = null;
        $distribusiKemarin = null;

        for ($offset = -3; $offset <= 3; $offset++) {
            $tanggal = $hariIni->copy()->addDays($offset);
            $lampau = $offset < 0;
            $hariIniFlag = $offset === 0;

            $jadwal = JadwalProduksi::factory()->create([
                'sppg_id' => $sppg->id,
                'menu_id' => $menus[($offset + 3) % 4]->id,
                'dibuat_oleh' => $users['kepala_sppg']->id,
                'tanggal_produksi' => $tanggal->toDateString(),
                'jam_mulai_masak' => '03:00:00',
                'target_porsi' => $totalSasaran,
                'status' => $lampau ? StatusJadwalProduksi::Selesai : ($hariIniFlag ? StatusJadwalProduksi::Dimasak : StatusJadwalProduksi::Direncanakan),
            ]);

            $hasilMutu = null;
            if ($lampau || $hariIniFlag) {
                LogProduksi::factory()->create([
                    'sppg_id' => $sppg->id,
                    'jadwal_produksi_id' => $jadwal->id,
                    'dicatat_oleh' => $users['relawan_dapur']->id,
                    'realisasi_porsi' => $lampau ? $totalSasaran - 10 : $totalSasaran,
                    'sisa_makanan_kg' => $lampau ? 3.5 : 0,
                    'jam_selesai' => $lampau ? '08:15:00' : null,
                    'kendala' => $lampau && $offset === -2 ? 'Gas elpiji hampir habis, ganti tabung saat memasak' : null,
                ]);

                $hasilMutu = $hariIniFlag ? $p['mutu_hari_ini'] : HasilMutu::Layak;
                PemeriksaanMutu::factory()->create([
                    'sppg_id' => $sppg->id,
                    'jadwal_produksi_id' => $jadwal->id,
                    'pemeriksa_id' => $users['ahli_gizi']->id,
                    'waktu_pemeriksaan' => $tanggal->copy()->setTime(8, 30),
                    'hasil' => $hasilMutu,
                    ...($hasilMutu === HasilMutu::TidakLayak ? [
                        'suhu_makanan_c' => 45.0,
                        'aroma_baik' => false,
                        'organoleptik_baik' => false,
                        'catatan_organoleptik' => 'Aroma kurang segar (data demo)',
                        'tindakan_korektif' => 'Masak ulang lauk dan periksa ulang',
                    ] : []),
                ]);
            }

            foreach ($titik as $i => $t) {
                $berangkat = $lampau || ($hariIniFlag && $hasilMutu === HasilMutu::Layak);
                $status = match (true) {
                    $lampau => StatusJadwalDistribusi::Diterima,
                    $hariIniFlag && $hasilMutu === HasilMutu::Layak => StatusJadwalDistribusi::Siap,
                    default => StatusJadwalDistribusi::Menunggu,
                };

                $dist = JadwalDistribusi::factory()->create([
                    'sppg_id' => $sppg->id,
                    'jadwal_produksi_id' => $jadwal->id,
                    'titik_layanan_id' => $t->id,
                    'armada_id' => $armadas[$i % 2]->id,
                    'pengemudi_id' => $pengemudis[$i % 2]->id,
                    'petugas_id' => $users['relawan_distribusi']->id,
                    'tanggal_distribusi' => $tanggal->toDateString(),
                    'jam_berangkat' => '09:00:00',
                    'jam_rencana_tiba' => sprintf('09:%02d:00', 20 + $i * 10),
                    'jumlah_porsi' => $p['sasaran'][$i],
                    'status' => $status,
                ]);

                if ($lampau) {
                    $terlambat = $offset === -3 && $i === 3;
                    BuktiDistribusi::factory()->create([
                        'sppg_id' => $sppg->id,
                        'jadwal_distribusi_id' => $dist->id,
                        'dicatat_oleh' => $users['relawan_distribusi']->id,
                        'waktu_berangkat' => $tanggal->copy()->setTime(9, 0),
                        'waktu_tiba' => $tanggal->copy()->setTime(9, 20 + $i * 10)->addMinutes($terlambat ? 70 : 0),
                        'waktu_ambil_foto' => $tanggal->copy()->setTime(9, 25 + $i * 10),
                        'porsi_diterima' => $p['sasaran'][$i],
                        'nama_pic' => $t->pic_nama,
                        'no_berita_acara' => sprintf('BA-%d-%s-%d', $sppg->id, $tanggal->format('md'), $i + 1),
                        'terlambat' => $terlambat,
                        'menit_keterlambatan' => $terlambat ? 70 : null,
                    ]);
                }

                if ($berangkat && ($i < 2)) {
                    $this->buatNotifikasi($sppg, $dist, $t, $users['relawan_distribusi'], $tanggal, $i);
                }

                if ($offset === -1 && $i === 0) {
                    $distribusiKemarin = $dist;
                }
            }

            if ($hariIniFlag) {
                $jadwalHariIni = $jadwal;
            }

            if ($lampau) {
                RekapHarian::factory()->create([
                    'sppg_id' => $sppg->id,
                    'tanggal' => $tanggal->toDateString(),
                    'dikirim_pada' => $tanggal->copy()->setTime(17, 0),
                    'status_kirim' => StatusKirim::Terkirim,
                    'ringkasan' => [
                        'target_porsi' => $totalSasaran,
                        'realisasi_porsi' => $totalSasaran - 10,
                        'mutu' => 'layak',
                        'distribusi_selesai' => count($titik),
                        'distribusi_total' => count($titik),
                        'keluhan_terbuka' => 0,
                        'serapan_anggaran_persen' => $p['pencairan']['persen'],
                        'sisa_makanan_kg' => 3.5,
                    ],
                ]);
            }
        }

        $this->buatKeluhan($sppg, $users, $titik, $jadwalHariIni, $distribusiKemarin?->jadwalProduksi);
    }

    private function buatNotifikasi(Sppg $sppg, JadwalDistribusi $dist, TitikLayanan $titik, User $pemicu, $tanggal, int $i): void
    {
        $terkirim = $titik->picTerhubung();

        NotifikasiPerjalanan::factory()->create([
            'sppg_id' => $sppg->id,
            'jadwal_distribusi_id' => $dist->id,
            'titik_layanan_id' => $titik->id,
            'dipicu_oleh' => $pemicu->id,
            'penerima_chat_id' => $terkirim ? $titik->pic_telegram_chat_id : null,
            'dialihkan_ke_kepala' => ! $terkirim,
            'waktu_kirim' => $terkirim ? $tanggal->copy()->setTime(9, 0) : null,
            'estimasi_tiba' => $tanggal->copy()->setTime(9, 20 + $i * 10),
            'status_kirim' => $terkirim ? StatusKirim::Terkirim : StatusKirim::TidakTerkirim,
            'pesan' => "Mobil SPPG berangkat menuju {$titik->nama}, estimasi tiba 09:".(20 + $i * 10).' WIB.',
        ]);
    }

    /**
     * @param  array<string, User>  $users
     * @param  list<TitikLayanan>  $titik
     */
    private function buatKeluhan(Sppg $sppg, array $users, array $titik, ?JadwalProduksi $hariIni, ?JadwalProduksi $kemarin): void
    {
        FeedbackKeluhan::factory()->kritis()->create([
            'sppg_id' => $sppg->id,
            'titik_layanan_id' => $titik[0]->id,
            'jadwal_produksi_id' => $hariIni?->id,
            'dicatat_oleh' => $users['admin']->id,
            'sumber' => SumberKeluhan::Guru,
            'status' => StatusKeluhan::Baru,
        ]);

        FeedbackKeluhan::factory()->create([
            'sppg_id' => $sppg->id,
            'titik_layanan_id' => $titik[1]->id,
            'jadwal_produksi_id' => $kemarin?->id,
            'dicatat_oleh' => $users['relawan_distribusi']->id,
            'ditangani_oleh' => $users['kepala_sppg']->id,
            'sumber' => SumberKeluhan::OrangTua,
            'kategori' => KategoriKeluhan::PorsiKurang,
            'prioritas' => PrioritasKeluhan::Sedang,
            'status' => StatusKeluhan::Diproses,
            'deskripsi' => 'Porsi nasi dirasa kurang untuk siswa kelas 6',
            'tindak_lanjut' => 'Sedang dihitung ulang porsi per kelas',
        ]);

        FeedbackKeluhan::factory()->selesai()->create([
            'sppg_id' => $sppg->id,
            'titik_layanan_id' => $titik[2]->id,
            'dicatat_oleh' => $users['admin']->id,
            'ditangani_oleh' => $users['kepala_sppg']->id,
            'sumber' => SumberKeluhan::Relawan,
            'kategori' => KategoriKeluhan::Kemasan,
            'prioritas' => PrioritasKeluhan::Rendah,
            'deskripsi' => 'Kemasan lauk bocor saat tiba',
        ]);
    }

    /**
     * @param  array{nominal: int, persen: int}  $cfg
     * @param  list<Pemasok>  $pemasoks
     */
    private function buatKeuangan(Sppg $sppg, array $cfg, User $akuntan, array $pemasoks): void
    {
        $pencairan = PencairanDana::factory()->create([
            'sppg_id' => $sppg->id,
            'dicatat_oleh' => $akuntan->id,
            'periode' => now()->format('Y-m'),
            'sumber_dana' => 'BGN Tahap I (Demo)',
            'nominal' => $cfg['nominal'],
            'tanggal_cair' => now()->startOfMonth()->toDateString(),
            'no_referensi' => 'DEMO-REF-'.$sppg->id.'-'.now()->format('Ym'),
        ]);

        $terpakai = intdiv($cfg['nominal'] * $cfg['persen'], 100);
        $bagi = [
            [KategoriPengeluaran::BahanBaku, 60, 'Belanja bahan pasar (demo)'],
            [KategoriPengeluaran::InsentifRelawan, 20, 'Insentif relawan dapur (demo)'],
            [KategoriPengeluaran::Energi, 12, 'Gas elpiji dan listrik (demo)'],
        ];

        $sisa = $terpakai;
        foreach ($bagi as $n => [$kategori, $persen, $uraian]) {
            $nominal = intdiv($terpakai * $persen, 100);
            $sisa -= $nominal;
            Pengeluaran::factory()->create([
                'sppg_id' => $sppg->id,
                'pencairan_dana_id' => $pencairan->id,
                'pemasok_id' => $kategori === KategoriPengeluaran::BahanBaku ? $pemasoks[0]->id : null,
                'dicatat_oleh' => $akuntan->id,
                'tanggal' => now()->subDays($n + 1)->toDateString(),
                'kategori' => $kategori,
                'uraian' => $uraian,
                'nominal' => $nominal,
            ]);
        }

        // Sisa pembulatan masuk kategori transportasi sehingga total tepat sesuai persentase.
        Pengeluaran::factory()->create([
            'sppg_id' => $sppg->id,
            'pencairan_dana_id' => $pencairan->id,
            'dicatat_oleh' => $akuntan->id,
            'tanggal' => now()->toDateString(),
            'kategori' => KategoriPengeluaran::Transportasi,
            'uraian' => 'Bahan bakar armada distribusi (demo)',
            'nominal' => $sisa,
        ]);
    }
}
