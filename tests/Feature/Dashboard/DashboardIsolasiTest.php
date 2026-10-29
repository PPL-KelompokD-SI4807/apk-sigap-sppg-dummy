<?php

namespace Tests\Feature\Dashboard;

use App\Enums\Role;
use App\Models\User;
use App\Services\Dashboard\DashboardService;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

/** Isolasi lintas SPPG/investor, privasi, penyamaran k=5, cache, dan beban query. */
class DashboardIsolasiTest extends DashboardTestCase
{
    /** Teks yang hanya ada di SPPG B. */
    private const MILIK_B = ['SDN Fiktif B', 'Menu B Fiktif', 'Gula B Fiktif', 'Garam B Fiktif'];

    /** Teks yang hanya ada di SPPG A. */
    private const MILIK_A = ['SDN Fiktif Satu', 'Posyandu Fiktif Dua', 'Nasi Ayam Fiktif', 'Beras Fiktif', 'B 1234 FIK', 'Pengemudi Fiktif'];

    /** Data pribadi/rahasia yang tidak boleh muncul di dashboard peran mana pun. */
    private const RAHASIA = ['PIC Fiktif', 'Anak Fiktif', 'RAHASIA', 'SIM-RAHASIA', '0812000000', 'nota-a.jpg', 'Alergi', 'Deskripsi keluhan'];

    /** @return list<array{0: string, 1: User}> */
    private function penggunaA(): array
    {
        return [
            ['kepala', $this->kepalaA], ['ahli gizi', $this->ahliGiziA], ['akuntan', $this->akuntanA], ['gudang', $this->stafGudangA],
            ['relawan dapur', $this->relawanA], ['relawan distribusi', $this->relawanDistribusiA],
        ];
    }

    public function test_peran_satu_sppg_tidak_melihat_data_sppg_lain(): void
    {
        foreach ($this->penggunaA() as [$nama, $user]) {
            $json = $this->sebagai($user)->getJson(self::URL)->assertOk()->getContent();

            foreach (self::MILIK_B as $teks) {
                $this->assertStringNotContainsString($teks, $json, "{$nama} bocor {$teks}");
            }
        }

        $b = $this->sebagai($this->kepalaB)->getJson(self::URL)->assertOk();
        $json = $b->getContent();
        $this->assertSame(1000, $b->json('data.widgets.jadwal_produksi_hari_ini.target_porsi'));
        $this->assertSame(20_000_000, $b->json('data.widgets.anggaran.anggaran'));
        $this->assertSame(2, $b->json('data.widgets.keluhan_terbuka.kritis_terbuka'));

        foreach (self::MILIK_A as $teks) {
            $this->assertStringNotContainsString($teks, $json, "kepala B bocor {$teks}");
        }
    }

    public function test_peran_satu_sppg_tidak_bisa_memilih_sppg_lain(): void
    {
        foreach ($this->penggunaA() as [$nama, $user]) {
            $this->sebagai($user)->getJson(self::URL.'?sppg_id='.$this->sppgB)->assertForbidden()->assertJsonPath('alasan', 'sppg_aktif_ditolak');
            $this->sebagai($user)->getJson(self::URL.'?sppg_id='.$this->sppgA)->assertOk();
            // Header asing ditolak middleware yang sama.
            $this->sebagai($user, $this->sppgB)->getJson(self::URL)->assertForbidden()->assertJsonPath('alasan', 'sppg_aktif_ditolak');
        }
    }

    public function test_investor_tidak_melihat_sppg_investor_lain(): void
    {
        // Investor AB meminta SPPG C (milik investor lain) atau SPPG yang tidak ada: 403 sama, tanpa membocorkan keberadaan.
        foreach ([$this->sppgC, 999_999] as $asing) {
            $this->sebagai($this->investorAB)->getJson(self::URL.'?sppg_id='.$asing)->assertForbidden()->assertJsonPath('alasan', 'sppg_aktif_ditolak');
        }

        $this->sebagai($this->investorAB, $this->sppgC)->getJson(self::URL)->assertForbidden()->assertJsonPath('alasan', 'sppg_aktif_ditolak');
        $this->sebagai($this->investorC)->getJson(self::URL.'?sppg_id='.$this->sppgA)->assertForbidden()->assertJsonPath('alasan', 'sppg_aktif_ditolak');

        // Investor C hanya melihat SPPG C (kosong), tanpa jejak A atau B.
        $res = $this->sebagai($this->investorC)->getJson(self::URL)->assertOk();
        $this->assertSame(['SPPG C'], array_column($res->json('data.sppg'), 'nama'));
        $this->assertSame(0, $res->json('data.widgets.porsi_hari_ini.target'));
        $this->assertSame(0, $res->json('data.widgets.anggaran.anggaran'));
        $this->assertSame(0, $res->json('data.widgets.keluhan_kritis.kritis_terbuka'));

        foreach ([...self::MILIK_A, ...self::MILIK_B, 'SPPG A', 'SPPG B'] as $teks) {
            $this->assertStringNotContainsString($teks, $res->getContent(), $teks);
        }
    }

    public function test_investor_gabungan_tidak_menyertakan_sppg_yang_dihapus_atau_dicabut(): void
    {
        DB::table('investor_sppg')->where(['user_id' => $this->investorAB->id, 'sppg_id' => $this->sppgB])->delete();

        $res = $this->sebagai($this->investorAB)->getJson(self::URL)->assertOk();
        $this->assertSame(['SPPG A'], array_column($res->json('data.sppg'), 'nama'));
        $this->assertSame(800, $res->json('data.widgets.porsi_hari_ini.target'));
        $this->assertStringNotContainsString('SPPG B', $res->getContent());

        $this->lewatiCache();
        $this->sebagai($this->investorAB)->getJson(self::URL.'?sppg_id='.$this->sppgB)->assertForbidden();
    }

    public function test_sppg_terhapus_lunak_tidak_masuk_cakupan(): void
    {
        DB::table('sppgs')->where('id', $this->sppgB)->update(['deleted_at' => now()]);

        $res = $this->sebagai($this->investorAB)->getJson(self::URL)->assertOk();
        $this->assertSame(['SPPG A'], array_column($res->json('data.sppg'), 'nama'));

        $this->sebagai($this->investorAB)->getJson(self::URL.'?sppg_id='.$this->sppgB)->assertForbidden();
        $admin = $this->sebagai($this->admin)->getJson(self::URL)->assertOk();
        $this->assertStringNotContainsString('SPPG B', json_encode($admin->json('data.widgets.operasional_lintas_sppg')));
        $this->sebagai($this->admin)->getJson(self::URL.'?sppg_id='.$this->sppgB)->assertNotFound();
    }

    public function test_tidak_ada_data_pribadi_di_dashboard_peran_mana_pun(): void
    {
        $pengguna = [...$this->penggunaA(), ['admin', $this->admin], ['investor', $this->investorAB]];

        foreach ($pengguna as [$nama, $user]) {
            $json = $this->sebagai($user)->getJson(self::URL)->assertOk()->getContent();

            foreach (self::RAHASIA as $teks) {
                $this->assertStringNotContainsString($teks, $json, "{$nama} memuat {$teks}");
            }
        }
    }

    // ------------------------------------------------------------------ penerima manfaat (k = 5)

    public function test_kebutuhan_khusus_menyamarkan_sel_kecil_beserta_pelengkapnya(): void
    {
        // Fixture: siswa 6, balita 3, ibu_hamil 0, ibu_menyusui 0 -> balita (<5) disamarkan; siswa ikut disamarkan
        // sebagai sel pelengkap agar balita tidak dapat dihitung dari total.
        $res = $this->sebagai($this->ahliGiziA)->getJson(self::URL)->assertOk();
        $w = $res->json('data.widgets.kebutuhan_khusus');

        $sel = array_column($w['per_kelompok_sasaran'], null, 'kelompok_sasaran');
        $this->assertNull($sel['balita']['jumlah']);
        $this->assertSame('<5', $sel['balita']['penanda']);
        $this->assertNull($sel['siswa']['jumlah']);
        $this->assertSame('<5', $sel['siswa']['penanda']);
        $this->assertSame(0, $sel['ibu_hamil']['jumlah']);
        $this->assertNull($w['total']);
        $this->assertSame(['diterapkan' => true, 'ambang_k' => 5, 'penanda' => '<5'], $w['penyamaran']);

        // Tidak ada agregat lain yang memuat angka sel: tidak ada total penerima aktif / pelengkap.
        $json = json_encode($w);
        foreach (['"aktif"', '"nonaktif"', '"tanpa'] as $kunci) {
            $this->assertStringNotContainsString($kunci, $json);
        }
        $this->assertStringNotContainsString(':9', $json);
        $this->assertStringNotContainsString(':6', $json);
        $this->assertStringNotContainsString(':3,', $json);
    }

    public function test_kebutuhan_khusus_menampilkan_angka_bila_semua_sel_di_atas_ambang(): void
    {
        $this->penerima($this->sppgA, $this->a['t2'], 'balita', 4, 'Alergi fiktif lain'); // balita 3 + 4 = 7

        $w = $this->sebagai($this->ahliGiziA)->getJson(self::URL)->assertOk()->json('data.widgets.kebutuhan_khusus');
        $sel = array_column($w['per_kelompok_sasaran'], 'jumlah', 'kelompok_sasaran');

        $this->assertSame(['siswa' => 6, 'balita' => 7, 'ibu_hamil' => 0, 'ibu_menyusui' => 0], $sel);
        $this->assertSame(13, $w['total']);
    }

    public function test_ambang_k_mengikuti_config(): void
    {
        config(['rbac.agregat_ambang.k' => 7]);

        $w = $this->sebagai($this->ahliGiziA)->getJson(self::URL)->assertOk()->json('data.widgets.kebutuhan_khusus');

        $this->assertSame(7, $w['penyamaran']['ambang_k']);
        $this->assertSame('<7', $w['penyamaran']['penanda']);
        $sel = array_column($w['per_kelompok_sasaran'], 'jumlah', 'kelompok_sasaran');
        $this->assertNull($sel['siswa']);
        $this->assertNull($sel['balita']);
    }

    public function test_satu_sel_kecil_saja_tetap_disamarkan_dan_total_disembunyikan(): void
    {
        DB::table('penerima_manfaats')->where('sppg_id', $this->sppgA)->where('kelompok_sasaran', 'siswa')->update(['catatan_alergi' => null]);
        $this->penerima($this->sppgA, $this->a['t1'], 'siswa', 2, 'Alergi fiktif');

        $w = $this->sebagai($this->ahliGiziA)->getJson(self::URL)->assertOk()->json('data.widgets.kebutuhan_khusus');

        $this->assertNull($w['total']);
        $this->assertSame(0, array_column($w['per_kelompok_sasaran'], 'jumlah', 'kelompok_sasaran')['ibu_hamil']);
        $this->assertNull(array_column($w['per_kelompok_sasaran'], 'jumlah', 'kelompok_sasaran')['siswa']);
    }

    public function test_peran_tanpa_hak_penerima_manfaat_tidak_mendapat_widgetnya(): void
    {
        foreach ([$this->kepalaA, $this->akuntanA, $this->stafGudangA, $this->investorAB, $this->relawanA, $this->admin] as $user) {
            $this->assertArrayNotHasKey('kebutuhan_khusus', $this->sebagai($user)->getJson(self::URL)->assertOk()->json('data.widgets'));
        }
    }

    // ------------------------------------------------------------------ RBAC per modul

    public function test_widget_menjadi_null_bila_peran_kehilangan_hak_baca_modulnya(): void
    {
        config(['rbac.modules.keuangan.akuntan' => '']);

        $w = $this->sebagai($this->akuntanA)->getJson(self::URL)->assertOk()->json('data.widgets');

        $this->assertSame(['pencairan_vs_pengeluaran', 'serapan_per_kategori', 'pengeluaran_menunggu', 'selisih_tren', 'bukti_nota'], array_keys($w));
        $this->assertSame([null], array_values(array_unique($w, SORT_REGULAR)));
    }

    // ------------------------------------------------------------------ cache

    public function test_cache_60_detik_dan_kedaluwarsa(): void
    {
        $pertama = $this->sebagai($this->kepalaA)->getJson(self::URL)->assertOk();
        $this->keluhan($this->sppgA, 'kritis'); // data berubah

        $kedua = $this->sebagai($this->kepalaA)->getJson(self::URL)->assertOk();
        $this->assertSame($pertama->json('data.dibuat_pada'), $kedua->json('data.dibuat_pada'));
        $this->assertSame(1, $kedua->json('data.widgets.keluhan_terbuka.kritis_terbuka'));

        $this->lewatiCache();
        $ketiga = $this->sebagai($this->kepalaA)->getJson(self::URL)->assertOk();
        $this->assertSame(2, $ketiga->json('data.widgets.keluhan_terbuka.kritis_terbuka'));
        $this->assertSame(60, $ketiga->json('data.cache_detik'));
    }

    public function test_cache_tidak_dibagi_antar_pengguna_atau_sppg_atau_rentang(): void
    {
        $this->sebagai($this->kepalaA)->getJson(self::URL)->assertOk();

        // Kepala lain di SPPG yang sama: kunci cache berbeda -> dihitung sendiri (ada query data).
        $kepalaA2 = $this->userOf(Role::KepalaSppg, $this->sppgA);
        DB::enableQueryLog();
        $this->sebagai($kepalaA2)->getJson(self::URL)->assertOk();
        $this->assertGreaterThan(5, count(DB::getQueryLog()));
        DB::disableQueryLog();

        // Kepala SPPG lain: angka sendiri.
        $this->assertSame(1000, $this->sebagai($this->kepalaB)->getJson(self::URL)->json('data.widgets.jadwal_produksi_hari_ini.target_porsi'));
        $this->assertSame(800, $this->sebagai($this->kepalaA)->getJson(self::URL)->json('data.widgets.jadwal_produksi_hari_ini.target_porsi'));

        // Investor: gabungan, satu SPPG, dan rentang masing-masing punya entri sendiri.
        $this->assertSame(1800, $this->sebagai($this->investorAB)->getJson(self::URL)->json('data.widgets.porsi_hari_ini.target'));
        $this->assertSame(800, $this->sebagai($this->investorAB)->getJson(self::URL.'?sppg_id='.$this->sppgA)->json('data.widgets.porsi_hari_ini.target'));
        $this->assertSame(1000, $this->sebagai($this->investorAB)->getJson(self::URL.'?sppg_id='.$this->sppgB)->json('data.widgets.porsi_hari_ini.target'));
        $this->assertCount(30, $this->sebagai($this->investorAB)->getJson(self::URL.'?rentang=30')->json('data.widgets.tren.labels'));
        $this->assertCount(7, $this->sebagai($this->investorAB)->getJson(self::URL)->json('data.widgets.tren.labels'));
    }

    public function test_kunci_cache_memuat_pengguna_peran_dan_himpunan_sppg(): void
    {
        $this->sebagai($this->investorAB)->getJson(self::URL)->assertOk();

        $md5 = md5(implode(',', [$this->sppgA, $this->sppgB]));
        $kunci = sprintf('dashboard:v1:u%d:investor:-:gabungan:s%s:r7:%s', $this->investorAB->id, $md5, self::HARI);
        $this->assertTrue(Cache::has($kunci), 'kunci cache harus memuat user_id, peran, mode, himpunan SPPG, rentang, tanggal');

        // Pengguna lain tidak dapat membaca isi kunci tersebut karena kuncinya memuat user_id miliknya sendiri.
        $this->assertFalse(Cache::has(sprintf('dashboard:v1:u%d:investor:-:gabungan:s%s:r7:%s', $this->investorC->id, $md5, self::HARI)));
    }

    public function test_pencabutan_akses_berlaku_walau_ada_cache(): void
    {
        $this->sebagai($this->investorAB)->getJson(self::URL.'?sppg_id='.$this->sppgB)->assertOk();
        DB::table('investor_sppg')->where(['user_id' => $this->investorAB->id, 'sppg_id' => $this->sppgB])->delete();

        // Validasi cakupan dilakukan SEBELUM cache dibaca: cache tidak pernah meloloskan SPPG yang sudah dicabut.
        $this->sebagai($this->investorAB)->getJson(self::URL.'?sppg_id='.$this->sppgB)->assertForbidden();
    }

    // ------------------------------------------------------------------ beban query

    public function test_jumlah_query_dibatasi_dan_tidak_tumbuh_dengan_banyaknya_sppg(): void
    {
        // Tambah SPPG ke investor AB agar jelas tidak ada N+1: 2 SPPG vs 1 SPPG vs 6 SPPG.
        $hitung = function (User $user, string $query = ''): int {
            DB::flushQueryLog();
            DB::enableQueryLog();
            $this->sebagai($user)->getJson(self::URL.$query)->assertOk();
            $n = count(DB::getQueryLog());
            DB::disableQueryLog();

            return $n;
        };

        $satu = $hitung($this->investorAB, '?sppg_id='.$this->sppgA);
        $dua = $hitung($this->investorAB);

        $this->assertSame($satu, $dua, 'jumlah query tidak boleh bertambah dengan banyaknya SPPG');
        $this->assertLessThanOrEqual(30, $dua);

        $banyak = $this->investor([$this->sppgA, $this->sppgB, $this->sppgC, $this->createSppg('D'), $this->createSppg('E'), $this->createSppg('F')]);
        $this->assertSame($dua, $hitung($banyak));

        foreach ([$this->kepalaA, $this->ahliGiziA, $this->akuntanA, $this->stafGudangA, $this->relawanA, $this->relawanDistribusiA, $this->admin] as $user) {
            $n = $hitung($user);
            $this->assertLessThanOrEqual(30, $n, $user->role->value.' memakai '.$n.' query');
        }

        // Respons ke-2 dari cache: tidak ada query data (hanya autentikasi/cakupan).
        $cache = $hitung($this->kepalaA);
        $this->assertLessThanOrEqual(8, $cache);
    }

    public function test_menu_dan_log_banyak_tidak_menambah_query(): void
    {
        $sebelum = (function () {
            DB::flushQueryLog();
            DB::enableQueryLog();
            $this->sebagai($this->ahliGiziA)->getJson(self::URL)->assertOk();
            $n = count(DB::getQueryLog());
            DB::disableQueryLog();

            return $n;
        })();

        for ($i = 0; $i < 12; $i++) {
            $m = $this->menu($this->sppgA, 'Menu Tambahan '.$i, 'diajukan');
            $this->jadwal($this->sppgA, $m, 10);
            $this->pengajuanMenu($this->sppgA, $m, 'diajukan', 8000, 1);
        }

        $this->lewatiCache();
        DB::flushQueryLog();
        DB::enableQueryLog();
        $this->sebagai($this->ahliGiziA)->getJson(self::URL)->assertOk();
        $sesudah = count(DB::getQueryLog());
        DB::disableQueryLog();

        $this->assertSame($sebelum, $sesudah, 'banyaknya menu tidak boleh menambah jumlah query (N+1)');
        $this->assertLessThanOrEqual(30, $sesudah);
        $this->assertCount(14, $this->sebagai($this->ahliGiziA)->getJson(self::URL)->json('data.widgets.menu_hari_ini.items'));
    }

    public function test_kunci_waktu_membeku(): void
    {
        $this->assertSame(self::HARI, Carbon::now('Asia/Jakarta')->toDateString());
        $this->assertSame([7, 30], DashboardService::RENTANG_DIIZINKAN);
    }
}
