<?php

namespace Tests\Feature\Master;

use App\Enums\Role;
use App\Models\AiDraft;
use App\Models\PenerimaManfaat;
use App\Models\TitikLayanan;
use App\Models\User;
use PHPUnit\Framework\Attributes\DataProvider;

class PenerimaManfaatTest extends MasterApiTestCase
{
    private TitikLayanan $titikA;

    private TitikLayanan $titikA2;

    private TitikLayanan $titikB;

    protected function setUp(): void
    {
        parent::setUp();
        $this->titikA = TitikLayanan::factory()->create(['sppg_id' => $this->sppgA, 'nama' => 'SDN A1']);
        $this->titikA2 = TitikLayanan::factory()->create(['sppg_id' => $this->sppgA, 'nama' => 'Posyandu A2']);
        $this->titikB = TitikLayanan::factory()->create(['sppg_id' => $this->sppgB, 'nama' => 'SDN B1']);
    }

    /** @return array<string, mixed> */
    private function payload(array $override = []): array
    {
        return array_merge([
            'titik_layanan_id' => $this->titikA->id,
            'nama' => 'A.R.',
            'kelompok_sasaran' => 'siswa',
            'kelas_keterangan' => 'Kelas 4B',
            'catatan_alergi' => 'Alergi kacang',
        ], $override);
    }

    private function penerima(TitikLayanan $titik, array $attrs = []): PenerimaManfaat
    {
        $this->forgetActor();

        return PenerimaManfaat::factory()->create(['sppg_id' => $titik->sppg_id, 'titik_layanan_id' => $titik->id] + $attrs);
    }

    public function test_kepala_membuat_penerima(): void
    {
        $this->as($this->kepalaA)->postJson('/api/v1/penerima-manfaat', $this->payload())
            ->assertCreated()
            ->assertJsonPath('data.nama', 'A.R.')
            ->assertJsonPath('data.sppg_id', $this->sppgA)
            ->assertJsonPath('data.titik_layanan_nama', 'SDN A1')
            ->assertJsonPath('data.kelompok_sasaran', 'siswa');

        $this->assertDatabaseHas('penerima_manfaats', ['nama' => 'A.R.', 'sppg_id' => $this->sppgA]);
        $this->assertDatabaseHas('audit_logs', ['event' => 'created', 'auditable_type' => (new PenerimaManfaat)->getMorphClass(), 'user_id' => $this->kepalaA->id]);
    }

    public function test_admin_sppg_id_diturunkan_dari_titik_layanan(): void
    {
        $this->as($this->admin)->postJson('/api/v1/penerima-manfaat', $this->payload(['titik_layanan_id' => $this->titikB->id, 'sppg_id' => $this->sppgA]))
            ->assertCreated()->assertJsonPath('data.sppg_id', $this->sppgB);
    }

    public function test_kepala_tidak_dapat_memakai_titik_layanan_sppg_lain(): void
    {
        $this->as($this->kepalaA)->postJson('/api/v1/penerima-manfaat', $this->payload(['titik_layanan_id' => $this->titikB->id]))
            ->assertUnprocessable()->assertJsonValidationErrors(['titik_layanan_id']);
        $this->assertDatabaseCount('penerima_manfaats', 0);
    }

    public function test_validasi_gagal(): void
    {
        $this->as($this->kepalaA);

        $this->postJson('/api/v1/penerima-manfaat', [])->assertUnprocessable()->assertJsonValidationErrors(['titik_layanan_id', 'nama', 'kelompok_sasaran']);
        $this->postJson('/api/v1/penerima-manfaat', $this->payload(['kelompok_sasaran' => 'lansia']))->assertUnprocessable()->assertJsonValidationErrors(['kelompok_sasaran']);
        $this->postJson('/api/v1/penerima-manfaat', $this->payload(['titik_layanan_id' => 999999]))->assertUnprocessable()->assertJsonValidationErrors(['titik_layanan_id']);
        $this->postJson('/api/v1/penerima-manfaat', $this->payload(['nama' => str_repeat('a', 101)]))->assertUnprocessable()->assertJsonValidationErrors(['nama']);
        $this->postJson('/api/v1/penerima-manfaat', $this->payload(['catatan_alergi' => str_repeat('a', 1001)]))->assertUnprocessable()->assertJsonValidationErrors(['catatan_alergi']);
    }

    public function test_nik_ditolak(): void
    {
        $this->as($this->kepalaA);

        foreach (['3201234567890001', 'Budi 3201234567890001', '12345678'] as $nama) {
            $this->postJson('/api/v1/penerima-manfaat', $this->payload(['nama' => $nama]))
                ->assertUnprocessable()->assertJsonValidationErrors(['nama']);
        }

        $this->postJson('/api/v1/penerima-manfaat', $this->payload(['nama' => 'Siswa 07']))->assertCreated();
        $this->assertDatabaseCount('penerima_manfaats', 1);
    }

    public function test_titik_layanan_nonaktif_tidak_menerima_penerima_baru(): void
    {
        $this->titikA2->update(['is_active' => false]);

        $this->as($this->kepalaA);
        $this->postJson('/api/v1/penerima-manfaat', $this->payload(['titik_layanan_id' => $this->titikA2->id]))
            ->assertUnprocessable()->assertJsonValidationErrors(['titik_layanan_id']);
    }

    public function test_ubah_data_tanpa_mengganti_titik_nonaktif_tetap_boleh(): void
    {
        $pm = $this->penerima($this->titikA2);
        $this->titikA2->update(['is_active' => false]);

        $this->as($this->kepalaA)->patchJson("/api/v1/penerima-manfaat/{$pm->id}", ['kelas_keterangan' => 'Kelas 5'])->assertOk();
    }

    public function test_memindahkan_ke_titik_lain_di_sppg_sama(): void
    {
        $pm = $this->penerima($this->titikA);

        $this->as($this->kepalaA)->patchJson("/api/v1/penerima-manfaat/{$pm->id}", ['titik_layanan_id' => $this->titikA2->id])
            ->assertOk()->assertJsonPath('data.titik_layanan_id', $this->titikA2->id);
        $this->patchJson("/api/v1/penerima-manfaat/{$pm->id}", ['titik_layanan_id' => $this->titikB->id])->assertUnprocessable();
    }

    /** @return array<string, array{Role}> */
    public static function perananTanpaAkses(): array
    {
        return ['akuntan' => [Role::Akuntan], 'staf_gudang' => [Role::StafGudang], 'relawan' => [Role::Relawan]];
    }

    #[DataProvider('perananTanpaAkses')]
    public function test_peran_tanpa_hak_ditolak_total(Role $role): void
    {
        $pm = $this->penerima($this->titikA);
        $actor = User::withoutGlobalScopes()->where('role', $role->value)->firstOrFail();

        $this->as($actor);
        $this->getJson('/api/v1/penerima-manfaat')->assertForbidden();
        $this->getJson("/api/v1/penerima-manfaat/{$pm->id}")->assertForbidden();
        $this->postJson('/api/v1/penerima-manfaat', [])->assertForbidden();
        $this->patchJson("/api/v1/penerima-manfaat/{$pm->id}", ['nama' => 'X'])->assertForbidden();
        $this->deleteJson("/api/v1/penerima-manfaat/{$pm->id}")->assertForbidden();
    }

    public function test_ahli_gizi_hanya_membaca_termasuk_catatan_alergi(): void
    {
        $pm = $this->penerima($this->titikA, ['catatan_alergi' => 'Alergi telur']);

        $this->as($this->ahliGiziA);
        $this->getJson('/api/v1/penerima-manfaat')->assertOk()->assertJsonPath('data.0.catatan_alergi', 'Alergi telur');
        $this->getJson("/api/v1/penerima-manfaat/{$pm->id}")->assertOk();
        $this->postJson('/api/v1/penerima-manfaat', $this->payload())->assertForbidden();
        $this->patchJson("/api/v1/penerima-manfaat/{$pm->id}", ['nama' => 'X'])->assertForbidden();
        $this->deleteJson("/api/v1/penerima-manfaat/{$pm->id}")->assertForbidden();
    }

    public function test_kepala_cru_tanpa_hapus(): void
    {
        $pm = $this->penerima($this->titikA);

        $this->as($this->kepalaA);
        $this->patchJson("/api/v1/penerima-manfaat/{$pm->id}", ['nama' => 'B.S.'])->assertOk()->assertJsonPath('data.nama', 'B.S.');
        $this->deleteJson("/api/v1/penerima-manfaat/{$pm->id}")->assertForbidden();
        $this->assertDatabaseHas('penerima_manfaats', ['id' => $pm->id, 'deleted_at' => null]);
    }

    public function test_admin_hapus_lunak_dan_tercatat_audit(): void
    {
        $pm = $this->penerima($this->titikB);

        $this->as($this->admin)->deleteJson("/api/v1/penerima-manfaat/{$pm->id}")->assertNoContent();

        $this->assertSoftDeleted('penerima_manfaats', ['id' => $pm->id]);
        $this->assertDatabaseHas('audit_logs', ['event' => 'deleted', 'auditable_id' => $pm->id, 'user_id' => $this->admin->id]);
        $this->getJson("/api/v1/penerima-manfaat/{$pm->id}")->assertNotFound();
    }

    public function test_lintas_sppg_404(): void
    {
        $milikB = $this->penerima($this->titikB);

        $this->as($this->kepalaA);
        $this->getJson("/api/v1/penerima-manfaat/{$milikB->id}")->assertNotFound();
        $this->patchJson("/api/v1/penerima-manfaat/{$milikB->id}", ['nama' => 'X'])->assertNotFound();
        $this->getJson('/api/v1/penerima-manfaat')->assertOk()->assertJsonPath('meta.total', 0);
    }

    public function test_filter_per_titik_kelompok_alergi_pencarian_dan_paginasi(): void
    {
        $this->penerima($this->titikA, ['nama' => 'Ani', 'kelompok_sasaran' => 'siswa', 'catatan_alergi' => 'Kacang']);
        $this->penerima($this->titikA, ['nama' => 'Budi', 'kelompok_sasaran' => 'siswa', 'catatan_alergi' => null]);
        $this->penerima($this->titikA2, ['nama' => 'Citra', 'kelompok_sasaran' => 'balita', 'catatan_alergi' => null, 'is_active' => false]);
        $this->penerima($this->titikB, ['nama' => 'Dodi']);

        $this->as($this->kepalaA);
        $this->getJson('/api/v1/penerima-manfaat')->assertOk()->assertJsonPath('meta.total', 3);
        $this->getJson('/api/v1/penerima-manfaat?titik_layanan_id='.$this->titikA->id)->assertOk()->assertJsonPath('meta.total', 2);
        $this->getJson('/api/v1/penerima-manfaat?kelompok_sasaran=balita')->assertOk()->assertJsonPath('meta.total', 1);
        $this->getJson('/api/v1/penerima-manfaat?ada_alergi=1')->assertOk()->assertJsonPath('meta.total', 1)->assertJsonPath('data.0.nama', 'Ani');
        $this->getJson('/api/v1/penerima-manfaat?is_active=false')->assertOk()->assertJsonPath('data.0.nama', 'Citra');
        $this->getJson('/api/v1/penerima-manfaat?q=kacang')->assertOk()->assertJsonPath('meta.total', 1);
        $this->getJson('/api/v1/penerima-manfaat?per_page=2')->assertOk()->assertJsonCount(2, 'data')->assertJsonPath('meta.per_page', 2);

        // Admin: lintas SPPG, bisa menyaring per SPPG. Filter titik SPPG lain oleh kepala tidak bocor.
        $this->getJson('/api/v1/penerima-manfaat?titik_layanan_id='.$this->titikB->id)->assertOk()->assertJsonPath('meta.total', 0);
        $this->as($this->admin)->getJson('/api/v1/penerima-manfaat')->assertOk()->assertJsonPath('meta.total', 4);
        $this->getJson('/api/v1/penerima-manfaat?sppg_id='.$this->sppgB)->assertOk()->assertJsonPath('meta.total', 1);
    }

    public function test_tanpa_token_401(): void
    {
        $this->getJson('/api/v1/penerima-manfaat')->assertUnauthorized();
    }

    public function test_ai_draft_id(): void
    {
        $this->forgetActor();
        $draf = AiDraft::factory()->create(['user_id' => $this->kepalaA->id, 'sppg_id' => $this->sppgA, 'module' => 'penerima-manfaat']);

        $this->as($this->kepalaA)->postJson('/api/v1/penerima-manfaat', $this->payload(['ai_draft_id' => $draf->uuid]))->assertCreated();

        $this->assertDatabaseHas('ai_drafts', ['id' => $draf->id, 'status' => 'confirmed']);
    }
}
