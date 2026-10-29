<?php

namespace Tests\Feature\Dashboard;

use App\Models\User;
use Illuminate\Support\Facades\DB;

/** Isi widget per peran (angka dihitung tangan dari fixture DashboardTestCase). */
class DashboardIsiTest extends DashboardTestCase
{
    /** @return array<string, mixed> */
    private function widgets(User $user, string $query = '', int|string|null $header = null): array
    {
        return $this->sebagai($user, $header)->getJson(self::URL.$query)->assertOk()->json('data.widgets');
    }

    public function test_kepala_sppg(): void
    {
        $w = $this->widgets($this->kepalaA);

        $this->assertSame(3, $w['jadwal_produksi_hari_ini']['total']);
        $this->assertSame(2, $w['jadwal_produksi_hari_ini']['aktif']);
        $this->assertSame(800, $w['jadwal_produksi_hari_ini']['target_porsi']);
        $perStatus = array_column($w['jadwal_produksi_hari_ini']['per_status'], 'jumlah', 'status');
        $this->assertEquals(['direncanakan' => 0, 'dimasak' => 1, 'selesai' => 1, 'dibatalkan' => 1], $perStatus);

        $porsi = $w['porsi_target_vs_realisasi'];
        $this->assertSame(800, $porsi['target']);
        $this->assertSame(480, $porsi['realisasi']);
        $this->assertEquals(60.0, $porsi['capaian_persen']);
        $this->assertSame(1, $porsi['jadwal_dengan_log']);
        $this->assertSame(1, $porsi['jadwal_tanpa_log']);
        $this->assertSame(1.5, $porsi['sisa_makanan_kg']);
        $this->assertSame(500, $porsi['didistribusikan']);
        $this->assertSame(290, $porsi['diterima']);

        $titik = $w['distribusi_per_titik'];
        $this->assertSame(['Posyandu Fiktif Dua', 'SDN Fiktif Satu'], $titik['labels']);
        $this->assertSame([200, 400], $titik['series']['porsi_rencana']);
        $this->assertSame([0, 290], $titik['series']['porsi_diterima']);
        $this->assertSame(1, $titik['items'][1]['terlambat']);
        $this->assertSame(1, $titik['items'][1]['jadwal_selesai']);

        $mutu = $w['mutu'];
        $this->assertSame(['pemeriksaan' => 1, 'layak' => 1, 'tidak_layak' => 0, 'batch_belum_diperiksa' => 1], $mutu['hari_ini']);
        $this->assertSame(2, $mutu['bulan_berjalan']['pemeriksaan']);
        $this->assertSame(1, $mutu['bulan_berjalan']['tidak_layak']);
        $this->assertEquals(50.0, $mutu['persen_layak_bulan']);

        $keluhan = $w['keluhan_terbuka'];
        $this->assertSame(2, $keluhan['terbuka']);
        $this->assertSame(1, $keluhan['baru']);
        $this->assertSame(1, $keluhan['diproses']);
        $this->assertSame(1, $keluhan['kritis_terbuka']);
        $this->assertSame(3, $keluhan['masuk_hari_ini']);
        $this->assertCount(7, $keluhan['tren']['series']['masuk']);
        $this->assertSame(3, $keluhan['tren']['series']['masuk'][6]);

        $stok = $w['stok_kritis'];
        $this->assertSame(3, $stok['total_bahan']);
        $this->assertSame(1, $stok['di_bawah_minimum']);
        $this->assertSame(1, $stok['sudah_kedaluwarsa']);
        $this->assertSame(1, $stok['akan_kedaluwarsa']);

        $anggaran = $w['anggaran'];
        $this->assertSame(10_000_000, $anggaran['anggaran']);
        $this->assertSame(5_000_000, $anggaran['terpakai']);
        $this->assertSame(5_000_000, $anggaran['sisa']);
        $this->assertEquals(50.0, $anggaran['persen']);
        $this->assertSame('aman', $anggaran['status']);
        $this->assertFalse($anggaran['penanda']['mencapai_ambang']);
        $this->assertSame(['jumlah' => 1, 'nominal' => 500_000], $anggaran['menunggu_persetujuan']);
        $this->assertNull($anggaran['per_sppg']);
        $this->assertSame(self::BULAN, $anggaran['periode']['bulan']);

        $this->assertSame(3, $w['bukti_nota']['pengeluaran_dihitung']);
        $this->assertSame(['jumlah' => 2, 'nominal' => 1_500_000], $w['bukti_nota']['bukti_belum_ada']);
        $this->assertSame(66.67, $w['bukti_nota']['persen_bukti_belum_ada']);

        $this->assertSame(2, $w['pengajuan_menunggu_investor']['total']);
        $this->assertSame(['jumlah' => 1, 'nominal' => 500_000], $w['pengajuan_menunggu_investor']['pengeluaran']);

        $rekap = $w['rekap_harian'];
        $this->assertTrue($rekap['sudah_disusun']);
        $this->assertSame('menunggu', $rekap['status_kirim']);
        $this->assertSame('17:00', $rekap['jam_kirim']);
        $this->assertFalse($rekap['sudah_lewat_jam_kirim']);
    }

    public function test_ambang_80_persen_ditandai_pada_anggaran(): void
    {
        $this->pengeluaran($this->sppgA, 3_500_000, 'disetujui', 'peralatan', 'x.jpg');

        $a = $this->widgets($this->kepalaA)['anggaran'];

        $this->assertEquals(85.0, $a['persen']);
        $this->assertSame('peringatan', $a['status']);
        $this->assertTrue($a['penanda']['mencapai_ambang']);
        $this->assertFalse($a['penanda']['mencapai_100']);

        $this->pengeluaran($this->sppgA, 2_000_000, 'disetujui', 'peralatan', 'y.jpg');
        $this->lewatiCache();
        $a = $this->widgets($this->kepalaA)['anggaran'];
        $this->assertTrue($a['penanda']['mencapai_100']);
        $this->assertTrue($a['penanda']['melampaui']);
        $this->assertSame('melampaui', $a['status']);
    }

    public function test_pengeluaran_pembelian_dengan_nota_tidak_dihitung_tanpa_bukti(): void
    {
        $bahan = (int) DB::table('bahan_bakus')->where('sppg_id', $this->sppgA)->value('id');
        $pemasok = (int) DB::table('pemasoks')->where('sppg_id', $this->sppgA)->value('id');
        $pembelian = $this->ins('pembelian_bahans', [
            'sppg_id' => $this->sppgA, 'bahan_baku_id' => $bahan, 'pemasok_id' => $pemasok, 'tanggal_beli' => self::HARI, 'jumlah' => 1,
            'satuan' => 'kg', 'harga_satuan' => 1000, 'total' => 1000, 'nota_path' => 'uploads/pembelian/n.jpg', 'status' => 'terverifikasi',
        ]);
        $this->ins('pengeluarans', [
            'sppg_id' => $this->sppgA, 'tanggal' => self::HARI, 'kategori' => 'bahan_baku', 'uraian' => 'dari pembelian', 'nominal' => 1000,
            'status_persetujuan' => 'disetujui', 'versi' => 1, 'pembelian_bahan_id' => $pembelian,
        ]);
        $lampiranId = $this->pengeluaran($this->sppgA, 2000, 'disetujui', 'lainnya');
        $this->ins('lampirans', [
            'sppg_id' => $this->sppgA, 'lampiranable_type' => 'App\\Models\\Pengeluaran', 'lampiranable_id' => $lampiranId,
            'kategori' => 'nota', 'path' => 'uploads/z.jpg', 'mime' => 'image/jpeg', 'ukuran' => 10,
        ]);

        $n = $this->widgets($this->kepalaA)['bukti_nota'];

        // 3 (fixture) + 2 baru = 5 dihitung; yang belum punya bukti tetap 2 (kedua pengeluaran baru punya bukti).
        $this->assertSame(5, $n['pengeluaran_dihitung']);
        $this->assertSame(2, $n['bukti_belum_ada']['jumlah']);
    }

    public function test_ahli_gizi(): void
    {
        $w = $this->widgets($this->ahliGiziA);

        $this->assertSame(2, $w['menu_hari_ini']['jumlah']);
        $this->assertEquals(['draf' => 1, 'diajukan' => 0, 'disetujui' => 1, 'ditolak' => 0, 'perlu_revisi' => 0], array_column($w['menu_hari_ini']['per_status'], 'jumlah', 'status'));

        $gizi = $w['gizi_vs_standar'];
        $this->assertSame(1, $gizi['sesuai']);
        $this->assertSame(0, $gizi['tidak_sesuai']);
        $this->assertSame(1, $gizi['tanpa_standar']);
        $this->assertTrue($gizi['items'][1]['sesuai'] ?? $gizi['items'][0]['sesuai']);

        $biaya = $w['biaya_per_porsi'];
        $this->assertEquals(12500.0, $biaya['rata_rata']);
        $this->assertSame(1, $biaya['menu_tanpa_biaya']);

        $this->assertSame(1, $w['hasil_mutu']['hari_ini']['pemeriksaan']);
    }

    public function test_gizi_di_luar_standar_ditandai(): void
    {
        DB::table('menus')->where('id', $this->a['m1'])->update(['energi_kkal' => 800]);

        $item = collect($this->widgets($this->ahliGiziA)['gizi_vs_standar']['items'])->firstWhere('menu_id', $this->a['m1']);

        $this->assertFalse($item['sesuai']);
        $this->assertSame('lebih', collect($item['zat'])->firstWhere('kolom', 'energi_kkal')['status']);
    }

    public function test_akuntan(): void
    {
        $w = $this->widgets($this->akuntanA);

        $p = $w['pencairan_vs_pengeluaran'];
        $this->assertSame(10_000_000, $p['pencairan']);
        $this->assertSame(5_000_000, $p['pengeluaran']);
        $this->assertSame(2, $p['jumlah_pengeluaran']);
        $this->assertSame(5_000_000, $p['selisih']);
        $this->assertEquals(50.0, $p['persen']);

        $kategori = array_column($w['serapan_per_kategori']['items'], null, 'kategori');
        $this->assertSame(4_000_000, $kategori['bahan_baku']['nominal']);
        $this->assertEquals(80.0, $kategori['bahan_baku']['persen_dari_terpakai']);
        $this->assertSame(1_000_000, $kategori['energi']['nominal']);
        $this->assertSame(0, $kategori['transportasi']['nominal']);

        $this->assertSame(['jumlah' => 1, 'nominal' => 500_000], $w['pengeluaran_menunggu']['pengeluaran']);
        $this->assertSame(0, $w['pengeluaran_menunggu']['pembelian_menunggu_verifikasi']['jumlah']);

        $tren = $w['selisih_tren'];
        $this->assertSame(10_000_000, $tren['series']['pencairan'][6]);
        $this->assertSame(5_000_000, $tren['series']['pengeluaran'][6]);
        $this->assertSame(5_000_000, $tren['total_selisih']);
        $this->assertSame(0, $tren['series']['pencairan'][0]);

        $this->assertSame(2, $w['bukti_nota']['bukti_belum_ada']['jumlah']);
    }

    public function test_staf_gudang(): void
    {
        $w = $this->widgets($this->stafGudangA);

        $this->assertSame(3, $w['stok_per_bahan']['total_bahan']);
        $this->assertSame('Beras Fiktif', $w['stok_per_bahan']['terendah'][0]['nama']);
        $this->assertSame(3, array_sum(array_column($w['stok_per_bahan']['per_kategori'], 'jumlah')));

        $this->assertSame(1, $w['stok_di_bawah_minimum']['jumlah']);
        $this->assertSame(['Beras Fiktif'], array_column($w['stok_di_bawah_minimum']['items'], 'nama'));

        $k = $w['stok_mendekati_kedaluwarsa'];
        $this->assertSame(1, $k['sudah_kedaluwarsa']);
        $this->assertSame(1, $k['akan_kedaluwarsa']);
        $this->assertSame(['Telur Fiktif', 'Beras Fiktif'], array_column($k['items'], 'nama'));
        $this->assertSame('2026-11-01', $k['items'][0]['kedaluwarsa_terdekat']);

        $this->assertSame(3, $w['mutasi_hari_ini']['total']);
        $this->assertEquals(['masuk' => 2, 'keluar' => 1, 'penyesuaian' => 0], array_column($w['mutasi_hari_ini']['per_jenis'], 'jumlah', 'jenis'));

        $p = $w['pengiriman_pemasok'];
        $this->assertSame(2, $p['penerimaan_hari_ini']);
        $this->assertSame(1, $p['pemasok_hari_ini']);
        $this->assertNull($p['terjadwal']);
        $this->assertSame(['menunggu_verifikasi' => 0, 'ditolak_bulan_ini' => 0], $p['pembelian']);
    }

    public function test_relawan_dapur(): void
    {
        $w = $this->widgets($this->relawanA);

        $this->assertSame(2, $w['menu_target_hari_ini']['jumlah_jadwal']);
        $this->assertSame(800, $w['menu_target_hari_ini']['total_target_porsi']);
        $this->assertSame(['Nasi Ayam Fiktif', 'Bubur Kacang Fiktif'], array_column($w['menu_target_hari_ini']['items'], 'menu'));

        $this->assertSame(1, $w['status_log_produksi']['sudah_ada_log']);
        $this->assertSame(1, $w['status_log_produksi']['belum_ada_log']);
        $this->assertSame(480, collect($w['status_log_produksi']['items'])->firstWhere('ada_log', true)['realisasi_porsi']);

        $this->assertSame(1, $w['checklist_mutu_belum_diisi']['jumlah']);
        $this->assertSame('Bubur Kacang Fiktif', $w['checklist_mutu_belum_diisi']['items'][0]['menu']);
    }

    public function test_relawan_distribusi(): void
    {
        $res = $this->sebagai($this->relawanDistribusiA)->getJson(self::URL)->assertOk();
        $w = $res->json('data.widgets');

        $this->assertSame(3, $w['rute_hari_ini']['jumlah_titik']);
        $this->assertEquals(['diterima' => 1, 'berangkat' => 1, 'menunggu' => 1, 'siap' => 0, 'dibatalkan' => 0], array_column($w['status_kirim']['per_status'], 'jumlah', 'status'));
        $this->assertSame(1, $w['bukti_belum_diunggah']['jumlah']);
        $this->assertSame('Posyandu Fiktif Dua', $w['bukti_belum_diunggah']['items'][0]['titik_layanan']);
        $this->assertEquals(['bertugas' => 1, 'tersedia' => 1, 'servis' => 0], array_column($w['armada_pengemudi']['armada_per_status'], 'jumlah', 'status'));
        $this->assertSame(['id', 'nama'], array_keys($w['armada_pengemudi']['pengemudi_aktif'][0]));
        $this->assertSame('Pengemudi Fiktif', $w['armada_pengemudi']['pengemudi_aktif'][0]['nama']);

        $json = $res->getContent();
        $this->assertStringNotContainsString('PIC Fiktif', $json);
        $this->assertStringNotContainsString('SIM-RAHASIA', $json);
        $this->assertStringNotContainsString('081200000001', $json);
    }

    public function test_admin_lintas_sppg_dan_filter_sppg(): void
    {
        $this->ins('audit_logs', ['event' => 'auth.masuk_gagal', 'auditable_type' => 'App\\Models\\User', 'auditable_id' => $this->kepalaA->id]);
        $this->ins('audit_logs', ['event' => 'auth.masuk_gagal', 'auditable_type' => 'App\\Models\\User', 'auditable_id' => $this->kepalaA->id]);
        $this->ins('audit_logs', ['event' => 'auth.masuk_gagal', 'auditable_type' => 'App\\Models\\User', 'auditable_id' => $this->kepalaB->id, 'created_at' => '2026-11-01 08:00:00']);
        $this->ins('audit_logs', ['event' => 'auth.akun_terkunci', 'auditable_type' => 'App\\Models\\User', 'auditable_id' => $this->kepalaB->id]);
        $this->ins('audit_logs', ['event' => 'auth.logout', 'auditable_type' => 'App\\Models\\User', 'auditable_id' => $this->kepalaB->id]);
        DB::table('users')->where('id', $this->kepalaB->id)->update(['locked_until' => now()->addMinutes(10)]);

        $this->ins('jobs', ['queue' => 'default', 'payload' => '{"rahasia":"x"}', 'attempts' => 0, 'available_at' => now()->timestamp, 'created_at' => now()->timestamp]);
        $this->ins('jobs', ['queue' => 'default', 'payload' => '{}', 'attempts' => 1, 'reserved_at' => now()->timestamp, 'available_at' => now()->timestamp, 'created_at' => now()->timestamp]);
        DB::table('failed_jobs')->insert(['uuid' => 'u-1', 'connection' => 'database', 'queue' => 'default', 'payload' => '{}', 'exception' => 'x', 'failed_at' => now()]);
        DB::table('failed_jobs')->insert(['uuid' => 'u-2', 'connection' => 'database', 'queue' => 'default', 'payload' => '{}', 'exception' => 'x', 'failed_at' => now()->subDays(20)]);
        $this->ins('telegram_updates', ['update_id' => 1, 'payload' => '{"pesan":"rahasia"}', 'created_at' => now()->subMinutes(10)->toDateTimeString()]);
        $this->ins('telegram_updates', ['update_id' => 2, 'payload' => '{}', 'processed_at' => now()->toDateTimeString(), 'created_at' => now()->subMinutes(10)->toDateTimeString()]);
        $this->ins('telegram_updates', ['update_id' => 3, 'payload' => '{}', 'created_at' => now()->toDateTimeString()]);

        foreach ([['ai-1', $this->sppgA, 30], ['ai-2', $this->sppgB, 30], ['ai-3', $this->sppgA, -30]] as [$uuid, $sppg, $menit]) {
            $this->ins('ai_drafts', [
                'uuid' => $uuid, 'sppg_id' => $sppg, 'user_id' => $this->kepalaA->id, 'module' => 'keluhan', 'action' => 'create',
                'payload' => '{"rahasia":"isi draf"}', 'status' => 'pending', 'expires_at' => now()->addMinutes($menit)->toDateTimeString(),
            ]);
        }

        $res = $this->sebagai($this->admin)->getJson(self::URL)->assertOk();
        $w = $res->json('data.widgets');

        $this->assertSame('gabungan', $res->json('data.mode'));
        $op = $w['operasional_lintas_sppg'];
        $this->assertSame(3, $op['jumlah_sppg']);
        $perSppg = array_column($op['per_sppg'], null, 'sppg_id');
        $this->assertSame(800, $perSppg[$this->sppgA]['target_porsi']);
        $this->assertSame(480, $perSppg[$this->sppgA]['realisasi_porsi']);
        $this->assertSame(1000, $perSppg[$this->sppgB]['target_porsi']);
        $this->assertSame(2, $perSppg[$this->sppgB]['keluhan_kritis']);
        $this->assertSame(0, $perSppg[$this->sppgC]['target_porsi']);
        $this->assertSame(1800, $op['total']['target_porsi']);
        $this->assertSame(3, $op['total']['keluhan_kritis']);
        $this->assertSame(3, $op['total']['stok_di_bawah_minimum']);

        $peran = array_column($w['pengguna_aktif_per_peran']['items'], 'aktif', 'peran');
        $this->assertSame(1, $peran['admin']);
        $this->assertSame(2, $peran['kepala_sppg']);
        $this->assertSame(2, $peran['investor']);
        $this->assertSame('semua_sppg', $w['pengguna_aktif_per_peran']['cakupan']);

        $login = $w['login_gagal'];
        $this->assertSame(2, $login['hari_ini']['masuk_gagal']);
        $this->assertSame(1, $login['hari_ini']['akun_terkunci']);
        $this->assertSame(1, $login['tren']['series']['masuk_gagal'][5]);
        $this->assertSame(2, $login['tren']['series']['masuk_gagal'][6]);
        $this->assertSame(4, $login['total_rentang']);
        $this->assertSame(1, $login['akun_terkunci_saat_ini']);

        $q = $w['antrean_job_webhook'];
        $this->assertSame(['menunggu_atau_ditunda' => 1, 'sedang_diproses' => 1], $q['antrean']);
        $this->assertSame(['total' => 2, 'dalam_rentang' => 1], $q['job_gagal']);
        $this->assertSame(1, $q['webhook_tersangkut']['jumlah']);
        $this->assertSame(10, $q['webhook_tersangkut']['tertua_menit']);

        $ai = $w['draf_ai_tertunda'];
        $this->assertSame(2, $ai['menunggu_konfirmasi']);
        $this->assertSame(1, $ai['kedaluwarsa_belum_dibersihkan']);

        // Filter satu SPPG mempersempit operasional/pengguna/draf, tetapi widget sistem tetap global.
        $res = $this->sebagai($this->admin)->getJson(self::URL.'?sppg_id='.$this->sppgA)->assertOk();
        $w = $res->json('data.widgets');
        $this->assertSame('satu', $res->json('data.mode'));
        $this->assertSame(1, $w['operasional_lintas_sppg']['jumlah_sppg']);
        $this->assertSame(800, $w['operasional_lintas_sppg']['total']['target_porsi']);
        $this->assertSame('sppg_terpilih', $w['pengguna_aktif_per_peran']['cakupan']);
        $this->assertSame(0, array_column($w['pengguna_aktif_per_peran']['items'], 'aktif', 'peran')['admin']);
        $this->assertSame(1, $w['draf_ai_tertunda']['menunggu_konfirmasi']);
        $this->assertSame('sistem', $w['login_gagal']['cakupan']);
        $this->assertSame(2, $w['login_gagal']['hari_ini']['masuk_gagal']);

        $json = $res->getContent();
        $this->assertStringNotContainsString('rahasia', $json);

        $this->sebagai($this->admin)->getJson(self::URL.'?sppg_id=999999')->assertNotFound();
    }

    public function test_investor_gabungan_dan_per_sppg(): void
    {
        $res = $this->sebagai($this->investorAB)->getJson(self::URL)->assertOk();
        $this->assertSame('gabungan', $res->json('data.mode'));
        $this->assertSame(['SPPG A', 'SPPG B'], array_column($res->json('data.sppg'), 'nama'));
        $this->assertCount(2, $res->json('data.sppg_pilihan'));
        $w = $res->json('data.widgets');

        $porsi = $w['porsi_hari_ini'];
        $this->assertSame(1800, $porsi['target']);
        $this->assertSame(1380, $porsi['realisasi']);
        $this->assertSame(1500, $porsi['didistribusikan']);
        $this->assertSame(1240, $porsi['diterima']);

        $a = $w['anggaran'];
        $this->assertSame(30_000_000, $a['anggaran']);
        $this->assertSame(23_000_000, $a['terpakai']);
        $this->assertSame(76.67, $a['persen']);
        $this->assertSame(30_000_000, $a['rencana_anggaran']);
        $this->assertCount(2, $a['per_sppg']);
        $this->assertSame(['aman', 'peringatan'], array_column($a['per_sppg'], 'status'));
        $this->assertEquals(90.0, $a['per_sppg'][1]['persen']);

        $p = $w['pengajuan_menunggu'];
        $this->assertSame(5, $p['total']);
        $this->assertSame(['jumlah' => 2, 'nominal' => 1_200_000], $p['pengeluaran']);
        $this->assertSame(3, $p['menu']['jumlah']);
        $this->assertSame([1, 2], array_column($p['per_sppg'], 'menu'));

        $this->assertSame(5, $w['stok_kritis']['total_bahan']);
        $this->assertSame(3, $w['stok_kritis']['di_bawah_minimum']);
        $this->assertSame(1, $w['stok_kritis']['sudah_kedaluwarsa']);
        $this->assertSame(2, $w['stok_kritis']['akan_kedaluwarsa']);

        $this->assertSame(1, $w['insiden_mutu']['tidak_layak_hari_ini']);
        $this->assertSame(2, $w['insiden_mutu']['tidak_layak_bulan']);
        $this->assertSame(3, $w['keluhan_kritis']['kritis_terbuka']);

        $tren = $w['tren'];
        $this->assertSame(1800, $tren['series']['porsi_target'][6]);
        $this->assertSame(1380 + 0, $tren['series']['porsi_realisasi'][6]);
        $this->assertSame(190, $tren['series']['porsi_realisasi'][5]);
        $this->assertSame(23_000_000, $tren['series']['pengeluaran'][6]);
        $this->assertSame(5, $tren['series']['keluhan_masuk'][6]);
        $this->assertSame(1, $tren['series']['mutu_tidak_layak'][5]);

        // Pilih satu SPPG milik sendiri lewat query maupun header.
        foreach ([[self::URL.'?sppg_id='.$this->sppgA, null], [self::URL, $this->sppgA]] as [$url, $header]) {
            $res = $this->sebagai($this->investorAB, $header)->getJson($url)->assertOk();
            $this->assertSame('satu', $res->json('data.mode'));
            $this->assertSame(['SPPG A'], array_column($res->json('data.sppg'), 'nama'));
            $this->assertCount(2, $res->json('data.sppg_pilihan'));
            $this->assertSame(800, $res->json('data.widgets.porsi_hari_ini.target'));
            $this->assertSame(10_000_000, $res->json('data.widgets.anggaran.anggaran'));
            $this->assertNull($res->json('data.widgets.anggaran.per_sppg'));
        }

        $this->assertSame(1000, $this->widgets($this->investorAB, '', $this->sppgB)['porsi_hari_ini']['target']);
        $this->assertSame(1000, $this->widgets($this->investorAB, '?sppg_id='.$this->sppgB, $this->sppgA)['porsi_hari_ini']['target']);
    }

    public function test_investor_tidak_melihat_modul_tertutup_atau_data_pribadi(): void
    {
        $res = $this->sebagai($this->investorAB)->getJson(self::URL)->assertOk();
        $json = $res->getContent();

        foreach (['distribusi_per_titik', 'kebutuhan_khusus', 'rute_hari_ini', 'menu_hari_ini', 'SDN Fiktif', 'Posyandu', 'Anak Fiktif', 'RAHASIA', 'PIC Fiktif', 'nota-a.jpg', 'Nasi Ayam Fiktif'] as $terlarang) {
            $this->assertStringNotContainsString($terlarang, $json, $terlarang);
        }
    }
}
