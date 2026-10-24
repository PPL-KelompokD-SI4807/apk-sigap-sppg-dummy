<?php

namespace Tests\Feature\Auth;

use App\Enums\RelawanUnit;
use App\Enums\Role;
use App\Models\User;
use App\Services\Telegram\TelegramAuthService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\RateLimiter;
use Laravel\Sanctum\PersonalAccessToken;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Support\BuildsTelegramInitData;
use Tests\Support\CreatesSppg;
use Tests\TestCase;

class TelegramLoginTest extends TestCase
{
    use BuildsTelegramInitData, CreatesSppg, RefreshDatabase;

    private const URL = '/api/v1/auth/telegram';

    private function registeredUser(array $attributes = []): User
    {
        return User::factory()->role(Role::KepalaSppg)->create(array_merge([
            'sppg_id' => $this->createSppg('SPPG Sukamaju'),
            'telegram_id' => 7012345678,
        ], $attributes));
    }

    /** Lupakan user yang di-cache guard antar-permintaan dalam satu tes. */
    private function freshAuth(): void
    {
        $this->app['auth']->forgetGuards();
    }

    public function test_initdata_sah_menerbitkan_token_dan_profil(): void
    {
        $this->freezeSecond();
        $user = $this->registeredUser();

        $response = $this->postJson(self::URL, ['init_data' => $this->makeInitData(['id' => 7012345678, 'username' => 'kepala_baru'])]);

        $response->assertOk()
            ->assertJsonPath('token_type', 'Bearer')
            ->assertJsonPath('expires_at', now()->addMinutes(480)->toIso8601String())
            ->assertJsonPath('user.id', $user->id)
            ->assertJsonPath('user.role', 'kepala_sppg')
            ->assertJsonPath('user.unit', null)
            ->assertJsonPath('user.sppg_id', $user->sppg_id)
            ->assertJsonPath('user.sppg_name', 'SPPG Sukamaju')
            ->assertJsonPath('user.permissions.jadwal', 'CRUD')
            ->assertJsonStructure(['token', 'token_type', 'expires_at', 'user' => ['id', 'name', 'role', 'unit', 'sppg_id', 'sppg_name', 'modules']])
            ->assertHeader('Cache-Control', 'no-store, private')
            ->assertHeader('X-Content-Type-Options', 'nosniff');

        $this->assertContains('keuangan', $response->json('user.modules'));
        $this->assertNotContains('menu-gizi:C', PersonalAccessToken::first()->abilities);
        $this->assertContains('role:kepala_sppg', PersonalAccessToken::first()->abilities);

        $user->refresh();
        $this->assertNotNull($user->last_login_at);
        $this->assertSame('kepala_baru', $user->telegram_username);

        $this->freshAuth();
        $this->withToken($response->json('token'))->getJson('/api/v1/auth/me')
            ->assertOk()->assertJsonPath('user.id', $user->id);
    }

    public function test_login_ulang_perangkat_sama_mencabut_token_lama_perangkat_lain_tetap(): void
    {
        $user = $this->registeredUser();
        $initData = $this->makeInitData(['id' => 7012345678]);

        $first = $this->postJson(self::URL, ['init_data' => $initData])->json('token');
        $other = $this->postJson(self::URL, ['init_data' => $initData, 'device' => 'tablet-dapur'])->json('token');
        $second = $this->postJson(self::URL, ['init_data' => $initData])->json('token');

        $this->assertSame(2, $user->tokens()->count());

        $this->freshAuth();
        $this->withToken($first)->getJson('/api/v1/auth/me')->assertUnauthorized();
        $this->freshAuth();
        $this->withToken($second)->getJson('/api/v1/auth/me')->assertOk();
        $this->freshAuth();
        $this->withToken($other)->getJson('/api/v1/auth/me')->assertOk();
    }

    public function test_hash_dimanipulasi_ditolak_401(): void
    {
        $this->registeredUser();
        $fields = $this->initDataFields(['id' => 7012345678]);
        $fields['auth_date'] = (string) (time() - 5);

        $this->postJson(self::URL, ['init_data' => $this->encodeInitData($fields)])
            ->assertUnauthorized()
            ->assertExactJson(['message' => 'Tanda tangan initData tidak valid.']);

        $this->assertSame(0, PersonalAccessToken::count());
    }

    public function test_user_id_diganti_ke_pengguna_lain_ditolak(): void
    {
        $this->registeredUser();
        $fields = $this->initDataFields(['id' => 1111]);
        $fields['user'] = str_replace('"id":1111', '"id":7012345678', $fields['user']);

        $this->postJson(self::URL, ['init_data' => $this->encodeInitData($fields)])->assertUnauthorized();
    }

    public function test_initdata_kedaluwarsa_ditolak_401(): void
    {
        $this->registeredUser();
        config(['telegram.init_data_max_age' => 3600]);

        $this->postJson(self::URL, ['init_data' => $this->makeInitData(['id' => 7012345678], time() - 3700)])
            ->assertUnauthorized()
            ->assertJsonPath('message', 'initData kedaluwarsa. Tutup lalu buka ulang Mini App.');
    }

    public function test_auth_date_masa_depan_ditolak_401(): void
    {
        $this->registeredUser();

        $this->postJson(self::URL, ['init_data' => $this->makeInitData(['id' => 7012345678], time() + 3600)])
            ->assertUnauthorized();
    }

    public function test_telegram_id_tidak_terdaftar_403_dengan_tautan_undangan(): void
    {
        $this->postJson(self::URL, ['init_data' => $this->makeInitData(['id' => 5550001])])
            ->assertForbidden()
            ->assertJsonPath('message', fn (string $m) => str_contains($m, 't.me/sigap_test_bot?start=') && str_contains($m, 'belum terdaftar'));
    }

    public function test_pengguna_nonaktif_403(): void
    {
        $this->registeredUser(['is_active' => false]);

        $this->postJson(self::URL, ['init_data' => $this->makeInitData(['id' => 7012345678])])
            ->assertForbidden()
            ->assertJsonPath('message', 'Akun Anda nonaktif. Hubungi Admin SPPG.');
    }

    public function test_pengguna_terhapus_lunak_403(): void
    {
        $this->registeredUser()->delete();

        $this->postJson(self::URL, ['init_data' => $this->makeInitData(['id' => 7012345678])])->assertForbidden();
        $this->assertSame(0, PersonalAccessToken::count());
    }

    public function test_init_data_wajib_422(): void
    {
        $this->postJson(self::URL, [])
            ->assertUnprocessable()
            ->assertJsonPath('message', 'Data yang dikirim tidak valid.')
            ->assertJsonValidationErrors(['init_data']);
    }

    public function test_device_tidak_valid_422(): void
    {
        $this->postJson(self::URL, ['init_data' => 'x', 'device' => '../etc'])
            ->assertUnprocessable()->assertJsonValidationErrors(['device']);
    }

    public function test_token_bot_belum_diatur_menolak_semua(): void
    {
        $this->registeredUser();
        $initData = $this->makeInitData(['id' => 7012345678]);
        config(['telegram.bot_token' => '']);

        $this->postJson(self::URL, ['init_data' => $initData])->assertUnauthorized();
    }

    // ---- Dev bypass ----

    public function test_dev_bypass_mati_secara_default_di_testing(): void
    {
        $this->postJson(self::URL, ['init_data' => 'dev_role=kepala_sppg'])->assertUnauthorized();
        $this->assertSame(0, User::count());
    }

    public function test_dev_bypass_aktif_di_local_bila_diaktifkan(): void
    {
        $this->app['env'] = 'local';
        config(['telegram.dev_bypass' => true]);

        $response = $this->postJson(self::URL, ['init_data' => 'dev_role=kepala_sppg'])
            ->assertOk()
            ->assertJsonPath('user.role', 'kepala_sppg');

        $this->assertNotNull($response->json('user.sppg_id'));
        $this->assertNotNull($response->json('user.sppg_name'));

        // Pemanggilan kedua memakai pengguna demo yang sama.
        $this->postJson(self::URL, ['init_data' => 'dev_role=kepala_sppg'])->assertJsonPath('user.id', $response->json('user.id'));
        $this->postJson(self::URL, ['init_data' => 'dev_role=relawan&dev_unit=distribusi'])
            ->assertOk()->assertJsonPath('user.unit', 'distribusi');
        $this->postJson(self::URL, ['init_data' => 'dev_role=admin'])
            ->assertOk()->assertJsonPath('user.sppg_id', null);
    }

    public function test_dev_bypass_peran_tidak_dikenal_ditolak(): void
    {
        $this->app['env'] = 'local';
        config(['telegram.dev_bypass' => true]);

        $this->postJson(self::URL, ['init_data' => 'dev_role=superuser'])->assertUnauthorized();
    }

    #[DataProvider('lingkunganTerlarang')]
    public function test_dev_bypass_mustahil_di_production_dan_staging_meski_flag_true(string $env): void
    {
        $this->app['env'] = $env;
        config(['telegram.dev_bypass' => true]);

        $this->assertFalse(app(TelegramAuthService::class)->devBypassAllowed());
        $this->postJson(self::URL, ['init_data' => 'dev_role=admin'])->assertUnauthorized();
        $this->postJson(self::URL, ['init_data' => 'dev_role=kepala_sppg'])->assertUnauthorized();
        $this->assertSame(0, User::count());
        $this->assertSame(0, PersonalAccessToken::count());
    }

    /** @return array<string, array{string}> */
    public static function lingkunganTerlarang(): array
    {
        return ['production' => ['production'], 'staging' => ['staging']];
    }

    // ---- Rate limit ----

    public function test_rate_limit_10_per_menit_per_ip(): void
    {
        RateLimiter::clear('auth-telegram');

        for ($i = 0; $i < 10; $i++) {
            $this->postJson(self::URL, ['init_data' => 'x'])->assertUnauthorized();
        }

        $this->postJson(self::URL, ['init_data' => 'x'])
            ->assertStatus(429)
            ->assertHeader('Retry-After')
            ->assertExactJson(['message' => 'Terlalu banyak permintaan. Coba lagi sebentar lagi.']);

        // IP lain tidak terdampak.
        $this->withServerVariables(['REMOTE_ADDR' => '10.0.0.9'])
            ->postJson(self::URL, ['init_data' => 'x'])->assertUnauthorized();
    }

    public function test_relawan_menerima_modul_sesuai_unit(): void
    {
        User::factory()->relawan(RelawanUnit::Distribusi)->create([
            'sppg_id' => $this->createSppg(), 'telegram_id' => 6000001,
        ]);

        $response = $this->postJson(self::URL, ['init_data' => $this->makeInitData(['id' => 6000001])])->assertOk();

        $this->assertContains('bukti-distribusi', $response->json('user.modules'));
        $this->assertNotContains('log-produksi', $response->json('user.modules'));
        $this->assertNotContains('keuangan', $response->json('user.modules'));
    }
}
