<?php

namespace Tests\Feature\Master;

use App\Enums\Role;
use App\Models\Invitation;
use App\Models\User;
use PHPUnit\Framework\Attributes\DataProvider;

class UndanganApiTest extends MasterApiTestCase
{
    private function akunTertunda(): User
    {
        $this->forgetActor();

        return User::factory()->role(Role::Akuntan)->withoutTelegram()->create(['sppg_id' => $this->sppgA]);
    }

    public function test_admin_menerbitkan_undangan_untuk_akun_yang_ada(): void
    {
        $akun = $this->akunTertunda();

        $res = $this->as($this->admin)->postJson('/api/v1/pengguna/undangan', ['user_id' => $akun->id])
            ->assertCreated()
            ->assertHeader('Cache-Control')
            ->assertJsonPath('data.tipe', 'pengguna')
            ->assertJsonPath('data.status', 'aktif')
            ->assertJsonPath('data.user_id', $akun->id)
            ->assertJsonPath('data.role', 'akuntan');

        $token = $res->json('data.token');
        $this->assertSame(43, strlen($token));
        $this->assertMatchesRegularExpression('/^[A-Za-z0-9_-]{43}$/', $token);
        $this->assertSame("https://t.me/sigap_test_bot?start=inv_{$token}", $res->json('data.deep_link'));
        $this->assertStringContainsString('no-store', (string) $res->headers->get('Cache-Control'));

        // Hanya hash yang disimpan; token mentah tidak ada di DB.
        $row = Invitation::query()->findOrFail($res->json('data.id'));
        $this->assertSame(hash('sha256', $token), $row->token_hash);
        $this->assertDatabaseMissing('invitations', ['token_hash' => $token]);
        // Kedaluwarsa 72 jam.
        $this->assertEqualsWithDelta(72 * 3600, now()->diffInSeconds($row->expires_at, true), 5);
        $this->assertSame($this->admin->id, $row->created_by);
    }

    public function test_token_mentah_tidak_pernah_tampil_di_daftar_atau_ulang(): void
    {
        $akun = $this->akunTertunda();
        $this->as($this->admin)->postJson('/api/v1/pengguna/undangan', ['user_id' => $akun->id])->assertCreated();

        $list = $this->getJson('/api/v1/pengguna/undangan')->assertOk();
        $list->assertJsonMissingPath('data.0.token')->assertJsonMissingPath('data.0.token_hash')->assertJsonMissingPath('data.0.deep_link');
        $this->assertStringNotContainsString('token', strtolower(json_encode($list->json('data.0'))));
    }

    public function test_undangan_akun_baru_dengan_peran(): void
    {
        $this->as($this->admin)->postJson('/api/v1/pengguna/undangan', ['role' => 'relawan', 'unit' => 'distribusi', 'sppg_id' => $this->sppgA])
            ->assertCreated()
            ->assertJsonPath('data.role', 'relawan')
            ->assertJsonPath('data.unit', 'distribusi')
            ->assertJsonPath('data.sppg_id', $this->sppgA)
            ->assertJsonPath('data.user_id', null);
    }

    public function test_validasi_undangan(): void
    {
        $akun = $this->akunTertunda();
        $this->as($this->admin);

        $this->postJson('/api/v1/pengguna/undangan', [])->assertUnprocessable()->assertJsonValidationErrors(['user_id', 'role']);
        $this->postJson('/api/v1/pengguna/undangan', ['user_id' => 999999])->assertUnprocessable()->assertJsonValidationErrors(['user_id']);
        $this->postJson('/api/v1/pengguna/undangan', ['user_id' => $akun->id, 'role' => 'admin'])->assertUnprocessable();
        $this->postJson('/api/v1/pengguna/undangan', ['role' => 'relawan', 'sppg_id' => $this->sppgA])->assertUnprocessable()->assertJsonValidationErrors(['unit']);
        $this->postJson('/api/v1/pengguna/undangan', ['role' => 'akuntan'])->assertUnprocessable()->assertJsonValidationErrors(['sppg_id']);
        // Akun yang sudah terhubung Telegram tidak dapat diundang lagi.
        $this->postJson('/api/v1/pengguna/undangan', ['user_id' => $this->kepalaA->id])->assertUnprocessable()->assertJsonValidationErrors(['user_id']);
    }

    public function test_undangan_baru_mencabut_undangan_aktif_sebelumnya_untuk_akun_sama(): void
    {
        $akun = $this->akunTertunda();
        $this->as($this->admin);

        $a = $this->postJson('/api/v1/pengguna/undangan', ['user_id' => $akun->id])->json('data.id');
        $b = $this->postJson('/api/v1/pengguna/undangan', ['user_id' => $akun->id])->json('data.id');

        $this->assertNotNull(Invitation::find($a)->revoked_at);
        $this->assertNull(Invitation::find($b)->revoked_at);
    }

    public function test_daftar_undangan_filter_status_dan_paginasi(): void
    {
        $akun = $this->akunTertunda();
        $this->as($this->admin);
        $aktif = $this->postJson('/api/v1/pengguna/undangan', ['user_id' => $akun->id])->json('data.id');
        $this->forgetActor();
        $kedaluwarsa = Invitation::factory()->kedaluwarsa()->create(['created_by' => $this->admin->id, 'sppg_id' => $this->sppgA]);
        $terpakai = Invitation::factory()->terpakai()->create(['created_by' => $this->admin->id, 'sppg_id' => $this->sppgA]);
        $dicabut = Invitation::factory()->create(['created_by' => $this->admin->id, 'sppg_id' => $this->sppgA, 'revoked_at' => now()]);

        $this->as($this->admin);
        $this->getJson('/api/v1/pengguna/undangan')->assertOk()->assertJsonPath('meta.total', 4);
        $this->getJson('/api/v1/pengguna/undangan?per_page=2')->assertOk()->assertJsonCount(2, 'data');
        $this->getJson('/api/v1/pengguna/undangan?status=aktif')->assertOk()->assertJsonPath('meta.total', 1)->assertJsonPath('data.0.id', $aktif);
        $this->getJson('/api/v1/pengguna/undangan?status=kedaluwarsa')->assertOk()->assertJsonPath('data.0.id', $kedaluwarsa->id)->assertJsonPath('data.0.status', 'kedaluwarsa');
        $this->getJson('/api/v1/pengguna/undangan?status=terpakai')->assertOk()->assertJsonPath('data.0.id', $terpakai->id);
        $this->getJson('/api/v1/pengguna/undangan?status=dicabut')->assertOk()->assertJsonPath('data.0.id', $dicabut->id);
    }

    public function test_cabut_undangan(): void
    {
        $akun = $this->akunTertunda();
        $this->as($this->admin);
        $res = $this->postJson('/api/v1/pengguna/undangan', ['user_id' => $akun->id]);
        $id = $res->json('data.id');

        $this->deleteJson("/api/v1/pengguna/undangan/{$id}")->assertNoContent();
        $this->assertNotNull(Invitation::find($id)->revoked_at);

        // Mencabut ulang tidak error; undangan dicabut tidak dapat dipakai.
        $this->deleteJson("/api/v1/pengguna/undangan/{$id}")->assertNoContent();
        $this->getJson('/api/v1/pengguna/undangan?status=dicabut')->assertJsonPath('data.0.status', 'dicabut');
    }

    public function test_undangan_terpakai_tidak_dapat_dicabut(): void
    {
        $this->forgetActor();
        $terpakai = Invitation::factory()->terpakai()->create(['created_by' => $this->admin->id, 'sppg_id' => $this->sppgA]);

        $this->as($this->admin)->deleteJson("/api/v1/pengguna/undangan/{$terpakai->id}")
            ->assertUnprocessable()->assertJsonValidationErrors(['undangan']);
        $this->assertNull($terpakai->fresh()->revoked_at);
    }

    public function test_cabut_undangan_tidak_ada_404(): void
    {
        $this->as($this->admin)->deleteJson('/api/v1/pengguna/undangan/987654')->assertNotFound();
    }

    /** @return array<string, array{Role}> */
    public static function perananNonAdmin(): array
    {
        return [
            'kepala_sppg' => [Role::KepalaSppg],
            'ahli_gizi' => [Role::AhliGizi],
            'relawan' => [Role::Relawan],
        ];
    }

    #[DataProvider('perananNonAdmin')]
    public function test_non_admin_tidak_dapat_mengelola_undangan(Role $role): void
    {
        $akun = $this->akunTertunda();
        $inv = Invitation::factory()->create(['created_by' => $this->admin->id, 'sppg_id' => $this->sppgA]);
        $actor = User::withoutGlobalScopes()->where('role', $role->value)->where('sppg_id', $this->sppgA)->firstOrFail();

        $this->as($actor);
        $this->postJson('/api/v1/pengguna/undangan', ['user_id' => $akun->id])->assertForbidden();
        $this->getJson('/api/v1/pengguna/undangan')->assertForbidden();
        $this->deleteJson("/api/v1/pengguna/undangan/{$inv->id}")->assertForbidden();
        $this->assertNull($inv->fresh()->revoked_at);
    }
}
