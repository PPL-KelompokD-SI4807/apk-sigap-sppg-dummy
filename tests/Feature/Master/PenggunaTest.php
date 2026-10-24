<?php

namespace Tests\Feature\Master;

use App\Enums\Role;
use App\Models\AiDraft;
use App\Models\Invitation;
use App\Models\User;
use App\Services\Users\UserAccountService;
use Illuminate\Validation\ValidationException;
use PHPUnit\Framework\Attributes\DataProvider;

class PenggunaTest extends MasterApiTestCase
{
    /** @return array<string, mixed> */
    private function payload(array $override = []): array
    {
        return array_merge([
            'name' => 'Siti Rahayu',
            'role' => 'relawan',
            'unit' => 'dapur',
            'sppg_id' => $this->sppgA,
            'phone' => '0812-0000-1234',
        ], $override);
    }

    public function test_admin_membuat_pengguna_tanpa_telegram(): void
    {
        $this->as($this->admin)->postJson('/api/v1/pengguna', $this->payload())
            ->assertCreated()
            ->assertJsonPath('data.name', 'Siti Rahayu')
            ->assertJsonPath('data.role', 'relawan')
            ->assertJsonPath('data.unit', 'dapur')
            ->assertJsonPath('data.status', 'menunggu_telegram')
            ->assertJsonPath('data.telegram_terhubung', false)
            ->assertJsonMissingPath('data.telegram_id');

        $this->assertDatabaseHas('users', ['name' => 'Siti Rahayu', 'sppg_id' => $this->sppgA, 'telegram_id' => null, 'is_active' => true]);
        $this->assertDatabaseHas('audit_logs', ['auditable_type' => (new User)->getMorphClass(), 'event' => 'created', 'user_id' => $this->admin->id]);
    }

    public function test_telegram_id_dari_body_diabaikan(): void
    {
        $this->as($this->admin)->postJson('/api/v1/pengguna', $this->payload(['telegram_id' => 999111222]))->assertCreated();

        $this->assertDatabaseMissing('users', ['telegram_id' => 999111222]);
    }

    public function test_admin_membuat_admin_lain_tanpa_sppg(): void
    {
        $this->as($this->admin)->postJson('/api/v1/pengguna', ['name' => 'Admin Dua', 'role' => 'admin'])
            ->assertCreated()
            ->assertJsonPath('data.sppg_id', null);
    }

    public function test_validasi_gagal(): void
    {
        $this->as($this->admin);

        $this->postJson('/api/v1/pengguna', [])->assertUnprocessable()->assertJsonValidationErrors(['name', 'role']);
        $this->postJson('/api/v1/pengguna', $this->payload(['role' => 'superuser']))->assertUnprocessable()->assertJsonValidationErrors(['role']);
        $this->postJson('/api/v1/pengguna', $this->payload(['unit' => null]))->assertUnprocessable()->assertJsonValidationErrors(['unit']);
        $this->postJson('/api/v1/pengguna', $this->payload(['role' => 'akuntan', 'unit' => 'dapur']))->assertUnprocessable()->assertJsonValidationErrors(['unit']);
        $this->postJson('/api/v1/pengguna', $this->payload(['sppg_id' => null]))->assertUnprocessable()->assertJsonValidationErrors(['sppg_id']);
        $this->postJson('/api/v1/pengguna', $this->payload(['sppg_id' => 99999]))->assertUnprocessable()->assertJsonValidationErrors(['sppg_id']);
        $this->postJson('/api/v1/pengguna', ['name' => 'X', 'role' => 'admin', 'sppg_id' => $this->sppgA])->assertUnprocessable()->assertJsonValidationErrors(['sppg_id']);
        $this->postJson('/api/v1/pengguna', $this->payload(['phone' => 'abc']))->assertUnprocessable()->assertJsonValidationErrors(['phone']);
    }

    /** @return array<string, array{Role}> */
    public static function perananTanpaHakTulis(): array
    {
        return [
            'kepala_sppg' => [Role::KepalaSppg],
            'ahli_gizi' => [Role::AhliGizi],
            'akuntan' => [Role::Akuntan],
            'staf_gudang' => [Role::StafGudang],
            'relawan' => [Role::Relawan],
        ];
    }

    #[DataProvider('perananTanpaHakTulis')]
    public function test_non_admin_tidak_boleh_membuat_mengubah_menghapus(Role $role): void
    {
        $actor = User::withoutGlobalScopes()->where('role', $role->value)->firstOrFail();
        $target = $this->relawanA;

        $this->as($actor);
        // 403 harus lebih dulu daripada 422 (payload sengaja kosong).
        $this->postJson('/api/v1/pengguna', [])->assertForbidden();
        $this->patchJson("/api/v1/pengguna/{$target->id}", ['name' => 'Baru'])->assertForbidden();
        $this->deleteJson("/api/v1/pengguna/{$target->id}")->assertForbidden();
        $this->postJson('/api/v1/pengguna/undangan', ['user_id' => $target->id])->assertForbidden();
        $this->getJson('/api/v1/pengguna/undangan')->assertForbidden();
    }

    public function test_admin_melihat_daftar_lintas_sppg_dengan_filter_dan_paginasi(): void
    {
        $this->as($this->admin)->getJson('/api/v1/pengguna?per_page=2')
            ->assertOk()
            ->assertJsonCount(2, 'data')
            ->assertJsonStructure(['data', 'links', 'meta'])
            ->assertJsonPath('meta.total', 7);

        $this->getJson('/api/v1/pengguna?role=kepala_sppg')->assertOk()->assertJsonPath('meta.total', 2);
        $this->getJson('/api/v1/pengguna?role=kepala_sppg&sppg_id='.$this->sppgB)->assertOk()->assertJsonPath('meta.total', 1);
        $this->getJson('/api/v1/pengguna?q='.urlencode($this->akuntanA->name))->assertOk()->assertJsonPath('data.0.id', $this->akuntanA->id);
        $this->getJson('/api/v1/pengguna?role=bukan_peran')->assertOk()->assertJsonPath('meta.total', 7);
    }

    public function test_filter_status(): void
    {
        $this->forgetActor();
        $tertunda = User::factory()->role(Role::Akuntan)->withoutTelegram()->create(['sppg_id' => $this->sppgA]);
        $mati = User::factory()->role(Role::Akuntan)->inactive()->create(['sppg_id' => $this->sppgA]);

        $this->as($this->admin);
        $this->getJson('/api/v1/pengguna?status=menunggu_telegram')->assertOk()->assertJsonPath('meta.total', 1)->assertJsonPath('data.0.id', $tertunda->id);
        $this->getJson('/api/v1/pengguna?status=nonaktif')->assertOk()->assertJsonPath('meta.total', 1)->assertJsonPath('data.0.id', $mati->id);
        $this->getJson('/api/v1/pengguna?status=aktif')->assertOk()->assertJsonPath('meta.total', 7);
    }

    public function test_kepala_sppg_hanya_melihat_pengguna_sppg_sendiri(): void
    {
        $this->as($this->kepalaA)->getJson('/api/v1/pengguna')
            ->assertOk()
            ->assertJsonPath('meta.total', 5);

        $this->getJson("/api/v1/pengguna/{$this->akuntanA->id}")->assertOk();
        $this->getJson("/api/v1/pengguna/{$this->kepalaB->id}")->assertNotFound();
        $this->getJson("/api/v1/pengguna/{$this->admin->id}")->assertNotFound();
    }

    #[DataProvider('perananProfilSendiri')]
    public function test_peran_r_bintang_hanya_melihat_profil_sendiri(Role $role): void
    {
        $self = User::withoutGlobalScopes()->where('role', $role->value)->firstOrFail();

        $this->as($self)->getJson('/api/v1/pengguna')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', $self->id);

        $this->getJson("/api/v1/pengguna/{$self->id}")->assertOk()->assertJsonPath('data.id', $self->id);
        // Rekan satu SPPG: ditolak (403), SPPG lain: tidak terlihat (404).
        $this->getJson("/api/v1/pengguna/{$this->kepalaA->id}")->assertForbidden();
        $this->getJson("/api/v1/pengguna/{$this->kepalaB->id}")->assertNotFound();
    }

    /** @return array<string, array{Role}> */
    public static function perananProfilSendiri(): array
    {
        return [
            'ahli_gizi' => [Role::AhliGizi],
            'akuntan' => [Role::Akuntan],
            'staf_gudang' => [Role::StafGudang],
            'relawan' => [Role::Relawan],
        ];
    }

    public function test_admin_mengubah_pengguna_dan_token_dicabut(): void
    {
        $this->forgetActor();
        $target = $this->relawanA;
        $target->createToken('miniapp:hp');

        $this->as($this->admin)->patchJson("/api/v1/pengguna/{$target->id}", ['role' => 'akuntan', 'unit' => null])
            ->assertOk()
            ->assertJsonPath('data.role', 'akuntan')
            ->assertJsonPath('data.unit', null);

        $this->assertSame(0, $target->tokens()->count());
        $this->assertDatabaseHas('audit_logs', ['auditable_id' => $target->id, 'event' => 'updated']);
    }

    public function test_ubah_peran_menjadi_relawan_tanpa_unit_ditolak(): void
    {
        $this->as($this->admin)->patchJson("/api/v1/pengguna/{$this->akuntanA->id}", ['role' => 'relawan'])
            ->assertUnprocessable()->assertJsonValidationErrors(['unit']);
    }

    public function test_ubah_menjadi_admin_mengosongkan_sppg(): void
    {
        $this->as($this->admin)->patchJson("/api/v1/pengguna/{$this->akuntanA->id}", ['role' => 'admin'])
            ->assertOk()->assertJsonPath('data.sppg_id', null);
    }

    public function test_admin_tidak_dapat_menonaktifkan_atau_menghapus_diri_sendiri(): void
    {
        $this->forgetActor();
        $adminLain = User::factory()->admin()->create();

        $this->as($this->admin);
        $this->patchJson("/api/v1/pengguna/{$this->admin->id}", ['is_active' => false])
            ->assertUnprocessable()->assertJsonValidationErrors(['user']);
        $this->patchJson("/api/v1/pengguna/{$this->admin->id}", ['role' => 'kepala_sppg', 'sppg_id' => $this->sppgA])
            ->assertUnprocessable()->assertJsonValidationErrors(['user']);
        $this->deleteJson("/api/v1/pengguna/{$this->admin->id}")
            ->assertUnprocessable()->assertJsonValidationErrors(['user']);

        $this->assertDatabaseHas('users', ['id' => $this->admin->id, 'is_active' => true, 'deleted_at' => null]);

        // Admin lain tetap boleh dikelola selama masih ada admin aktif.
        $this->patchJson("/api/v1/pengguna/{$adminLain->id}", ['is_active' => false])->assertOk();
    }

    public function test_admin_aktif_terakhir_tidak_dapat_dihilangkan(): void
    {
        $this->forgetActor();
        $adminLain = User::factory()->admin()->create();

        // Admin pertama dinonaktifkan oleh admin kedua; setelah itu admin kedua adalah satu-satunya.
        $this->as($adminLain)->patchJson("/api/v1/pengguna/{$this->admin->id}", ['is_active' => false])->assertOk();

        $service = app(UserAccountService::class);
        $this->forgetActor();
        $penyerang = User::factory()->role(Role::KepalaSppg)->create(['sppg_id' => $this->sppgA]);

        foreach ([
            fn () => $service->update($penyerang, $adminLain->refresh(), ['is_active' => false]),
            fn () => $service->update($penyerang, $adminLain, ['role' => 'kepala_sppg', 'sppg_id' => $this->sppgA]),
            fn () => $service->delete($penyerang, $adminLain),
        ] as $aksi) {
            try {
                $aksi();
                $this->fail('Admin terakhir seharusnya dilindungi.');
            } catch (ValidationException $e) {
                $this->assertArrayHasKey('user', $e->errors());
            }
        }

        $this->assertDatabaseHas('users', ['id' => $adminLain->id, 'is_active' => true, 'role' => 'admin', 'deleted_at' => null]);
    }

    public function test_hapus_lunak_pengguna_dan_pengguna_hilang_dari_daftar(): void
    {
        $this->forgetActor();
        $target = $this->relawanA;

        $this->as($this->admin)->deleteJson("/api/v1/pengguna/{$target->id}")->assertNoContent();

        $this->assertSoftDeleted('users', ['id' => $target->id]);
        $this->getJson("/api/v1/pengguna/{$target->id}")->assertNotFound();
        $this->getJson('/api/v1/pengguna')->assertJsonMissing(['id' => $target->id]);
        $this->assertDatabaseHas('audit_logs', ['auditable_id' => $target->id, 'event' => 'deleted']);
    }

    public function test_menghapus_pengguna_mencabut_undangan_aktifnya(): void
    {
        $this->forgetActor();
        $target = User::factory()->role(Role::Akuntan)->withoutTelegram()->create(['sppg_id' => $this->sppgA]);

        $this->as($this->admin);
        $id = $this->postJson('/api/v1/pengguna/undangan', ['user_id' => $target->id])->assertCreated()->json('data.id');
        $this->deleteJson("/api/v1/pengguna/{$target->id}")->assertNoContent();

        $this->assertNotNull(Invitation::find($id)->revoked_at);
    }

    public function test_pengguna_nonaktif_tidak_dapat_memakai_api(): void
    {
        $this->forgetActor();
        $mati = User::factory()->role(Role::KepalaSppg)->inactive()->create(['sppg_id' => $this->sppgA]);

        $this->as($mati)->getJson('/api/v1/pengguna')->assertForbidden();
    }

    public function test_tanpa_token_401(): void
    {
        $this->getJson('/api/v1/pengguna')->assertUnauthorized();
        $this->postJson('/api/v1/pengguna/undangan', ['user_id' => 1])->assertUnauthorized();
    }

    public function test_menerima_ai_draft_id_dan_menandai_confirmed(): void
    {
        $this->forgetActor();
        $draf = AiDraft::factory()->create([
            'user_id' => $this->admin->id,
            'sppg_id' => $this->sppgA,
            'module' => 'pengguna',
        ]);

        $this->as($this->admin)->postJson('/api/v1/pengguna', $this->payload(['ai_draft_id' => $draf->uuid]))->assertCreated();

        $this->assertDatabaseHas('ai_drafts', ['id' => $draf->id, 'status' => 'confirmed']);
    }

    public function test_ai_draft_id_milik_orang_lain_membatalkan_aksi(): void
    {
        $this->forgetActor();
        $draf = AiDraft::factory()->create(['user_id' => $this->kepalaA->id, 'sppg_id' => $this->sppgA, 'module' => 'pengguna']);

        $this->as($this->admin)->postJson('/api/v1/pengguna', $this->payload(['ai_draft_id' => $draf->uuid]))
            ->assertUnprocessable()->assertJsonValidationErrors(['ai_draft_id']);

        $this->assertDatabaseMissing('users', ['name' => 'Siti Rahayu']);
        $this->assertDatabaseHas('ai_drafts', ['id' => $draf->id, 'status' => 'pending']);
    }
}
