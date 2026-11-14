<?php

namespace Tests\Feature\Auth;

use App\Enums\Role;
use App\Models\AuditLog;
use App\Models\User;
use App\Services\Auth\AuditAuth;
use App\Services\Auth\SesiService;
use App\Services\Auth\SesiWeb;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Log;
use Illuminate\Testing\TestResponse;
use Laravel\Sanctum\PersonalAccessToken;
use Symfony\Component\HttpFoundation\Cookie;
use Tests\Support\CreatesSppg;
use Tests\TestCase;

/**
 * R04-4: batas laju jalur cookie SEBELUM CSRF/autentikasi (#81) dengan IP dari proxy tepercaya saja, dan rotasi
 * token sesi web + CSRF saat password diubah lewat jalur cookie (#82). Jalur Bearer tidak tersentuh.
 */
class WebBatasLajuRotasiTest extends TestCase
{
    use CreatesSppg, RefreshDatabase;

    private const ORIGIN = 'http://localhost';

    private const SANDI = 'Rahasia123x';

    private const PESAN_429 = 'Terlalu banyak permintaan. Coba lagi sebentar lagi.';

    private User $siti;

    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo(Carbon::parse('2026-11-14 08:00:00'));

        $this->siti = User::factory()->role(Role::KepalaSppg)->denganKredensial('siti.dapur', self::SANDI)->create([
            'sppg_id' => $this->createSppg('SPPG Sukamaju'),
            'telegram_id' => 7012345678,
        ]);
    }

    private function sesiWeb(): string
    {
        return app(SesiService::class)->terbitkanWeb($this->siti, 'Firefox · Linux')['cookie']->getValue();
    }

    /**
     * @param  array<string, string>  $headers
     * @param  array<string, string>  $server  tambahan variabel server (REMOTE_ADDR, ...)
     * @param  array<string, mixed>  $body
     */
    private function kirim(string $method, string $uri, ?string $sesi, array $headers = [], array $body = [], array $server = []): TestResponse
    {
        $this->app['auth']->forgetGuards();
        $this->flushHeaders();

        $cookies = $sesi === null ? [] : [SesiWeb::namaCookieSesi() => $sesi];
        $vars = $this->transformHeadersToServerVars(['Accept' => 'application/json', 'Content-Type' => 'application/json'] + $headers) + $server;

        return $this->call($method, $uri, [], $cookies, [], $vars, (string) json_encode($body));
    }

    /** @return array<string, string> */
    private function headerWeb(string $csrf): array
    {
        return ['Origin' => self::ORIGIN, 'X-Sigap-Klien' => 'web', 'X-CSRF-Token' => $csrf];
    }

    /** @param array<string, string> $server */
    private function me(?string $sesi, array $server = []): TestResponse
    {
        return $this->kirim('GET', '/api/v1/auth/me', $sesi, ['X-Sigap-Klien' => 'web'], [], $server);
    }

    private function cookieSesi(TestResponse $res): ?Cookie
    {
        foreach ($res->headers->getCookies() as $cookie) {
            if ($cookie->getName() === SesiWeb::namaCookieSesi()) {
                return $cookie;
            }
        }

        return null;
    }

    private function assert429Seragam(TestResponse $res): void
    {
        $res->assertStatus(429)->assertHeader('Retry-After')->assertExactJson(['message' => self::PESAN_429]);
    }

    // ---------------------------------------------------------------- #81 batas laju sebelum autentikasi

    public function test_kegagalan_401_jalur_cookie_dibatasi_per_ip_lalu_429_seragam_juga_untuk_cookie_sah(): void
    {
        config(['kredensial.web.throttle.sesi_gagal_per_ip_per_menit' => 3]);
        $sah = $this->sesiWeb();

        $this->me(null)->assertUnauthorized();
        $this->me('1|cookie-palsu-tidak-dikenal')->assertUnauthorized();
        $this->me('999|lain-lagi')->assertUnauthorized();

        // Bucket kegagalan habis: 429 sebelum autentikasi, bentuk sama untuk cookie sah, palsu, dan tanpa cookie.
        $this->assert429Seragam($this->me($sah));
        $this->assert429Seragam($this->me('1|cookie-palsu-tidak-dikenal'));
        $this->assert429Seragam($this->me(null));

        $this->travel(61)->seconds();
        $this->me($sah)->assertOk();
    }

    public function test_csrf_ditolak_ikut_dihitung_karena_limiter_berjalan_sebelum_sesi_web_cookie(): void
    {
        config(['kredensial.web.throttle.sesi_gagal_per_ip_per_menit' => 2]);
        $sesi = $this->sesiWeb();

        $this->kirim('POST', '/api/v1/auth/logout', $sesi, $this->headerWeb('csrf-salah'))->assertForbidden()->assertJsonPath('alasan', 'csrf_ditolak');
        $this->kirim('POST', '/api/v1/auth/logout', $sesi, $this->headerWeb('csrf-salah'))->assertForbidden();

        $this->assert429Seragam($this->kirim('POST', '/api/v1/auth/logout', $sesi, $this->headerWeb(SesiWeb::csrfSesi($sesi))));
        $this->assertNotNull(PersonalAccessToken::findToken($sesi), 'Permintaan 429 tidak boleh diproses.');
    }

    public function test_403_rbac_biasa_tidak_dihitung_sebagai_kegagalan(): void
    {
        config(['kredensial.web.throttle.sesi_gagal_per_ip_per_menit' => 1]);
        $sesi = $this->sesiWeb();

        // Kepala SPPG tidak berhak mengelola pengguna lintas SPPG (403 Policy) -> bukan kegagalan autentikasi.
        for ($i = 0; $i < 3; $i++) {
            $status = $this->kirim('GET', '/api/v1/pengguna/999999', $sesi, ['X-Sigap-Klien' => 'web'])->status();
            $this->assertContains($status, [403, 404]);
        }

        $this->me($sesi)->assertOk();
    }

    public function test_semua_permintaan_jalur_cookie_dibatasi_per_ip(): void
    {
        config(['kredensial.web.throttle.sesi_per_ip_per_menit' => 4]);
        $sesi = $this->sesiWeb();

        for ($i = 0; $i < 4; $i++) {
            $this->me($sesi)->assertOk();
        }

        $this->assert429Seragam($this->me($sesi));
        // IP lain tidak terpengaruh (tanpa pemutus arus global).
        $this->me($sesi, ['REMOTE_ADDR' => '198.51.100.20'])->assertOk();
    }

    public function test_jalur_bearer_tidak_dihitung_dan_tidak_diblokir_limiter_cookie(): void
    {
        config(['kredensial.web.throttle.sesi_gagal_per_ip_per_menit' => 1, 'kredensial.web.throttle.sesi_per_ip_per_menit' => 1]);
        $bearer = $this->beriSesiPenuh($this->siti);

        for ($i = 0; $i < 5; $i++) {
            $this->kirim('GET', '/api/v1/auth/me', null, ['Authorization' => 'Bearer 1|bearer-palsu', 'X-Sigap-Klien' => 'web'])->assertUnauthorized();
        }

        $this->kirim('GET', '/api/v1/auth/me', null, ['Authorization' => 'Bearer '.$bearer])->assertOk()->assertJsonMissingPath('csrf');

        // Bucket cookie IP ini dihabiskan; Bearer dari IP yang sama tetap dilayani.
        $this->me(null)->assertUnauthorized();
        $this->assert429Seragam($this->me(null));
        $this->kirim('GET', '/api/v1/auth/me', null, ['Authorization' => 'Bearer '.$bearer])->assertOk();
    }

    public function test_batas_tercapai_dicatat_ke_log_sekali_per_ip_per_menit_tanpa_ip_mentah(): void
    {
        config(['kredensial.web.throttle.sesi_gagal_per_ip_per_menit' => 1]);
        Log::spy();

        $this->me(null)->assertUnauthorized();
        $this->me(null)->assertStatus(429);
        $this->me(null)->assertStatus(429);

        Log::shouldHaveReceived('warning')->once()->withArgs(function (string $pesan, array $konteks): bool {
            return str_contains($pesan, 'batas laju jalur cookie') && $konteks['bucket'] === 'gagal'
                && ! in_array('127.0.0.1', $konteks, true) && strlen($konteks['ip_sidik']) === 16;
        });
    }

    // ---------------------------------------------------------------- IP klien: hanya X-Forwarded-For dari proxy tepercaya

    public function test_x_forwarded_for_dari_proxy_tepercaya_menjadi_kunci_ip(): void
    {
        config(['trustedproxy.proxies' => '10.0.0.9', 'kredensial.web.throttle.sesi_gagal_per_ip_per_menit' => 1]);
        $lewatProxy = fn (string $klien) => ['REMOTE_ADDR' => '10.0.0.9', 'HTTP_X_FORWARDED_FOR' => $klien];

        $this->me(null, $lewatProxy('203.0.113.5'))->assertUnauthorized();
        $this->assert429Seragam($this->me(null, $lewatProxy('203.0.113.5')));

        // Klien lain di balik proxy tepercaya yang sama punya bucket sendiri.
        $this->me(null, $lewatProxy('203.0.113.6'))->assertUnauthorized();
    }

    public function test_x_forwarded_for_palsu_dari_luar_proxy_tepercaya_diabaikan(): void
    {
        config(['trustedproxy.proxies' => '10.0.0.9', 'kredensial.web.throttle.sesi_gagal_per_ip_per_menit' => 1]);

        $this->me(null, ['REMOTE_ADDR' => '198.51.100.7', 'HTTP_X_FORWARDED_FOR' => '203.0.113.1'])->assertUnauthorized();

        // Mengganti X-Forwarded-For tidak memberi bucket baru: kunci tetap REMOTE_ADDR.
        $this->assert429Seragam($this->me(null, ['REMOTE_ADDR' => '198.51.100.7', 'HTTP_X_FORWARDED_FOR' => '203.0.113.2']));
        $this->assert429Seragam($this->me(null, ['REMOTE_ADDR' => '198.51.100.7', 'HTTP_X_FORWARDED_FOR' => '10.0.0.9']));
    }

    public function test_tanpa_proxy_tepercaya_x_forwarded_for_selalu_diabaikan(): void
    {
        config(['trustedproxy.proxies' => null, 'kredensial.web.throttle.sesi_gagal_per_ip_per_menit' => 1]);

        $this->me(null, ['HTTP_X_FORWARDED_FOR' => '203.0.113.1'])->assertUnauthorized();
        $this->assert429Seragam($this->me(null, ['HTTP_X_FORWARDED_FOR' => '203.0.113.2']));
    }

    public function test_config_trusted_proxies_menolak_wildcard(): void
    {
        $lama = getenv('TRUSTED_PROXIES');

        try {
            foreach (['*' => null, '**' => null, '' => null, ' 10.0.0.9 ' => '10.0.0.9'] as $nilai => $harapan) {
                putenv('TRUSTED_PROXIES='.$nilai);
                $this->assertSame($harapan, (require base_path('config/trustedproxy.php'))['proxies'], 'TRUSTED_PROXIES='.$nilai);
            }
        } finally {
            putenv($lama === false ? 'TRUSTED_PROXIES' : 'TRUSTED_PROXIES='.$lama);
        }
    }

    // ---------------------------------------------------------------- #82 rotasi token + CSRF saat password diubah

    public function test_ubah_password_jalur_cookie_merotasi_cookie_dan_csrf(): void
    {
        $lama = $this->sesiWeb();
        $id = (int) PersonalAccessToken::findToken($lama)->getKey();

        $res = $this->kirim('PUT', '/api/v1/auth/password', $lama, $this->headerWeb(SesiWeb::csrfSesi($lama)), [
            'password_lama' => self::SANDI, 'password' => 'SandiBaru456y', 'password_confirmation' => 'SandiBaru456y',
        ])->assertOk()->assertJsonStructure(['message', 'jumlah_sesi_dicabut', 'csrf']);

        $cookie = $this->cookieSesi($res);
        $this->assertNotNull($cookie, 'Cookie sesi baru wajib diterbitkan.');
        $baru = (string) $cookie->getValue();
        $this->assertNotSame($lama, $baru);
        $this->assertTrue($cookie->isHttpOnly());
        $this->assertSame(SesiWeb::csrfSesi($baru), $res->json('csrf'));
        $this->assertNotSame(SesiWeb::csrfSesi($lama), $res->json('csrf'));
        $this->assertStringNotContainsString($baru, (string) $res->getContent());

        // Baris sesi sama (id, kuota), nilai lama tidak sah lagi.
        $this->assertNull(PersonalAccessToken::findToken($lama));
        $this->assertSame($id, (int) PersonalAccessToken::findToken($baru)->getKey());
        $this->me($lama)->assertUnauthorized();
        $this->me($baru)->assertOk()->assertJsonPath('csrf', $res->json('csrf'));

        // CSRF lama ditolak walau dengan cookie baru; CSRF baru diterima.
        $this->kirim('POST', '/api/v1/auth/logout', $baru, $this->headerWeb(SesiWeb::csrfSesi($lama)))->assertForbidden()->assertJsonPath('alasan', 'csrf_ditolak');
        $this->kirim('POST', '/api/v1/auth/logout', $baru, $this->headerWeb((string) $res->json('csrf')))->assertOk();

        $this->assertSame('password_diubah', AuditLog::query()->where('event', AuditAuth::WEB_SESI_DIROTASI)->sole()->new_values['pemicu']);
    }

    public function test_ubah_password_jalur_bearer_tidak_merotasi_dan_respons_tidak_berubah(): void
    {
        $bearer = $this->beriSesiPenuh($this->siti);

        $res = $this->kirim('PUT', '/api/v1/auth/password', null, ['Authorization' => 'Bearer '.$bearer], [
            'password_lama' => self::SANDI, 'password' => 'SandiBaru456y', 'password_confirmation' => 'SandiBaru456y',
        ])->assertOk();

        $this->assertSame(['message', 'jumlah_sesi_dicabut'], array_keys($res->json()));
        $this->assertNull($this->cookieSesi($res));
        $this->assertNotNull(PersonalAccessToken::findToken($bearer));
        $this->kirim('GET', '/api/v1/auth/me', null, ['Authorization' => 'Bearer '.$bearer])->assertOk();
        $this->assertSame(0, AuditLog::query()->where('event', AuditAuth::WEB_SESI_DIROTASI)->count());
    }

    public function test_ubah_password_gagal_jalur_cookie_tidak_merotasi(): void
    {
        $sesi = $this->sesiWeb();

        $res = $this->kirim('PUT', '/api/v1/auth/password', $sesi, $this->headerWeb(SesiWeb::csrfSesi($sesi)), [
            'password_lama' => 'SalahSekali9', 'password' => 'SandiBaru456y', 'password_confirmation' => 'SandiBaru456y',
        ])->assertStatus(422);

        $this->assertNull($res->json('csrf'));
        $this->assertNotNull(PersonalAccessToken::findToken($sesi));
        $this->me($sesi)->assertOk();
    }
}
