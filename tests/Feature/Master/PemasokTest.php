<?php

namespace Tests\Feature\Master;

use App\Enums\Role;
use App\Models\AiDraft;
use App\Models\BahanBaku;
use App\Models\Pemasok;
use App\Models\User;
use PHPUnit\Framework\Attributes\DataProvider;

class PemasokTest extends MasterApiTestCase
{
    /** @return array<string, mixed> */
    private function payload(array $override = []): array
    {
        return array_merge([
            'nama' => 'UD Tani Makmur',
            'jenis' => 'petani',
            'kontak' => '0812-0000-3333',
            'alamat' => 'Desa Fiktif RT 02',
            'jenis_bahan' => 'Sayur, beras',
        ], $override);
    }

    private function pemasok(int $sppgId, array $attrs = []): Pemasok
    {
        $this->forgetActor();

        return Pemasok::factory()->create(['sppg_id' => $sppgId] + $attrs);
    }

    public function test_staf_gudang_membuat_dan_mengubah_pemasok(): void
    {
        $id = $this->as($this->stafGudangA)->postJson('/api/v1/pemasok', $this->payload(['sppg_id' => $this->sppgB]))
            ->assertCreated()
            ->assertJsonPath('data.nama', 'UD Tani Makmur')
            ->assertJsonPath('data.sppg_id', $this->sppgA)
            ->assertJsonPath('data.is_active', true)
            ->json('data.id');

        $this->patchJson("/api/v1/pemasok/{$id}", ['is_active' => false, 'kontak' => '+62 812 0000 4444'])
            ->assertOk()->assertJsonPath('data.is_active', false)->assertJsonPath('data.kontak', '+62 812 0000 4444');
    }

    public function test_validasi_kontak_dan_field_lain(): void
    {
        $this->as($this->stafGudangA);

        $this->postJson('/api/v1/pemasok', [])->assertUnprocessable()->assertJsonValidationErrors(['nama', 'jenis']);
        $this->postJson('/api/v1/pemasok', $this->payload(['jenis' => 'tengkulak']))->assertUnprocessable()->assertJsonValidationErrors(['jenis']);

        foreach (['abc', '12345', '0812-abcd-3333', '08123456789012345678', '<script>', '0812 0000 33 33 33 33 33 33'] as $kontak) {
            $this->postJson('/api/v1/pemasok', $this->payload(['kontak' => $kontak]))
                ->assertUnprocessable()->assertJsonValidationErrors(['kontak']);
        }

        foreach (['081200003333', '0812-0000-3333', '+6281200003333', '(021) 555-1234', null] as $kontak) {
            $this->postJson('/api/v1/pemasok', $this->payload(['kontak' => $kontak]))->assertCreated();
        }
    }

    public function test_admin_wajib_sppg_id(): void
    {
        $this->as($this->admin);

        $this->postJson('/api/v1/pemasok', $this->payload())->assertUnprocessable()->assertJsonValidationErrors(['sppg_id']);
        $this->postJson('/api/v1/pemasok', $this->payload(['sppg_id' => $this->sppgB]))->assertCreated()->assertJsonPath('data.sppg_id', $this->sppgB);
    }

    /** @return array<string, array{Role}> */
    public static function perananRead(): array
    {
        return ['kepala_sppg' => [Role::KepalaSppg], 'ahli_gizi' => [Role::AhliGizi], 'akuntan' => [Role::Akuntan]];
    }

    #[DataProvider('perananRead')]
    public function test_peran_read_only(Role $role): void
    {
        $p = $this->pemasok($this->sppgA);
        $actor = User::withoutGlobalScopes()->where('role', $role->value)->where('sppg_id', $this->sppgA)->firstOrFail();

        $this->as($actor);
        $this->getJson('/api/v1/pemasok')->assertOk()->assertJsonPath('meta.total', 1);
        $this->getJson("/api/v1/pemasok/{$p->id}")->assertOk();
        $this->postJson('/api/v1/pemasok', [])->assertForbidden();
        $this->patchJson("/api/v1/pemasok/{$p->id}", ['nama' => 'X'])->assertForbidden();
        $this->deleteJson("/api/v1/pemasok/{$p->id}")->assertForbidden();
    }

    public function test_relawan_tanpa_akses(): void
    {
        $p = $this->pemasok($this->sppgA);

        $this->as($this->relawanA);
        $this->getJson('/api/v1/pemasok')->assertForbidden();
        $this->getJson("/api/v1/pemasok/{$p->id}")->assertForbidden();
        $this->postJson('/api/v1/pemasok', [])->assertForbidden();
    }

    public function test_staf_gudang_tanpa_hapus(): void
    {
        $p = $this->pemasok($this->sppgA);

        $this->as($this->stafGudangA)->deleteJson("/api/v1/pemasok/{$p->id}")->assertForbidden();
        $this->assertDatabaseHas('pemasoks', ['id' => $p->id, 'deleted_at' => null]);
    }

    public function test_admin_hapus_lunak_walau_dipakai_bahan_baku_dan_riwayat_utuh(): void
    {
        $p = $this->pemasok($this->sppgA);
        $bahan = BahanBaku::factory()->create(['sppg_id' => $this->sppgA, 'pemasok_id' => $p->id]);

        $this->as($this->admin);
        $this->getJson("/api/v1/pemasok/{$p->id}")->assertOk()->assertJsonPath('data.bahan_baku_count', 1);
        $this->deleteJson("/api/v1/pemasok/{$p->id}")->assertNoContent();

        $this->assertSoftDeleted('pemasoks', ['id' => $p->id]);
        // Baris tetap ada di DB (bukan hapus permanen) dan bahan baku tidak ikut hilang.
        $this->assertDatabaseHas('pemasoks', ['id' => $p->id]);
        $this->assertDatabaseHas('bahan_bakus', ['id' => $bahan->id, 'pemasok_id' => $p->id, 'deleted_at' => null]);
        $this->getJson("/api/v1/pemasok/{$p->id}")->assertNotFound();
        $this->getJson('/api/v1/pemasok')->assertJsonPath('meta.total', 0);
    }

    public function test_tidak_ada_jalur_hapus_permanen(): void
    {
        $p = $this->pemasok($this->sppgA);

        $this->assertFalse($this->admin->can('forceDelete', $p));
        $this->as($this->admin)->deleteJson("/api/v1/pemasok/{$p->id}?force=1")->assertNoContent();
        $this->assertSoftDeleted('pemasoks', ['id' => $p->id]);
    }

    public function test_lintas_sppg_404_dan_admin_lintas_sppg(): void
    {
        $milikB = $this->pemasok($this->sppgB);

        $this->as($this->stafGudangA);
        $this->getJson("/api/v1/pemasok/{$milikB->id}")->assertNotFound();
        $this->patchJson("/api/v1/pemasok/{$milikB->id}", ['nama' => 'X'])->assertNotFound();
        $this->getJson('/api/v1/pemasok')->assertOk()->assertJsonPath('meta.total', 0);

        $this->as($this->admin)->getJson("/api/v1/pemasok/{$milikB->id}")->assertOk();
        $this->getJson('/api/v1/pemasok?sppg_id='.$this->sppgB)->assertOk()->assertJsonPath('meta.total', 1);
    }

    public function test_pencarian_filter_dan_paginasi(): void
    {
        $this->pemasok($this->sppgA, ['nama' => 'Peternakan Sumber', 'jenis' => 'peternak', 'jenis_bahan' => 'Telur ayam']);
        $this->pemasok($this->sppgA, ['nama' => 'Nelayan Pantai', 'jenis' => 'nelayan', 'jenis_bahan' => 'Ikan laut', 'is_active' => false]);
        $this->pemasok($this->sppgA, ['nama' => 'UMKM Tempe', 'jenis' => 'umkm', 'jenis_bahan' => 'Tempe']);

        $this->as($this->stafGudangA);
        $this->getJson('/api/v1/pemasok?jenis=nelayan')->assertOk()->assertJsonPath('meta.total', 1);
        $this->getJson('/api/v1/pemasok?is_active=false')->assertOk()->assertJsonPath('data.0.nama', 'Nelayan Pantai');
        $this->getJson('/api/v1/pemasok?q=telur')->assertOk()->assertJsonPath('meta.total', 1)->assertJsonPath('data.0.nama', 'Peternakan Sumber');
        $this->getJson('/api/v1/pemasok?q=%25')->assertOk()->assertJsonPath('meta.total', 0);
        $this->getJson('/api/v1/pemasok?per_page=2')->assertOk()->assertJsonCount(2, 'data')->assertJsonPath('meta.last_page', 2);
    }

    public function test_tanpa_token_401(): void
    {
        $this->getJson('/api/v1/pemasok')->assertUnauthorized();
    }

    public function test_ai_draft_id(): void
    {
        $this->forgetActor();
        $draf = AiDraft::factory()->create(['user_id' => $this->stafGudangA->id, 'sppg_id' => $this->sppgA, 'module' => 'pemasok']);

        $this->as($this->stafGudangA)->postJson('/api/v1/pemasok', $this->payload(['ai_draft_id' => $draf->uuid]))->assertCreated();

        $this->assertDatabaseHas('ai_drafts', ['id' => $draf->id, 'status' => 'confirmed']);
    }
}
