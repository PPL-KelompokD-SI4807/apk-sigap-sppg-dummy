<?php

namespace Tests\Feature\LaporanBgn;

use App\Enums\Role;
use App\Models\AuditLog;
use Illuminate\Support\Facades\DB;

/** Agregasi benar untuk 30a-30d, rekonsiliasi antar laporan, saldo awal, penomoran LPA, audit. Data fiktif. */
class LaporanBgnDataTest extends LaporanBgnTestCase
{
    private function sebagaiKepala(): static
    {
        return $this->as($this->kepalaA);
    }

    private function sebagaiAkuntan(): static
    {
        return $this->as($this->akuntanA);
    }

    // ------------------------------------------------------------------ 30b

    public function test_30b_harian_rincian_belanja_dan_sasaran(): void
    {
        $res = $this->sebagaiAkuntan()->laporan('harian', ['tanggal' => '2026-11-02'])->assertOk();

        $res->assertJsonPath('data.lampiran', '30b')
            ->assertJsonPath('data.sppg.nama', 'SPPG A')
            ->assertJsonPath('data.sppg.kecamatan', 'Kec. Fiktif')
            ->assertJsonPath('data.rincian_pembelanjaan.bahan_baku_pangan.total', 170_000)
            ->assertJsonPath('data.rincian_pembelanjaan.bahan_baku_pangan.bahan_baku_lainnya', 50_000)
            ->assertJsonPath('data.rincian_pembelanjaan.bahan_baku_pangan.rincian.0.nama', 'Beras Fiktif')
            ->assertJsonPath('data.rincian_pembelanjaan.bahan_baku_pangan.rincian.0.total', 120_000)
            ->assertJsonPath('data.rincian_pembelanjaan.biaya_operasional.total', 650_000)
            ->assertJsonPath('data.rincian_pembelanjaan.insentif_fasilitas.jumlah', 6_000_000)
            ->assertJsonPath('data.rincian_pembelanjaan.insentif_fasilitas.acuan', 6_000_000)
            // diajukan (999.999) dan SPPG B tidak ikut; tidak ada hitung ganda bahan baku.
            ->assertJsonPath('data.rincian_pembelanjaan.total_belanja', 6_820_000);

        $ops = collect($res->json('data.rincian_pembelanjaan.biaya_operasional.baris'))->keyBy('sub_kategori');
        $this->assertCount(17, $ops);
        $this->assertSame(300_000, $ops['insentif_relawan']['biaya_per_hari']);
        $this->assertSame(150_000, $ops['listrik']['biaya_per_hari']);
        // Q-5: data lama energi tanpa sub_kategori -> Lain-lain dengan keterangan kategori lama (tanpa tebakan).
        $this->assertSame(200_000, $ops['lain_lain']['biaya_per_hari']);
        $this->assertStringContainsString('Energi', (string) $ops['lain_lain']['keterangan']);
        $this->assertSame(0, $ops['gas']['biaya_per_hari']);

        $pd = collect($res->json('data.sasaran_penerima_manfaat.peserta_didik.baris'))->keyBy('baris');
        $this->assertSame(['laki_laki' => 6, 'perempuan' => 4, 'jumlah' => 10], collect($pd['sd_1_3'])->only(['laki_laki', 'perempuan', 'jumlah'])->all());
        $this->assertSame('SDN Fiktif 1', $pd['sd_1_3']['rincian'][0]['nama']);
        $this->assertSame(20, $pd['smp']['jumlah']);
        $this->assertSame(0, $pd['sd_4_6']['jumlah'], 'penerima nonaktif tidak dihitung');
        $this->assertSame(5, $pd['pendidik']['jumlah']);
        // 2 siswa tanpa jenjang tidak ditebak.
        $res->assertJsonPath('data.sasaran_penerima_manfaat.belum_terklasifikasi', 2);
        $this->assertSame(35, $res->json('data.sasaran_penerima_manfaat.peserta_didik.total.jumlah'));
        $this->assertSame(6, $res->json('data.sasaran_penerima_manfaat.non_peserta_didik.total.jumlah'));
        $this->assertContains('penerima_belum_terklasifikasi', array_column($res->json('data.peringatan'), 'kode'));
    }

    public function test_30b_hari_tanpa_transaksi_bernilai_nol(): void
    {
        $this->sebagaiAkuntan()->laporan('harian', ['tanggal' => '2026-11-04'])->assertOk()
            ->assertJsonPath('data.rincian_pembelanjaan.total_belanja', 0)
            ->assertJsonPath('data.rincian_pembelanjaan.insentif_fasilitas.acuan', 0)
            ->assertJsonPath('data.rincian_pembelanjaan.bahan_baku_pangan.rincian', []);
    }

    // ------------------------------------------------------------------ 30c

    public function test_30c_rincian_keuangan_dan_saldo(): void
    {
        $res = $this->sebagaiKepala()->laporan('dua-mingguan', ['tanggal_awal' => '2026-11-01', 'tanggal_akhir' => '2026-11-15'])->assertOk();

        $res->assertJsonPath('data.lampiran', '30c')
            ->assertJsonPath('data.rincian_keuangan.saldo_awal', 10_000_000)
            ->assertJsonPath('data.rincian_keuangan.dana_pemasukan_total', 50_000_000)
            ->assertJsonPath('data.rincian_keuangan.realisasi_anggaran.bahan_baku', 250_000)
            ->assertJsonPath('data.rincian_keuangan.realisasi_anggaran.operasional', 650_000)
            ->assertJsonPath('data.rincian_keuangan.realisasi_anggaran.insentif_fasilitas', 6_000_000)
            ->assertJsonPath('data.rincian_keuangan.realisasi_anggaran.total', 6_900_000)
            ->assertJsonPath('data.rincian_keuangan.sisa_anggaran_total', 53_100_000)
            ->assertJsonPath('data.insentif_fasilitas_acuan.hari_operasional', 2)
            ->assertJsonPath('data.insentif_fasilitas_acuan.nilai_acuan', 12_000_000)
            ->assertJsonPath('data.keterangan.no_rekening_va', '8800000000001')
            ->assertJsonPath('data.pelapor.nama', 'Ketua Fiktif')
            ->assertJsonPath('data.pelapor.yayasan', 'Yayasan Fiktif Sejahtera');
        $this->assertStringContainsString('Rp53.100.000', $res->json('data.keterangan.sisa_dana_dialihkan'));
    }

    public function test_rekonsiliasi_30c_sama_dengan_jumlah_30b_harian(): void
    {
        $this->sebagaiKepala();
        $c = $this->laporan('dua-mingguan', ['tanggal_awal' => '2026-11-01', 'tanggal_akhir' => '2026-11-15'])->assertOk();

        $jumlah = 0;
        for ($d = 1; $d <= 15; $d++) {
            $jumlah += $this->laporan('harian', ['tanggal' => sprintf('2026-11-%02d', $d)])->assertOk()
                ->json('data.rincian_pembelanjaan.total_belanja');
        }

        $this->assertSame($jumlah, $c->json('data.rincian_keuangan.realisasi_anggaran.total'));
        $this->assertSame(6_900_000, $jumlah);
    }

    // ------------------------------------------------------------------ 30d

    public function test_30d_a_sama_dengan_30d_b_dan_saldo_berantai(): void
    {
        $this->sebagaiKepala();
        $a = $this->laporan('bulanan/ringkasan', ['bulan' => '2026-11'])->assertOk();
        $b = $this->laporan('bulanan/anggaran', ['bulan' => '2026-11'])->assertOk();

        $a->assertJsonPath('data.periode.awal', '2026-11-01')->assertJsonPath('data.periode.akhir', '2026-11-30')
            ->assertJsonPath('data.rincian_keuangan.sisa_anggaran_total', 53_100_000);

        // 30d-A = 30d-B.
        $this->assertSame($a->json('data.rincian_keuangan.sisa_anggaran_total'), $b->json('data.total.saldo_akhir'));
        $this->assertSame($a->json('data.rincian_keuangan.dana_pemasukan_total'), $b->json('data.total.dana_masuk'));
        $this->assertSame($a->json('data.rincian_keuangan.realisasi_anggaran.total'), $b->json('data.total.dana_keluar'));
        $this->assertSame($a->json('data.nomor'), $b->json('data.nomor'), 'A dan B satu laporan bulanan, satu nomor');

        // Urutan baris juknis: saldo awal, penerimaan, "Pembelanjaan:", tiga subkelompok.
        $tipe = array_column($b->json('data.baris'), 'tipe');
        $this->assertSame(['saldo_awal', 'penerimaan', 'judul', 'subkelompok'], array_slice($tipe, 0, 4));
        $judul = array_values(array_column(array_filter($b->json('data.baris'), fn ($r) => $r['tipe'] === 'subkelompok'), 'perihal'));
        $this->assertSame(['Bahan Baku Pangan', 'Biaya Operasional', 'Insentif Fasilitas SPPG'], $judul);

        // Saldo berjalan tidak melompat: tiap baris = saldo sebelumnya + masuk - keluar.
        $saldo = null;
        foreach ($b->json('data.baris') as $r) {
            if ($r['saldo'] === null) {
                continue;
            }
            $saldo = $saldo === null ? $r['saldo'] : $saldo + ($r['dana_masuk'] ?? 0) - ($r['dana_keluar'] ?? 0);
            $this->assertSame($saldo, $r['saldo']);
        }

        // Periode berikutnya: saldo awal otomatis = saldo akhir sebelumnya (bukan saldo awal manual).
        $this->laporan('bulanan/ringkasan', ['bulan' => '2026-12'])->assertOk()
            ->assertJsonPath('data.rincian_keuangan.saldo_awal', 53_100_000)
            ->assertJsonPath('data.rincian_keuangan.sisa_anggaran_total', 53_100_000)
            ->assertJsonPath('data.peringatan', fn ($p) => ! in_array('saldo_awal_belum_diisi', array_column($p, 'kode'), true));
    }

    public function test_30d_c_porsi_per_hari_dan_total_sama_dengan_realisasi(): void
    {
        $res = $this->sebagaiKepala()->laporan('bulanan/penerima-manfaat', ['bulan' => '2026-11'])->assertOk();

        $hari = collect($res->json('data.hari'));
        $this->assertCount(30, $hari);
        $this->assertSame('Senin', $hari->firstWhere('tanggal', '2026-11-02')['hari']);
        $this->assertSame('tidak_ada_produksi', $hari->firstWhere('tanggal', '2026-11-04')['ket']);

        $d2 = $hari->firstWhere('tanggal', '2026-11-02');
        $this->assertSame(43, $d2['total']);
        $perBaris = collect($d2['peserta_didik'])->merge($d2['non_peserta_didik'])->pluck('porsi', 'baris');
        $this->assertSame(10, $perBaris['sd_1_3']);
        $this->assertSame(20, $perBaris['smp']);
        $this->assertSame(5, $perBaris['pendidik']);
        $this->assertSame(3, $perBaris['ibu_hamil']);
        $this->assertSame(3, $perBaris['balita']);
        $this->assertSame(2, $d2['belum_terklasifikasi']);
        // Jumlah semua kolom = porsi harian (rekonsiliasi dengan realisasi).
        $this->assertSame($d2['total'], $perBaris->sum() + $d2['belum_terklasifikasi']);

        $res->assertJsonPath('data.total_bulan.total', 129)
            ->assertJsonPath('data.hari.2.peserta_didik.0.baris', 'paud_tk');
        $this->assertSame(20, collect($hari->firstWhere('tanggal', '2026-11-03')['peserta_didik'])->firstWhere('baris', 'sd_1_3')['porsi'] * 1);
        $this->assertCount(9, $res->json('data.kolom.peserta_didik'));
        $this->assertCount(3, $res->json('data.kolom.non_peserta_didik'));
    }

    // ------------------------------------------------------------------ 30a

    public function test_30a_estimasi_porsi_biaya_dan_hari_ke_6(): void
    {
        $this->produksi('2026-11-07', 43); // Sabtu: hari operasional ke-6
        $res = $this->sebagaiKepala()->laporan('porsi', ['tanggal_awal' => '2026-11-02', 'tanggal_akhir' => '2026-11-13'])->assertOk();

        $res->assertJsonPath('data.lampiran', '30a')->assertJsonPath('data.penyamaran.diterapkan', false)
            ->assertJsonPath('data.nomor', null);
        $tabel = $res->json('data.tabel');
        $this->assertCount(2, $tabel);
        $this->assertCount(6, $tabel[0]['hari'], '5 hari kerja + Sabtu berproduksi');
        $this->assertCount(5, $tabel[1]['hari']);

        $baris = collect($tabel[0]['baris'])->keyBy('kelompok');
        $this->assertCount(16, $baris, '15 kelompok juknis + belum terklasifikasi');
        // 2 Nov: SD 1-3 = 10 porsi x Rp8.000; SMP = 20 x Rp10.000; Food Sample 3 x Rp10.000.
        $this->assertSame(['tanggal' => '2026-11-02', 'porsi' => 10, 'biaya' => 80_000], $baris['sd_1_3']['per_hari'][0]);
        $this->assertSame(['tanggal' => '2026-11-02', 'porsi' => 20, 'biaya' => 200_000], $baris['smp']['per_hari'][0]);
        $this->assertSame(8_000, $baris['sd_1_3']['anggaran_per_porsi']);
        $this->assertSame(8_000, $baris['anak_balita']['anggaran_per_porsi']);
        $this->assertSame(10_000, $baris['ibu_hamil']['anggaran_per_porsi']);
        $this->assertSame(3, $baris['food_sample']['per_hari'][0]['porsi']);
        $this->assertFalse($baris['food_sample']['estimasi']);
        $this->assertTrue($baris['smp']['estimasi']);
        $this->assertSame(5, $baris['pendidik_tenaga_kependidikan']['per_hari'][0]['porsi']);
        $this->assertSame(2, $baris['belum_terklasifikasi']['per_hari'][0]['porsi']);

        // Total kolom per hari = realisasi + sampel; total pekan = jumlah baris.
        $this->assertSame(48, $tabel[0]['total']['per_hari'][0]['porsi']);
        $this->assertSame(array_sum(array_column($tabel[0]['baris'], 'total_porsi')), $tabel[0]['total']['total_porsi']);
        $this->assertSame(array_sum(array_column($tabel[0]['baris'], 'total_biaya')), $tabel[0]['total']['total_biaya']);
        // Hari tanpa produksi tetap tampil bernilai 0.
        $this->assertSame(0, $tabel[0]['total']['per_hari'][2]['porsi']);
    }

    public function test_30a_acuan_anggaran_per_sppg_dapat_diubah(): void
    {
        DB::table('sppgs')->where('id', $this->sppgA)->update(['acuan_bahan_baku_kecil' => 7_500, 'acuan_bahan_baku_lainnya' => 11_000]);

        $res = $this->sebagaiKepala()->laporan('porsi', ['tanggal_awal' => '2026-11-02', 'tanggal_akhir' => '2026-11-06'])->assertOk();
        $baris = collect($res->json('data.tabel.0.baris'))->keyBy('kelompok');

        $this->assertSame(75_000, $baris['sd_1_3']['per_hari'][0]['biaya']);
        $this->assertSame(220_000, $baris['smp']['per_hari'][0]['biaya']);
    }

    // ------------------------------------------------------------------ saldo awal

    public function test_saldo_awal_kosong_memberi_peringatan_dan_memakai_mutasi_sebelumnya(): void
    {
        DB::table('sppgs')->where('id', $this->sppgA)->update(['saldo_awal' => null, 'saldo_awal_tanggal' => null]);

        $res = $this->sebagaiKepala()->laporan('dua-mingguan', ['tanggal_awal' => '2026-11-01', 'tanggal_akhir' => '2026-11-15'])->assertOk();

        $this->assertContains('saldo_awal_belum_diisi', array_column($res->json('data.peringatan'), 'kode'));
        // Mutasi sebelum 1 Nov: pencairan 20.000.000 - pengeluaran 500.000.
        $res->assertJsonPath('data.rincian_keuangan.saldo_awal', 19_500_000);
    }

    public function test_periode_sebelum_tanggal_saldo_awal_tidak_memakai_saldo_awal(): void
    {
        $res = $this->sebagaiKepala()->laporan('bulanan/ringkasan', ['bulan' => '2026-10'])->assertOk();

        $this->assertContains('periode_sebelum_saldo_awal', array_column($res->json('data.peringatan'), 'kode'));
        $res->assertJsonPath('data.rincian_keuangan.saldo_awal', 0)
            ->assertJsonPath('data.rincian_keuangan.dana_pemasukan_total', 20_000_000)
            ->assertJsonPath('data.rincian_keuangan.realisasi_anggaran.total', 500_000);
    }

    // ------------------------------------------------------------------ penomoran LPA

    public function test_nomor_lpa_idempoten_berurutan_per_sppg_dan_per_tahun(): void
    {
        $this->sebagaiAkuntan();
        $q1 = ['tanggal_awal' => '2026-11-01', 'tanggal_akhir' => '2026-11-15'];
        $q2 = ['tanggal_awal' => '2026-11-16', 'tanggal_akhir' => '2026-11-30'];

        $this->laporan('dua-mingguan', $q1)->assertOk()->assertJsonPath('data.nomor', '01/LPA/XI/2026');
        // Cetak ulang periode yang sama = nomor sama.
        $this->laporan('dua-mingguan', $q1)->assertOk()->assertJsonPath('data.nomor', '01/LPA/XI/2026');
        $this->laporan('dua-mingguan', $q2)->assertOk()->assertJsonPath('data.nomor', '02/LPA/XI/2026');
        $this->laporan('harian', ['tanggal' => '2026-11-02'])->assertOk()->assertJsonPath('data.nomor', '03/LPA/XI/2026');
        $this->laporan('bulanan/ringkasan', ['bulan' => '2026-11'])->assertOk()->assertJsonPath('data.nomor', '04/LPA/XI/2026');
        $this->laporan('bulanan/anggaran', ['bulan' => '2026-11'])->assertOk()->assertJsonPath('data.nomor', '04/LPA/XI/2026');
        // 30a dan 30d-C tidak bernomor menurut juknis dan tidak menghabiskan urutan.
        $this->laporan('porsi', $q1)->assertOk()->assertJsonPath('data.nomor', null);
        $this->laporan('bulanan/penerima-manfaat', ['bulan' => '2026-11'])->assertOk()->assertJsonPath('data.nomor', null);
        // Tahun baru: urutan mulai lagi, bulan Romawi mengikuti akhir periode.
        $this->laporan('dua-mingguan', ['tanggal_awal' => '2027-01-01', 'tanggal_akhir' => '2027-01-15'])->assertOk()
            ->assertJsonPath('data.nomor', '01/LPA/I/2027');

        // SPPG lain punya urutan sendiri.
        $this->as($this->kepalaB)->laporan('dua-mingguan', $q1)->assertOk()->assertJsonPath('data.nomor', '01/LPA/XI/2026');

        $this->forgetActor();
        $this->assertSame(1, DB::table('laporan_bgns')->where('sppg_id', $this->sppgA)->where('jenis', 'dua_mingguan')->where('periode_awal', '2026-11-01')->count());
    }

    public function test_investor_hanya_membaca_nomor_dan_tidak_menghabiskan_urutan(): void
    {
        $q = ['tanggal_awal' => '2026-12-01', 'tanggal_akhir' => '2026-12-15'];

        $this->sebagai($this->investorAB, $this->sppgA)->laporan('dua-mingguan', $q)->assertOk()
            ->assertJsonPath('data.nomor', null);

        // Pengguna berhak menerbitkan sesudahnya mendapat urutan pertama (bukan urutan kedua).
        $this->sebagai($this->kepalaA)->laporan('dua-mingguan', $q)->assertOk()->assertJsonPath('data.nomor', '01/LPA/XII/2026');

        // Setelah terbit, investor melihat nomor yang sama.
        $this->sebagai($this->investorAB, $this->sppgA)->laporan('dua-mingguan', $q)->assertOk()
            ->assertJsonPath('data.nomor', '01/LPA/XII/2026');
    }

    // ------------------------------------------------------------------ penandatangan

    public function test_blok_tanda_tangan_dari_pengguna_sppg_dan_pilihan_bila_lebih_dari_satu(): void
    {
        $res = $this->sebagaiKepala()->laporan('harian', ['tanggal' => '2026-11-02'])->assertOk();
        $res->assertJsonPath('data.penandatangan.pihak_pertama.nama', 'Ketua Fiktif')
            ->assertJsonPath('data.penandatangan.pihak_kedua.nama', $this->akuntanA->name)
            ->assertJsonPath('data.penandatangan.mengetahui.nama', $this->kepalaA->name)
            ->assertJsonPath('data.penandatangan.kota', 'Kab. Fiktif');

        // Akuntan kedua: nama dikosongkan sampai dipilih; pilihan harus akuntan aktif SPPG itu.
        $this->forgetActor();
        $kedua = $this->userOf(Role::Akuntan, $this->sppgA);
        $akuntanB = $this->userOf(Role::Akuntan, $this->sppgB);
        $this->as($this->kepalaA);
        $tanpa = $this->laporan('harian', ['tanggal' => '2026-11-02'])->assertOk();
        $tanpa->assertJsonPath('data.penandatangan.pihak_kedua.nama', null);
        $this->assertCount(2, $tanpa->json('data.penandatangan.pihak_kedua.kandidat'));
        $this->laporan('harian', ['tanggal' => '2026-11-02', 'akuntan_id' => $kedua->id])->assertOk()
            ->assertJsonPath('data.penandatangan.pihak_kedua.nama', $kedua->name);
        // Akuntan SPPG B tidak boleh dipilih.
        $this->laporan('harian', ['tanggal' => '2026-11-02', 'akuntan_id' => $akuntanB->id])->assertStatus(422)->assertJsonValidationErrors('akuntan_id');
    }

    // ------------------------------------------------------------------ audit

    public function test_setiap_akses_tercatat_di_audit_log_tanpa_isi_data(): void
    {
        $this->sebagaiAkuntan()->laporan('harian', ['tanggal' => '2026-11-02'])->assertOk();
        $this->forgetActor();

        $log = AuditLog::query()->withoutGlobalScopes()->where('event', 'laporan_bgn_diakses')->sole();
        $this->assertSame($this->sppgA, (int) $log->sppg_id);
        $this->assertSame($this->akuntanA->id, (int) $log->user_id);
        $this->assertSame('harian', $log->new_values['jenis']);
        $this->assertSame('2026-11-02', $log->new_values['periode_awal']);
        $this->assertStringNotContainsString('Beras', json_encode($log->new_values));
    }

    // ------------------------------------------------------------------ validasi

    public function test_validasi_parameter(): void
    {
        $this->sebagaiKepala();
        $this->laporan('harian')->assertStatus(422)->assertJsonValidationErrors('tanggal');
        $this->laporan('harian', ['tanggal' => '02-11-2026'])->assertStatus(422)->assertJsonValidationErrors('tanggal');
        $this->laporan('dua-mingguan', ['tanggal_awal' => '2026-11-10', 'tanggal_akhir' => '2026-11-01'])->assertStatus(422)->assertJsonValidationErrors('tanggal_akhir');
        $this->laporan('dua-mingguan', ['tanggal_awal' => '2026-11-01', 'tanggal_akhir' => '2026-11-17'])->assertStatus(422)->assertJsonValidationErrors('tanggal_akhir');
        $this->laporan('porsi', ['tanggal_awal' => '2026-11-01', 'tanggal_akhir' => '2026-11-16'])->assertOk();
        $this->laporan('bulanan/anggaran', ['bulan' => '2026-13'])->assertStatus(422)->assertJsonValidationErrors('bulan');
        $this->laporan('bulanan/ringkasan', ['bulan' => '2026-11-01'])->assertStatus(422)->assertJsonValidationErrors('bulan');
    }
}
