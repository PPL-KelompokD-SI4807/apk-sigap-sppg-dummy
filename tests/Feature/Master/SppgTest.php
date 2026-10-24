<?php

namespace Tests\Feature\Master;

use App\Enums\AksiAiDraft;
use App\Enums\Role;
use App\Models\AiDraft;
use App\Models\Sppg;
use App\Models\TitikLayanan;
use App\Models\User;
use PHPUnit\Framework\Attributes\DataProvider;

class SppgTest extends MasterApiTestCase
{
    /** @return array<string, mixed> */
    private function payload(array $override = []): array
    {
        return array_merge([
            'kode' => 'SPPG-BARU-01',
            'nama' => 'SPPG Baru',
            'alamat' => 'Jl. Fiktif No. 9',
            'mitra_yayasan' => 'Yayasan Fiktif',
            'telepon' => '0812-0000-9999',
            'kapasitas_porsi_harian' => 2500,
            'status_operasional' => 'aktif',
        ], $override);
    }

    public function test_admin_membuat_sppg(): void
    {
        $this->as($this->admin)->postJson('/api/v1/sppg', $this->payload())
            ->assertCreated()
            ->assertJsonPath('data.kode', 'SPPG-BARU-01')
            ->assertJsonPath('data.kapasitas_porsi_harian', 2500)
            ->assertJsonPath('data.status_operasional', 'aktif');

        $this->assertDatabaseHas('sppgs', ['kode' => 'SPPG-BARU-01', 'kapasitas_porsi_harian' => 2500]);
    }

    public function test_validasi_gagal_termasuk_kapasitas_harus_lebih_dari_nol(): void
    {
        $this->as($this->admin);

        $this->postJson('/api/v1/sppg', [])->assertUnprocessable()->assertJsonValidationErrors(['kode', 'nama', 'alamat', 'kapasitas_porsi_harian']);

        foreach ([0, -10, 'banyak', 1.5, 100001] as $kapasitas) {
            $this->postJson('/api/v1/sppg', $this->payload(['kapasitas_porsi_harian' => $kapasitas]))
                ->assertUnprocessable()->assertJsonValidationErrors(['kapasitas_porsi_harian']);
        }

        $this->postJson('/api/v1/sppg', $this->payload(['status_operasional' => 'rusak']))->assertUnprocessable()->assertJsonValidationErrors(['status_operasional']);
        $this->postJson('/api/v1/sppg', $this->payload(['telepon' => 'telepon']))->assertUnprocessable()->assertJsonValidationErrors(['telepon']);
        $this->postJson('/api/v1/sppg', $this->payload(['kode' => 'kode salah!']))->assertUnprocessable()->assertJsonValidationErrors(['kode']);
        $this->assertDatabaseCount('sppgs', 2);
    }

    public function test_kode_harus_unik_termasuk_yang_terhapus(): void
    {
        $this->as($this->admin);
        $id = $this->postJson('/api/v1/sppg', $this->payload())->json('data.id');
        $this->postJson('/api/v1/sppg', $this->payload())->assertUnprocessable()->assertJsonValidationErrors(['kode']);

        Sppg::query()->whereKey($id)->delete();
        $this->postJson('/api/v1/sppg', $this->payload())->assertUnprocessable()->assertJsonValidationErrors(['kode']);
    }

    public function test_admin_melihat_semua_sppg_dengan_pencarian_filter_paginasi(): void
    {
        $this->as($this->admin);
        $this->postJson('/api/v1/sppg', $this->payload(['nama' => 'Dapur Pelangi', 'status_operasional' => 'libur']))->assertCreated();

        $this->getJson('/api/v1/sppg')->assertOk()->assertJsonPath('meta.total', 3);
        $this->getJson('/api/v1/sppg?per_page=2')->assertOk()->assertJsonCount(2, 'data')->assertJsonStructure(['links', 'meta']);
        $this->getJson('/api/v1/sppg?q=Pelangi')->assertOk()->assertJsonPath('meta.total', 1);
        $this->getJson('/api/v1/sppg?q=%25')->assertOk()->assertJsonPath('meta.total', 0);
        $this->getJson('/api/v1/sppg?status_operasional=libur')->assertOk()->assertJsonPath('meta.total', 1);
    }

    public function test_kepala_melihat_dan_mengubah_hanya_sppg_sendiri(): void
    {
        $this->as($this->kepalaA);

        $this->getJson('/api/v1/sppg')->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.id', $this->sppgA);
        $this->getJson("/api/v1/sppg/{$this->sppgA}")->assertOk();
        $this->getJson("/api/v1/sppg/{$this->sppgB}")->assertForbidden();

        $this->patchJson("/api/v1/sppg/{$this->sppgA}", ['kapasitas_porsi_harian' => 3200, 'status_operasional' => 'libur'])
            ->assertOk()
            ->assertJsonPath('data.kapasitas_porsi_harian', 3200)
            ->assertJsonPath('data.status_operasional', 'libur');

        $this->patchJson("/api/v1/sppg/{$this->sppgB}", ['kapasitas_porsi_harian' => 1])->assertForbidden();
        $this->assertDatabaseHas('sppgs', ['id' => $this->sppgB, 'kapasitas_porsi_harian' => 3000]);
    }

    public function test_kepala_tidak_dapat_mengubah_kode_dan_kapasitas_nol_ditolak(): void
    {
        $kodeLama = Sppg::find($this->sppgA)->kode;

        $this->as($this->kepalaA);
        $this->putJson("/api/v1/sppg/{$this->sppgA}", ['kode' => 'KODE-DIBAJAK', 'nama' => 'Nama Baru'])->assertOk()->assertJsonPath('data.nama', 'Nama Baru');
        $this->assertDatabaseHas('sppgs', ['id' => $this->sppgA, 'kode' => $kodeLama]);

        $this->patchJson("/api/v1/sppg/{$this->sppgA}", ['kapasitas_porsi_harian' => 0])->assertUnprocessable()->assertJsonValidationErrors(['kapasitas_porsi_harian']);
    }

    public function test_admin_mengubah_kode_dengan_pengecekan_unik_mengabaikan_diri_sendiri(): void
    {
        $kodeB = Sppg::find($this->sppgB)->kode;
        $kodeA = Sppg::find($this->sppgA)->kode;

        $this->as($this->admin);
        $this->patchJson("/api/v1/sppg/{$this->sppgA}", ['kode' => $kodeA, 'nama' => 'Sama'])->assertOk();
        $this->patchJson("/api/v1/sppg/{$this->sppgA}", ['kode' => $kodeB])->assertUnprocessable()->assertJsonValidationErrors(['kode']);
        $this->patchJson("/api/v1/sppg/{$this->sppgA}", ['kode' => 'SPPG-GANTI'])->assertOk()->assertJsonPath('data.kode', 'SPPG-GANTI');
    }

    /** @return array<string, array{Role}> */
    public static function perananLain(): array
    {
        return [
            'ahli_gizi' => [Role::AhliGizi],
            'akuntan' => [Role::Akuntan],
            'staf_gudang' => [Role::StafGudang],
            'relawan' => [Role::Relawan],
        ];
    }

    #[DataProvider('perananLain')]
    public function test_peran_lain_hanya_membaca_sppg_sendiri(Role $role): void
    {
        $actor = User::withoutGlobalScopes()->where('role', $role->value)->firstOrFail();

        $this->as($actor);
        $this->getJson('/api/v1/sppg')->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.id', $this->sppgA);
        $this->getJson("/api/v1/sppg/{$this->sppgA}")->assertOk();
        $this->getJson("/api/v1/sppg/{$this->sppgB}")->assertForbidden();
        $this->patchJson("/api/v1/sppg/{$this->sppgA}", ['nama' => 'X'])->assertForbidden();
        $this->postJson('/api/v1/sppg', [])->assertForbidden();
        $this->deleteJson("/api/v1/sppg/{$this->sppgA}")->assertForbidden();
    }

    public function test_kepala_tidak_dapat_membuat_atau_menghapus(): void
    {
        $this->as($this->kepalaA);

        $this->postJson('/api/v1/sppg', $this->payload())->assertForbidden();
        $this->deleteJson("/api/v1/sppg/{$this->sppgA}")->assertForbidden();
        $this->assertDatabaseHas('sppgs', ['id' => $this->sppgA, 'deleted_at' => null]);
    }

    public function test_hapus_lunak_ditolak_bila_masih_ada_pengguna_atau_titik(): void
    {
        $this->as($this->admin);
        $this->deleteJson("/api/v1/sppg/{$this->sppgA}")->assertUnprocessable()->assertJsonValidationErrors(['sppg']);

        $kosong = $this->createSppg('SPPG Kosong');
        $this->forgetActor();
        TitikLayanan::factory()->create(['sppg_id' => $kosong]);
        $this->as($this->admin)->deleteJson("/api/v1/sppg/{$kosong}")->assertUnprocessable();

        $kosong2 = $this->createSppg('SPPG Kosong 2');
        $this->deleteJson("/api/v1/sppg/{$kosong2}")->assertNoContent();
        $this->assertSoftDeleted('sppgs', ['id' => $kosong2]);
        $this->getJson("/api/v1/sppg/{$kosong2}")->assertNotFound();
    }

    public function test_tanpa_token_401(): void
    {
        $this->getJson('/api/v1/sppg')->assertUnauthorized();
    }

    public function test_sppg_tidak_ada_404(): void
    {
        $this->as($this->admin)->getJson('/api/v1/sppg/999999')->assertNotFound();
    }

    public function test_ai_draft_id_dikonfirmasi_saat_simpan(): void
    {
        $this->forgetActor();
        $draf = AiDraft::factory()->create(['user_id' => $this->kepalaA->id, 'sppg_id' => $this->sppgA, 'module' => 'sppg', 'action' => AksiAiDraft::Update, 'target_id' => $this->sppgA]);

        $this->as($this->kepalaA)->patchJson("/api/v1/sppg/{$this->sppgA}", ['kapasitas_porsi_harian' => 2800, 'ai_draft_id' => $draf->uuid])->assertOk();

        $this->assertDatabaseHas('ai_drafts', ['id' => $draf->id, 'status' => 'confirmed']);
    }
}
