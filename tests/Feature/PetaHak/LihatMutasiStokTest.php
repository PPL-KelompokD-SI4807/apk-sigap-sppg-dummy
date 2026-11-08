<?php

namespace Tests\Feature\PetaHak;

use App\Models\BahanBaku;
use App\Models\MutasiStok;
use App\Support\PetaHak;
use App\Support\Rbac;
use Illuminate\Support\Facades\Gate;

/**
 * Aksi `lihat_mutasi_stok`: GET /bahan-baku/{id}/mutasi hanya admin, kepala_sppg, staf_gudang, akuntan.
 * ahli_gizi, investor, dan relawan 403 (walau ahli_gizi/investor tetap R daftar/detail bahan baku).
 */
class LihatMutasiStokTest extends PetaHakTestCase
{
    /** @var list<string> */
    private const BOLEH = ['admin', 'kepala_sppg', 'staf_gudang', 'akuntan'];

    protected function setUp(): void
    {
        parent::setUp();

        MutasiStok::factory()->count(2)->create(['sppg_id' => $this->sppgA, 'bahan_baku_id' => $this->berasA->id]);
        $this->forgetActor();
    }

    public function test_hanya_empat_peran_berhak_200_peran_lain_403(): void
    {
        foreach ($this->profil() as $nama => $user) {
            $respons = $this->masuk($user)->getJson("/api/v1/bahan-baku/{$this->berasA->id}/mutasi");

            if (in_array($nama, self::BOLEH, true)) {
                $respons->assertOk()->assertJsonCount(2, 'data');
            } else {
                $respons->assertForbidden();
            }
        }
    }

    public function test_ahli_gizi_dan_investor_tetap_boleh_daftar_dan_detail_bahan_baku(): void
    {
        foreach ([$this->ahliGiziA, $this->investorAB] as $user) {
            $this->masuk($user);
            $this->getJson('/api/v1/bahan-baku')->assertOk();
            $this->getJson("/api/v1/bahan-baku/{$this->berasA->id}")->assertOk();
            $this->getJson("/api/v1/bahan-baku/{$this->berasA->id}/mutasi")->assertForbidden();
        }
    }

    public function test_peta_auth_me_sama_dengan_endpoint_dan_policy(): void
    {
        foreach ($this->profil() as $nama => $user) {
            $aksi = $this->masuk($user)->getJson('/api/v1/auth/me')->assertOk()->json('user.aksi');
            $status = $this->masuk($user)->getJson("/api/v1/bahan-baku/{$this->berasA->id}/mutasi")->getStatusCode();

            $this->assertSame(in_array($nama, self::BOLEH, true), $aksi['lihat_mutasi_stok'], "{$nama}: aksi.lihat_mutasi_stok");
            $this->assertSame($aksi['lihat_mutasi_stok'], $status === 200, "{$nama}: peta vs endpoint ({$status})");
            $this->assertSame($aksi['lihat_mutasi_stok'], Gate::forUser($user)->allows('lihatMutasi', $this->berasA), "{$nama}: peta vs Policy");
        }
    }

    public function test_lintas_sppg_ditolak_dan_admin_lintas_sppg(): void
    {
        $milikB = BahanBaku::factory()->create(['sppg_id' => $this->sppgB]);
        $this->forgetActor();

        $this->masuk($this->stafGudangA)->getJson("/api/v1/bahan-baku/{$milikB->id}/mutasi")->assertNotFound();
        $this->masuk($this->kepalaA)->getJson("/api/v1/bahan-baku/{$milikB->id}/mutasi")->assertNotFound();
        $this->masuk($this->admin)->getJson("/api/v1/bahan-baku/{$milikB->id}/mutasi")->assertOk();
        $this->assertFalse(Gate::forUser($this->stafGudangA)->allows('lihatMutasi', $milikB));
    }

    public function test_pengguna_nonaktif_dan_aksi_tak_dikenal_gagal_tertutup(): void
    {
        $this->stafGudangA->forceFill(['is_active' => false])->saveQuietly();

        $this->assertFalse(PetaHak::aksi($this->stafGudangA->fresh())['lihat_mutasi_stok']);
        $this->assertFalse(Rbac::canAction($this->kepalaA, 'aksi-tidak-ada'));
        $this->assertFalse(Rbac::canAction(null, 'lihat-mutasi-stok'));
    }

    public function test_tanpa_login_401(): void
    {
        $this->forgetActor();
        $this->flushHeaders();
        $this->getJson("/api/v1/bahan-baku/{$this->berasA->id}/mutasi")->assertUnauthorized();
    }
}
