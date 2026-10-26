<?php

namespace Tests\Feature\Auth;

use App\Enums\RelawanUnit;
use App\Enums\Role;
use App\Models\User;
use App\Services\Telegram\TelegramAuthService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
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

    public function test_initdata_sah_hanya_menerbitkan_token_pra_login(): void
    {
        $this->freezeSecond();
        $user = $this->registeredUser();

        $response = $this->postJson(self::URL, ['init_data' => $this->makeInitData(['id' => 7012345678, 'username' => 'kepala_baru'])]);

        // Akun belum punya password -> wajib aktivasi; profil & hak akses belum dibuka.
        $response->assertOk()
            ->assertJsonPath('langkah', 'aktivasi')
            ->assertJsonPath('token_type', 'Bearer')
            ->assertJsonPath('token_jenis', 'pra_login')
            ->assertJsonPath('expires_at', now()->addMinutes(15)->toIso8601String())
            ->assertJsonPath('akun.nama', $user->name)
            ->assertJsonPath('akun.perlu_username', true)
            ->assertJsonMissingPath('user')
            ->assertHeader('Cache-Control', 'no-store, private')
            ->assertHeader('X-Content-Type-Options', 'nosniff');

        $this->assertSame(['auth:pra-login'], PersonalAccessToken::first()->abilities);

        $user->refresh();
        $this->assertNull($user->last_login_at, 'initData saja bukan login penuh.');
        $this->assertSame('kepala_baru', $user->telegram_username);

        // Token pra-login tidak membuka rute data mana pun.
        $this->freshAuth();
        $this->withToken($response->json('token'))->getJson('/api/v1/auth/me')
            ->assertForbidden()->assertJsonPath('alasan', 'perlu_login_penuh');
    }

    public function test_akun_berpassword_diarahkan_ke_langkah_masuk(): void
    {
        $this->registeredUser(['username' => 'kepala.sukamaju', 'password' => Hash::make('Rahasia123x')]);

        $this->postJson(self::URL, ['init_data' => $this->makeInitData(['id' => 7012345678])])
            ->assertOk()->assertJsonPath('langkah', 'masuk')->assertJsonPath('akun.perlu_username', false);
    }

    public function test_token_pra_login_satu_per_perangkat(): void
    {
        $user = $this->registeredUser();
        $initData = $this->makeInitData(['id' => 7012345678]);
        $hp = str_repeat('a', 32);
        $tablet = str_repeat('b', 32);

        $first = $this->postJson(self::URL, ['init_data' => $initData, 'device_id' => $hp])->json('token');
        $this->postJson(self::URL, ['init_data' => $initData, 'device_id' => $tablet])->assertOk();
        $second = $this->postJson(self::URL, ['init_data' => $initData, 'device_id' => $hp])->json('token');

        $this->assertSame(2, $user->tokens()->count());
        $this->assertNull(PersonalAccessToken::findToken($first));
        $this->assertNotNull(PersonalAccessToken::findToken($second));
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
        $this->postJson(self::URL, ['init_data' => 'x', 'device_id' => '../etc'])
            ->assertUnprocessable()->assertJsonValidationErrors(['device_id']);
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

        // Bypass dev hanya menerbitkan token pra-login bertanda auth:dev (tidak pernah login penuh).
        $this->postJson(self::URL, ['init_data' => 'dev_role=kepala_sppg'])
            ->assertOk()
            ->assertJsonPath('langkah', 'aktivasi')
            ->assertJsonPath('token_jenis', 'pra_login');

        $demo = User::query()->where('telegram_username', 'sigap_dev_kepala_sppg')->firstOrFail();
        $this->assertSame(Role::KepalaSppg, $demo->role);
        $this->assertNotNull($demo->sppg_id);
        $this->assertSame(['auth:pra-login', 'auth:dev'], PersonalAccessToken::first()->abilities);

        // Pemanggilan kedua memakai pengguna demo yang sama.
        $this->postJson(self::URL, ['init_data' => 'dev_role=kepala_sppg'])->assertOk();
        $this->assertSame(1, User::query()->where('telegram_username', 'sigap_dev_kepala_sppg')->count());

        $this->postJson(self::URL, ['init_data' => 'dev_role=relawan&dev_unit=distribusi'])->assertOk();
        $this->assertSame(RelawanUnit::Distribusi, User::query()->where('telegram_username', 'sigap_dev_relawan_distribusi')->firstOrFail()->unit);
        $this->postJson(self::URL, ['init_data' => 'dev_role=admin'])->assertOk();
        $this->assertNull(User::query()->where('telegram_username', 'sigap_dev_admin')->first()->sppg_id);
    }

    public function test_dev_bypass_tidak_dapat_menerbitkan_login_penuh(): void
    {
        $this->app['env'] = 'local';
        config(['telegram.dev_bypass' => true]);

        $pra = $this->postJson(self::URL, ['init_data' => 'dev_role=admin', 'device_id' => str_repeat('d', 32)])->json('token');
        $demo = User::query()->where('telegram_username', 'sigap_dev_admin')->firstOrFail();
        // Walau password diatur langsung di DB, token dev tetap tidak dapat ditukar.
        User::withoutGlobalScopes()->whereKey($demo->id)->update(['username' => 'demo.admin', 'password' => Hash::make('Rahasia123x')]);

        foreach ([
            ['/api/v1/auth/masuk', ['identitas' => 'demo.admin', 'password' => 'Rahasia123x', 'device_id' => str_repeat('d', 32)]],
            ['/api/v1/auth/reset-password', ['identitas' => 'demo.admin', 'password' => 'BaruSekali9z', 'password_confirmation' => 'BaruSekali9z', 'device_id' => str_repeat('d', 32)]],
        ] as [$url, $body]) {
            $this->freshAuth();
            $this->withToken($pra)->postJson($url, $body)->assertForbidden()->assertJsonPath('alasan', 'dev_bypass');
        }

        $this->assertSame(0, PersonalAccessToken::query()->get()->filter(fn ($t) => in_array('auth:penuh', $t->abilities, true))->count());
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

    #[DataProvider('urlPublik')]
    public function test_dev_bypass_ditolak_di_local_bila_app_url_publik(string $url): void
    {
        $this->app['env'] = 'local';
        config(['telegram.dev_bypass' => true, 'app.url' => $url]);

        $this->assertFalse(app(TelegramAuthService::class)->devBypassAllowed());
        $this->postJson(self::URL, ['init_data' => 'dev_role=admin'])->assertUnauthorized();
        $this->assertSame(0, User::count());
    }

    /** @return array<string, array{string}> */
    public static function urlPublik(): array
    {
        return [
            'domain staging' => ['https://staging.sigap-sppg.example'],
            'ip publik' => ['http://203.0.113.10'],
            'localhost palsu' => ['https://localhost.example.com'],
            'tanpa app_url' => [''],
        ];
    }

    public function test_dev_bypass_di_local_menerima_host_pengembangan(): void
    {
        $this->app['env'] = 'local';
        config(['telegram.dev_bypass' => true]);

        foreach (['http://localhost:8000', 'http://127.0.0.1', 'http://[::1]:8080', 'https://sigap.test', 'http://app.localhost'] as $url) {
            config(['app.url' => $url]);
            $this->assertTrue(app(TelegramAuthService::class)->devBypassAllowed(), $url);
        }
    }

    // ---- Usia initData (NFR-04) ----

    public function test_usia_initdata_bawaan_satu_jam(): void
    {
        $this->assertSame(3600, (int) config('telegram.init_data_max_age'));

        $this->freezeSecond();
        $this->registeredUser();

        $this->postJson(self::URL, ['init_data' => $this->makeInitData(['id' => 7012345678], now()->subMinutes(59)->timestamp)])->assertOk();
        $this->freshAuth();
        $this->postJson(self::URL, ['init_data' => $this->makeInitData(['id' => 7012345678], now()->subMinutes(61)->timestamp)])->assertUnauthorized();
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
        $relawan = User::factory()->relawan(RelawanUnit::Distribusi)->create([
            'sppg_id' => $this->createSppg(), 'telegram_id' => 6000001,
        ]);
        $this->masukPenuh($relawan);

        $response = $this->getJson('/api/v1/auth/me')->assertOk();

        $this->assertContains('bukti-distribusi', $response->json('user.modules'));
        $this->assertNotContains('log-produksi', $response->json('user.modules'));
        $this->assertNotContains('keuangan', $response->json('user.modules'));
    }
}
