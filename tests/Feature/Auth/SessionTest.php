<?php

namespace Tests\Feature\Auth;

use App\Enums\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\CreatesSppg;
use Tests\TestCase;

class SessionTest extends TestCase
{
    use CreatesSppg, RefreshDatabase;

    private function tokenFor(User $user, ?\DateTimeInterface $expiresAt = null): string
    {
        return $user->createToken('miniapp:miniapp', ['role:'.$user->role->value], $expiresAt ?? now()->addHours(8))->plainTextToken;
    }

    private function freshAuth(): void
    {
        $this->app['auth']->forgetGuards();
    }

    public function test_me_tanpa_token_401_json(): void
    {
        $this->getJson('/api/v1/auth/me')
            ->assertUnauthorized()
            ->assertExactJson(['message' => 'Tidak terautentikasi. Silakan buka ulang Mini App.']);
    }

    public function test_me_tanpa_header_accept_json_tetap_json_401(): void
    {
        $this->get('/api/v1/auth/me')->assertUnauthorized()->assertJsonStructure(['message']);
    }

    public function test_me_mengembalikan_profil_dan_hak_akses(): void
    {
        $user = User::factory()->role(Role::Akuntan)->create(['sppg_id' => $this->createSppg('SPPG Mawar')]);

        $this->withToken($this->tokenFor($user))->getJson('/api/v1/auth/me')
            ->assertOk()
            ->assertHeader('Cache-Control', 'no-store, private')
            ->assertJsonPath('user.role', 'akuntan')
            ->assertJsonPath('user.role_label', 'Akuntan')
            ->assertJsonPath('user.sppg_name', 'SPPG Mawar')
            ->assertJsonPath('user.permissions.keuangan', 'CRUD')
            ->assertJsonPath('user.permissions.pengguna', 'R*')
            ->assertJsonMissingPath('user.permissions.menu-gizi');
    }

    public function test_logout_mencabut_token_saat_ini_saja(): void
    {
        $user = User::factory()->create(['sppg_id' => $this->createSppg()]);
        $token = $this->tokenFor($user);
        $other = $user->createToken('miniapp:hp-lain')->plainTextToken;

        $this->withToken($token)->postJson('/api/v1/auth/logout')->assertOk()->assertJsonPath('message', 'Berhasil keluar.');

        $this->freshAuth();
        $this->withToken($token)->getJson('/api/v1/auth/me')->assertUnauthorized();
        $this->freshAuth();
        $this->withToken($other)->getJson('/api/v1/auth/me')->assertOk();
    }

    public function test_token_kedaluwarsa_ditolak(): void
    {
        $user = User::factory()->create(['sppg_id' => $this->createSppg()]);
        $token = $this->tokenFor($user, now()->addMinutes(10));

        $this->travel(11)->minutes();

        $this->withToken($token)->getJson('/api/v1/auth/me')->assertUnauthorized();
    }

    public function test_token_lebih_dari_480_menit_ditolak_meski_expires_at_kosong(): void
    {
        $user = User::factory()->create(['sppg_id' => $this->createSppg()]);
        $token = $user->createToken('lama')->plainTextToken;

        $this->travel(481)->minutes();

        $this->withToken($token)->getJson('/api/v1/auth/me')->assertUnauthorized();
    }

    public function test_pengguna_dinonaktifkan_setelah_login_kehilangan_akses(): void
    {
        $user = User::factory()->create(['sppg_id' => $this->createSppg()]);
        $token = $this->tokenFor($user);

        // Ubah langsung di DB (tanpa event) untuk menguji middleware 'active'.
        User::withoutGlobalScopes()->whereKey($user->id)->update(['is_active' => false]);

        $this->withToken($token)->getJson('/api/v1/auth/me')
            ->assertForbidden()->assertJsonPath('message', 'Akun Anda nonaktif. Hubungi Admin SPPG.');
        $this->assertSame(0, $user->tokens()->count());
    }

    public function test_perubahan_peran_atau_sppg_mencabut_semua_token(): void
    {
        $user = User::factory()->create(['sppg_id' => $this->createSppg()]);
        $token = $this->tokenFor($user);
        $user->createToken('lain');

        $user->update(['name' => 'Nama Baru']);
        $this->assertSame(2, $user->tokens()->count());

        $user->update(['role' => Role::Relawan]);
        $this->assertSame(0, $user->tokens()->count());

        $this->withToken($token)->getJson('/api/v1/auth/me')->assertUnauthorized();
    }

    public function test_pengguna_dihapus_lunak_tokennya_tidak_berlaku(): void
    {
        $user = User::factory()->create(['sppg_id' => $this->createSppg()]);
        $token = $this->tokenFor($user);

        $user->delete();

        $this->withToken($token)->getJson('/api/v1/auth/me')->assertUnauthorized();
    }

    public function test_rate_limit_api_60_per_menit_per_pengguna(): void
    {
        $user = User::factory()->create(['sppg_id' => $this->createSppg()]);
        $token = $this->tokenFor($user);

        for ($i = 0; $i < 60; $i++) {
            $this->freshAuth();
            $this->withToken($token)->getJson('/api/v1/auth/me')->assertOk();
        }

        $this->freshAuth();
        $this->withToken($token)->getJson('/api/v1/auth/me')->assertStatus(429);
    }
}
