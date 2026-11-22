<?php

namespace Tests\Feature\Dashboard;

use App\Services\Dashboard\RingkasanDashboard;
use Tests\TestCase;

/** Penyaji ringkasan dashboard (bersama tool Agen AI dan Bot): widget null dilewati, batas 8 baris, teks aman. */
class RingkasanDashboardTest extends TestCase
{
    public function test_widget_null_dan_profil_tak_dikenal_menghasilkan_daftar_kosong(): void
    {
        $this->assertSame([], RingkasanDashboard::dari(['profil' => 'relawan_tanpa_unit', 'widgets' => []]));
        $this->assertSame([], RingkasanDashboard::dari(['profil' => 'tidak_dikenal', 'widgets' => ['x' => ['a' => 1]]]));
        $this->assertSame([], RingkasanDashboard::dari(['profil' => 'kepala_sppg', 'widgets' => ['anggaran' => null, 'stok_kritis' => null]]));
        $this->assertSame([], RingkasanDashboard::dari([]));
    }

    public function test_hanya_baris_dari_widget_yang_ada_dan_tak_lebih_dari_delapan(): void
    {
        $baris = RingkasanDashboard::dari(['profil' => 'akuntan', 'widgets' => [
            'bukti_nota' => ['pengeluaran_dihitung' => 3, 'bukti_belum_ada' => ['jumlah' => 2, 'nominal' => 1500000], 'persen_bukti_belum_ada' => 66.67],
            'pencairan_vs_pengeluaran' => null,
        ]]);

        $this->assertSame([['kunci' => 'bukti_nota', 'label' => 'Pengeluaran tanpa bukti nota', 'nilai' => '2 dari 3 (66,7%)']], $baris);
    }

    public function test_sel_disamarkan_tidak_menampilkan_angka_dan_status_anggaran_diterjemahkan(): void
    {
        $gizi = RingkasanDashboard::dari(['profil' => 'ahli_gizi', 'widgets' => ['kebutuhan_khusus' => ['total' => null, 'per_kelompok_sasaran' => [['jumlah' => null, 'penanda' => '<5']]]]]);
        $this->assertSame('disamarkan (kelompok kecil)', $gizi[0]['nilai']);

        $investor = RingkasanDashboard::dari(['profil' => 'investor', 'widgets' => ['anggaran' => [
            'anggaran' => 100, 'terpakai' => 120, 'persen' => 120, 'status' => 'melampaui', 'penanda' => ['ambang_peringatan_persen' => 80],
        ]]]);
        $this->assertSame('Rp 120 dari Rp 100 (120,0%), MELAMPAUI anggaran', $investor[0]['nilai']);
    }

    public function test_nama_sppg_dibersihkan_dari_kontrol_arobase_dan_dipotong(): void
    {
        $bersih = RingkasanDashboard::bersihkan("A\nB\u{200B}C\t@admin ".str_repeat('z', 100));

        $this->assertStringNotContainsString("\n", $bersih);
        $this->assertStringNotContainsString('@', $bersih);
        $this->assertStringNotContainsString("\u{200B}", $bersih);
        $this->assertLessThanOrEqual(61, mb_strlen($bersih));
    }

    public function test_daftar_tidak_lengkap_tidak_dijumlahkan(): void
    {
        $penuh = RingkasanDashboard::dari(['profil' => 'relawan_dapur', 'widgets' => ['status_log_produksi' => ['jumlah_jadwal' => 2, 'sudah_ada_log' => 1, 'belum_ada_log' => 1, 'items' => [['realisasi_porsi' => 480], ['realisasi_porsi' => null]]]]]);
        $this->assertContains('Porsi tercatat di log', array_column($penuh, 'label'));

        $sebagian = RingkasanDashboard::dari(['profil' => 'relawan_dapur', 'widgets' => ['status_log_produksi' => ['jumlah_jadwal' => 60, 'sudah_ada_log' => 1, 'belum_ada_log' => 59, 'items' => [['realisasi_porsi' => 480]]]]]);
        $this->assertNotContains('Porsi tercatat di log', array_column($sebagian, 'label'));
    }
}
