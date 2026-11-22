<?php

namespace Tests\Feature\PetaHak;

use App\Enums\JenisLaporanBgn;
use App\Enums\Role;
use App\Http\Resources\AuthUserResource;
use App\Models\FeedbackKeluhan;
use App\Models\JadwalProduksi;
use App\Models\LaporanBgn;
use App\Models\Menu;
use App\Models\NotifikasiPerjalanan;
use App\Models\RekapHarian;
use App\Models\TitikLayanan;
use App\Models\User;
use App\Support\PetaHak;
use App\Support\Rbac;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;

/**
 * Peta hak `unit_scope` dan `aksi` di GET /auth/me: sama dengan Rbac/Policy (parity pada record nyata), tabel
 * kebenaran per profil, investor baca saja, nonaktif -> peta kosong. Server tetap otoritas (lihat tes sub-request).
 */
class PetaHakAuthMeTest extends PetaHakTestCase
{
    /** @var list<string> */
    private const AKSI = PetaHak::KUNCI_AKSI;

    /**
     * Tabel kebenaran dari matriks Tabel 5 (config/rbac.php) + aturan Policy; ditulis tangan agar tes tidak tautologis.
     *
     * @return array<string, list<string>> profil => aksi yang harus true
     */
    private function benar(): array
    {
        return [
            'admin' => ['undang_pengguna', 'reset_password_pengguna', 'kelola_kepemilikan_sppg', 'undang_pic', 'lihat_rincian_biaya', 'tindak_lanjut_keluhan', 'catat_mutasi_stok', 'catat_harga_pemasok', 'lihat_ringkasan_jadwal_produksi', 'lihat_mutasi_stok', 'lihat_laporan_bgn', 'lihat_laporan_bgn_agregat', 'terbitkan_nomor_laporan_bgn'],
            'kepala_sppg' => ['undang_pic', 'lihat_rincian_biaya', 'picu_perjalanan', 'susun_rekap', 'kirim_ulang_rekap', 'tindak_lanjut_keluhan', 'lihat_ringkasan_jadwal_produksi', 'lihat_mutasi_stok', 'lihat_laporan_bgn', 'lihat_laporan_bgn_agregat', 'terbitkan_nomor_laporan_bgn'],
            'ahli_gizi' => ['ajukan_menu', 'lihat_rincian_biaya', 'tindak_lanjut_keluhan', 'lihat_ringkasan_jadwal_produksi'],
            'akuntan' => ['lihat_mutasi_stok', 'lihat_laporan_bgn', 'lihat_laporan_bgn_agregat', 'terbitkan_nomor_laporan_bgn'],
            'staf_gudang' => ['lihat_rincian_biaya', 'catat_mutasi_stok', 'catat_harga_pemasok', 'lihat_ringkasan_jadwal_produksi', 'lihat_mutasi_stok'],
            'relawan.dapur' => ['lihat_ringkasan_jadwal_produksi'],
            'relawan.distribusi' => ['picu_perjalanan', 'lihat_ringkasan_jadwal_produksi'],
            'relawan.tanpa_unit' => [],
            'investor' => ['lihat_laporan_bgn_agregat'],
        ];
    }

    private function me(User $user): array
    {
        return $this->masuk($user)->getJson('/api/v1/auth/me')->assertOk()->json('user');
    }

    public function test_aksi_per_profil_sama_dengan_tabel_kebenaran(): void
    {
        foreach ($this->profil() as $nama => $user) {
            $aksi = $this->me($user)['aksi'];

            foreach (self::AKSI as $kunci) {
                $this->assertArrayHasKey($kunci, $aksi, "{$nama}: kunci {$kunci} hilang");
                $this->assertSame(in_array($kunci, $this->benar()[$nama], true), $aksi[$kunci], "{$nama}: aksi.{$kunci}");
            }
        }
    }

    public function test_aksi_true_jika_dan_hanya_jika_policy_mengizinkan_pada_record_nyata(): void
    {
        $menu = Menu::factory()->create(['sppg_id' => $this->sppgA]);
        $titik = TitikLayanan::factory()->create(['sppg_id' => $this->sppgA]);
        $rekap = RekapHarian::factory()->create(['sppg_id' => $this->sppgA]);
        $keluhan = FeedbackKeluhan::factory()->create(['sppg_id' => $this->sppgA]);
        $this->forgetActor();

        foreach ($this->profil() as $nama => $user) {
            $gate = Gate::forUser($user);
            $harapan = [
                'undang_pengguna' => $gate->allows('create', User::class),
                'reset_password_pengguna' => $gate->allows('update', $this->relawanA),
                'kelola_kepemilikan_sppg' => $gate->allows('kelolaKepemilikan', $this->investorAB),
                'undang_pic' => $gate->allows('update', $titik),
                'ajukan_menu' => $gate->allows('ajukan', $menu),
                'lihat_rincian_biaya' => $gate->allows('lihatRincianBiaya', $menu),
                'picu_perjalanan' => $gate->allows('create', NotifikasiPerjalanan::class),
                'susun_rekap' => $gate->allows('create', RekapHarian::class),
                'kirim_ulang_rekap' => $gate->allows('update', $rekap),
                'tindak_lanjut_keluhan' => $gate->allows('update', $keluhan),
                'catat_mutasi_stok' => $gate->allows('update', $this->berasA),
                'catat_harga_pemasok' => $gate->allows('catatHarga', $this->pemasokA),
                'lihat_ringkasan_jadwal_produksi' => $gate->allows('viewRingkas', JadwalProduksi::class),
                'lihat_mutasi_stok' => $gate->allows('lihatMutasi', $this->berasA),
                'lihat_laporan_bgn' => $gate->allows('lihat', [LaporanBgn::class, JenisLaporanBgn::Harian, $this->sppgA]),
                'lihat_laporan_bgn_agregat' => $gate->allows('lihat', [LaporanBgn::class, JenisLaporanBgn::Porsi, $this->sppgA]),
                'terbitkan_nomor_laporan_bgn' => $gate->allows('terbitkanNomor', [LaporanBgn::class, JenisLaporanBgn::Harian, $this->sppgA]),
            ];
            $aksi = $this->me($user)['aksi'];

            foreach ($harapan as $kunci => $nilai) {
                // SPPG aktif investor hanya terselesaikan di dalam permintaan (header/pivot); parity-nya diuji di PetaHakSubRequestParityTest.
                if ($nama === 'investor' && str_contains($kunci, 'laporan_bgn')) {
                    continue;
                }

                $this->assertSame($nilai, $aksi[$kunci], "{$nama}: aksi.{$kunci} harus sama dengan Policy");
            }

            $this->forgetActor();
        }
    }

    public function test_dashboard_tersedia_konsisten_dengan_dashboard_policy_dan_bot(): void
    {
        $tersedia = ['admin' => true, 'kepala_sppg' => true, 'ahli_gizi' => true, 'akuntan' => true, 'staf_gudang' => true,
            'relawan.dapur' => true, 'relawan.distribusi' => true, 'relawan.tanpa_unit' => false, 'investor' => true];
        $profil = ['admin' => 'admin', 'kepala_sppg' => 'kepala_sppg', 'ahli_gizi' => 'ahli_gizi', 'akuntan' => 'akuntan', 'staf_gudang' => 'staf_gudang',
            'relawan.dapur' => 'relawan_dapur', 'relawan.distribusi' => 'relawan_distribusi', 'investor' => 'investor'];

        foreach ($this->profil() as $nama => $user) {
            $d = $this->me($user)['aksi']['dashboard'];

            $this->assertSame($tersedia[$nama], $d['tersedia'], "{$nama}: aksi.dashboard.tersedia");
            $this->assertSame($profil[$nama] ?? null, $d['profil'], "{$nama}: aksi.dashboard.profil");

            // Tidak pernah true bila DashboardPolicy::lihat menolak.
            if ($d['tersedia']) {
                $this->assertTrue(Gate::forUser($user)->allows('dashboard.lihat'), $nama);
            }
        }

        // Pengguna tanpa SPPG (bukan admin/investor) ditolak DashboardPolicy -> tersedia=false walau perannya punya dashboard.
        $tanpaSppg = $this->userOf(Role::KepalaSppg, $this->sppgA);
        $tanpaSppg->forceFill(['sppg_id' => null])->saveQuietly();
        $this->assertFalse(Gate::forUser($tanpaSppg->fresh())->allows('dashboard.lihat'));
        $this->assertFalse(PetaHak::aksi($tanpaSppg->fresh())['dashboard']['tersedia']);
    }

    public function test_unit_scope_per_profil(): void
    {
        $harapan = [
            'admin' => [], 'kepala_sppg' => [], 'ahli_gizi' => [], 'akuntan' => [], 'staf_gudang' => [],
            'relawan.dapur' => ['jadwal' => 'produksi'],
            'relawan.distribusi' => ['jadwal' => 'distribusi'],
            'relawan.tanpa_unit' => [],
            'investor' => [],
        ];

        foreach ($this->profil() as $nama => $user) {
            $respons = $this->masuk($user)->getJson('/api/v1/auth/me')->assertOk();
            $this->assertSame($harapan[$nama], $respons->json('user.unit_scope'), "{$nama}: unit_scope");
            // Selalu objek JSON (bukan array), supaya klien dapat memakai `in`/indeks kunci.
            $this->assertStringContainsString($harapan[$nama] === [] ? '"unit_scope":{}' : '"unit_scope":{"jadwal"', $respons->getContent(), $nama);

            foreach ($harapan[$nama] as $modul => $jenis) {
                $this->assertSame($jenis, Rbac::unitScope($user, $modul), "{$nama}: Rbac::unitScope({$modul})");
            }
        }
    }

    public function test_investor_baca_saja_tidak_ada_hak_tulis_di_permintaan_peta(): void
    {
        $me = $this->me($this->investorAB);

        $this->assertTrue($me['baca_saja']);
        // Satu-satunya aksi true bagi investor adalah BACA agregat laporan BGN (30a, 30c, 30d-A/B); tanpa aksi tulis.
        $this->assertSame(['lihat_laporan_bgn_agregat'], array_values(array_filter(self::AKSI, fn (string $k) => $me['aksi'][$k] === true)));

        foreach ($me['permissions'] as $modul => $huruf) {
            $this->assertDoesNotMatchRegularExpression('/[CUD]/', $huruf, "investor {$modul}");
        }
    }

    public function test_pengguna_nonaktif_peta_kosong_gagal_tertutup(): void
    {
        foreach ($this->profil() as $nama => $user) {
            $user->forceFill(['is_active' => false])->saveQuietly();
            $data = (new AuthUserResource($user->fresh()))->resolve(Request::create('/'));

            $this->assertSame([], $data['modules'], $nama);
            $this->assertSame([], $data['permissions'], $nama);
            $this->assertEquals((object) [], $data['unit_scope'], $nama);
            $this->assertSame([], $data['keputusan'], $nama);

            foreach (self::AKSI as $kunci) {
                $this->assertFalse($data['aksi'][$kunci], "{$nama} nonaktif: aksi.{$kunci}");
            }

            $this->assertSame(['tersedia' => false, 'profil' => null], $data['aksi']['dashboard'], $nama);
        }
    }

    public function test_menghitung_peta_hak_tidak_menambah_query_basis_data(): void
    {
        foreach ([$this->admin, $this->kepalaA, $this->relawanA, $this->akuntanA] as $user) {
            DB::flushQueryLog();
            DB::enableQueryLog();
            PetaHak::aksi($user);
            PetaHak::unitScope($user);
            $jumlah = count(DB::getQueryLog());
            DB::disableQueryLog();

            $this->assertSame(0, $jumlah, "peta hak {$user->role->value} tidak boleh memicu query");
        }
    }

    public function test_bidang_lama_tetap_ada_dan_tanpa_login_401(): void
    {
        $me = $this->me($this->kepalaA);

        foreach (['modules', 'permissions', 'keputusan', 'agregat', 'baca_saja', 'unit', 'sppg_aktif_id', 'multi_sppg'] as $kunci) {
            $this->assertArrayHasKey($kunci, $me);
        }

        $this->forgetActor();
        $this->flushHeaders();
        $this->getJson('/api/v1/auth/me')->assertUnauthorized();
    }
}
