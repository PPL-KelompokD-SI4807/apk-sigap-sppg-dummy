<?php

namespace Tests\Feature\Dashboard;

use App\Enums\RelawanUnit;
use App\Enums\Role;
use App\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Tests\Feature\Investor\InvestorTestCase;

/**
 * Dasar tes dashboard R-07. Waktu dibekukan ke 2026-11-02 10:00 WIB (Senin). Fixture dimasukkan langsung ke
 * basis data (tanpa pengguna login) dengan data fiktif. SPPG A dan B punya angka berbeda agar kebocoran
 * lintas SPPG terdeteksi; SPPG C milik investor lain.
 */
abstract class DashboardTestCase extends InvestorTestCase
{
    protected const URL = '/api/v1/dashboard';

    protected const HARI = '2026-11-02';

    protected const KEMARIN = '2026-11-01';

    protected const BULAN = '2026-11';

    protected User $relawanDistribusiA;

    /** @var array<string, int> */
    protected array $a = [];

    /** @var array<string, int> */
    protected array $b = [];

    protected function setUp(): void
    {
        parent::setUp();

        Carbon::setTestNow(Carbon::parse(self::HARI.' 10:00:00', 'Asia/Jakarta'));
        $this->relawanDistribusiA = $this->userOf(Role::Relawan, $this->sppgA, RelawanUnit::Distribusi);
        $this->a = $this->isiSppgA();
        $this->b = $this->isiSppgB();
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    /** Majukan jam 61 detik agar cache dashboard (TTL 60 detik) kedaluwarsa. */
    protected function lewatiCache(): void
    {
        Carbon::setTestNow(now()->addSeconds(61));
    }

    /** @param array<string, mixed> $row */
    protected function ins(string $tabel, array $row): int
    {
        $waktu = now()->toDateTimeString();
        $kolom = DB::getSchemaBuilder()->getColumnListing($tabel);

        foreach (['created_at', 'updated_at'] as $k) {
            if (in_array($k, $kolom, true)) {
                $row += [$k => $waktu];
            }
        }

        return (int) DB::table($tabel)->insertGetId($row);
    }

    protected function menu(int $sppg, string $nama, string $status = 'disetujui', string $kelompok = 'siswa', array $ekstra = []): int
    {
        return $this->ins('menus', $ekstra + [
            'sppg_id' => $sppg, 'nama_menu' => $nama, 'kelompok_sasaran' => $kelompok, 'tanggal_sajian' => self::HARI,
            'energi_kkal' => 500, 'protein_g' => 20, 'lemak_g' => 15, 'karbohidrat_g' => 70, 'serat_g' => 5,
            'status_persetujuan' => $status, 'versi' => 1,
        ]);
    }

    protected function jadwal(int $sppg, int $menu, int $target, string $status = 'direncanakan', string $tanggal = self::HARI): int
    {
        return $this->ins('jadwal_produksis', [
            'sppg_id' => $sppg, 'menu_id' => $menu, 'tanggal_produksi' => $tanggal, 'jam_mulai_masak' => '03:00:00',
            'target_porsi' => $target, 'status' => $status,
        ]);
    }

    protected function log(int $sppg, int $jadwal, int $realisasi, float $sisa = 0): int
    {
        return $this->ins('log_produksis', ['sppg_id' => $sppg, 'jadwal_produksi_id' => $jadwal, 'realisasi_porsi' => $realisasi, 'sisa_makanan_kg' => $sisa, 'jam_selesai' => '07:30:00']);
    }

    protected function titik(int $sppg, string $nama): int
    {
        return $this->ins('titik_layanans', ['sppg_id' => $sppg, 'nama' => $nama, 'jenis' => 'sekolah', 'alamat' => 'Jl. Fiktif 1', 'pic_nama' => 'PIC Fiktif Rahasia', 'pic_telepon' => '081200000000', 'is_active' => true]);
    }

    protected function distribusi(int $sppg, int $jadwal, int $titik, int $porsi, string $status, string $tanggal = self::HARI): int
    {
        return $this->ins('jadwal_distribusis', [
            'sppg_id' => $sppg, 'jadwal_produksi_id' => $jadwal, 'titik_layanan_id' => $titik, 'tanggal_distribusi' => $tanggal,
            'jam_berangkat' => '07:00:00', 'jam_rencana_tiba' => '08:00:00', 'jumlah_porsi' => $porsi, 'status' => $status,
        ]);
    }

    protected function bukti(int $sppg, int $distribusi, int $diterima, bool $terlambat = false): int
    {
        return $this->ins('bukti_distribusis', [
            'sppg_id' => $sppg, 'jadwal_distribusi_id' => $distribusi, 'waktu_tiba' => self::HARI.' 08:00:00',
            'porsi_diterima' => $diterima, 'nama_pic' => 'PIC Fiktif Rahasia', 'terlambat' => $terlambat,
        ]);
    }

    protected function mutu(int $sppg, int $jadwal, string $hasil, string $tanggal = self::HARI): int
    {
        return $this->ins('pemeriksaan_mutus', ['sppg_id' => $sppg, 'jadwal_produksi_id' => $jadwal, 'waktu_pemeriksaan' => $tanggal.' 06:00:00', 'hasil' => $hasil]);
    }

    protected function bahan(int $sppg, string $nama, float $stok, float $min, ?string $kedaluwarsa = null, string $kategori = 'karbohidrat'): int
    {
        return $this->ins('bahan_bakus', ['sppg_id' => $sppg, 'nama' => $nama, 'kategori' => $kategori, 'satuan' => 'kg', 'stok' => $stok, 'stok_minimum' => $min, 'kedaluwarsa_terdekat' => $kedaluwarsa]);
    }

    protected function pencairan(int $sppg, int $nominal, string $periode = self::BULAN, string $tanggal = self::HARI): int
    {
        return $this->ins('pencairan_danas', ['sppg_id' => $sppg, 'periode' => $periode, 'sumber_dana' => 'BGN Fiktif', 'nominal' => $nominal, 'tanggal_cair' => $tanggal]);
    }

    protected function pengeluaran(int $sppg, int $nominal, string $status = 'disetujui', string $kategori = 'bahan_baku', ?string $nota = null, string $tanggal = self::HARI): int
    {
        return $this->ins('pengeluarans', [
            'sppg_id' => $sppg, 'tanggal' => $tanggal, 'kategori' => $kategori, 'uraian' => 'Uraian fiktif', 'nominal' => $nominal,
            'nota_path' => $nota, 'status_persetujuan' => $status, 'versi' => 1,
        ]);
    }

    protected function keluhan(int $sppg, string $prioritas, string $status = 'baru'): int
    {
        return $this->ins('feedback_keluhans', [
            'sppg_id' => $sppg, 'sumber' => 'guru', 'kategori' => 'rasa', 'prioritas' => $prioritas, 'status' => $status,
            'deskripsi' => 'Deskripsi keluhan fiktif RAHASIA-ANAK',
        ]);
    }

    protected function penerima(int $sppg, int $titik, string $kelompok, int $jumlah, ?string $alergi = null): void
    {
        for ($i = 0; $i < $jumlah; $i++) {
            $this->ins('penerima_manfaats', [
                'sppg_id' => $sppg, 'titik_layanan_id' => $titik, 'nama' => 'Anak Fiktif '.$sppg.'-'.$kelompok.'-'.$i,
                'kelompok_sasaran' => $kelompok, 'catatan_alergi' => $alergi, 'is_active' => true,
            ]);
        }
    }

    protected function pengajuanMenu(int $sppg, int $menu, string $status = 'diajukan', float $biaya = 12500, int $nomor = 1): int
    {
        return $this->ins('pengajuan_menus', [
            'sppg_id' => $sppg, 'menu_id' => $menu, 'nomor' => $nomor, 'menu_versi' => 1, 'status' => $status, 'rincian' => '[]',
            'biaya_per_porsi' => $biaya, 'jumlah_porsi' => 500, 'sumber_porsi' => 'jadwal_produksi', 'keterangan_asumsi' => 'fiktif',
            'total_biaya' => $biaya * 500, 'diajukan_pada' => now()->toDateTimeString(),
        ]);
    }

    /** @return array<string, int> */
    private function isiSppgA(): array
    {
        $s = $this->sppgA;
        $m1 = $this->menu($s, 'Nasi Ayam Fiktif', 'disetujui');
        $m2 = $this->menu($s, 'Bubur Kacang Fiktif', 'draf', 'balita', ['energi_kkal' => 900]);
        $this->ins('standar_gizis', ['sppg_id' => $s, 'kelompok_sasaran' => 'siswa', 'energi_min' => 400, 'energi_max' => 600, 'protein_min' => 15, 'protein_max' => 30, 'lemak_min' => 10, 'lemak_max' => 25, 'karbohidrat_min' => 50, 'karbohidrat_max' => 90, 'serat_min' => 3, 'serat_max' => 10]);

        $j1 = $this->jadwal($s, $m1, 500, 'selesai');
        $j2 = $this->jadwal($s, $m2, 300, 'dimasak');
        $this->jadwal($s, $m1, 100, 'dibatalkan');
        $j0 = $this->jadwal($s, $m1, 200, 'selesai', self::KEMARIN);
        $this->log($s, $j1, 480, 1.5);
        $this->log($s, $j0, 190);

        $t1 = $this->titik($s, 'SDN Fiktif Satu');
        $t2 = $this->titik($s, 'Posyandu Fiktif Dua');
        $d1 = $this->distribusi($s, $j1, $t1, 300, 'diterima');
        $this->distribusi($s, $j1, $t2, 200, 'berangkat');
        $this->distribusi($s, $j2, $t1, 100, 'menunggu');
        $this->bukti($s, $d1, 290, true);

        $this->mutu($s, $j1, 'layak');
        $this->mutu($s, $j0, 'tidak_layak', self::KEMARIN);

        $this->bahan($s, 'Beras Fiktif', 5, 10, '2026-11-03');
        $this->bahan($s, 'Telur Fiktif', 50, 10, '2026-11-01', 'protein_hewani');
        $this->bahan($s, 'Minyak Fiktif', 100, 10);

        $this->pencairan($s, 10_000_000);
        $this->pengeluaran($s, 4_000_000, 'disetujui', 'bahan_baku', 'uploads/1/keuangan-pengeluaran/nota-a.jpg');
        $this->pengeluaran($s, 1_000_000, 'disetujui', 'energi');
        $this->pengeluaran($s, 500_000, 'diajukan', 'transportasi');
        $this->pengeluaran($s, 300_000, 'ditolak', 'lainnya');

        $this->keluhan($s, 'kritis', 'baru');
        $this->keluhan($s, 'sedang', 'diproses');
        $this->keluhan($s, 'rendah', 'selesai');

        $this->pengajuanMenu($s, $m1);

        $this->penerima($s, $t1, 'siswa', 6, 'Alergi kacang fiktif');
        $this->penerima($s, $t1, 'siswa', 4);
        $this->penerima($s, $t2, 'balita', 3, 'Alergi susu fiktif');

        $pemasok = $this->ins('pemasoks', ['sppg_id' => $s, 'nama' => 'Koperasi Fiktif', 'jenis' => 'koperasi']);
        $b1 = (int) DB::table('bahan_bakus')->where('sppg_id', $s)->value('id');
        $this->ins('mutasi_stoks', ['sppg_id' => $s, 'bahan_baku_id' => $b1, 'pemasok_id' => $pemasok, 'jenis' => 'masuk', 'jumlah' => 10, 'tanggal_mutasi' => self::HARI]);
        $this->ins('mutasi_stoks', ['sppg_id' => $s, 'bahan_baku_id' => $b1, 'pemasok_id' => $pemasok, 'jenis' => 'masuk', 'jumlah' => 5, 'tanggal_mutasi' => self::HARI]);
        $this->ins('mutasi_stoks', ['sppg_id' => $s, 'bahan_baku_id' => $b1, 'jenis' => 'keluar', 'jumlah' => 3, 'tanggal_mutasi' => self::HARI]);

        $this->ins('rekap_harians', ['sppg_id' => $s, 'tanggal' => self::HARI, 'ringkasan' => '{}', 'status_kirim' => 'menunggu']);
        $this->ins('armadas', ['sppg_id' => $s, 'plat_nomor' => 'B 1234 FIK', 'jenis' => 'Pickup', 'kapasitas_box' => 40, 'status' => 'bertugas']);
        $this->ins('armadas', ['sppg_id' => $s, 'plat_nomor' => 'B 5678 FIK', 'jenis' => 'Pickup', 'kapasitas_box' => 40, 'status' => 'tersedia']);
        $this->ins('pengemudis', ['sppg_id' => $s, 'nama' => 'Pengemudi Fiktif', 'no_hp' => '081200000001', 'no_sim' => 'SIM-RAHASIA', 'is_active' => true]);

        return compact('m1', 'm2', 'j1', 'j2', 'j0', 't1', 't2', 'd1');
    }

    /** SPPG B: angka sengaja berbeda (target 1000, pencairan 20 jt, 2 keluhan kritis) agar kebocoran terlihat. */
    private function isiSppgB(): array
    {
        $s = $this->sppgB;
        $m = $this->menu($s, 'Menu B Fiktif');
        $j = $this->jadwal($s, $m, 1000, 'selesai');
        $this->log($s, $j, 900);
        $t = $this->titik($s, 'SDN Fiktif B');
        $d = $this->distribusi($s, $j, $t, 1000, 'diterima');
        $this->bukti($s, $d, 950);
        $this->mutu($s, $j, 'tidak_layak');
        $this->bahan($s, 'Gula B Fiktif', 1, 5, '2026-11-02');
        $this->bahan($s, 'Garam B Fiktif', 1, 5);
        $this->pencairan($s, 20_000_000);
        $this->pengeluaran($s, 18_000_000, 'disetujui', 'bahan_baku');
        $this->pengeluaran($s, 700_000, 'diajukan', 'peralatan');
        $this->keluhan($s, 'kritis');
        $this->keluhan($s, 'kritis');
        $this->pengajuanMenu($s, $m);
        $this->pengajuanMenu($s, $m, 'diajukan', 9000, 2);

        return compact('m', 'j', 't', 'd');
    }
}
