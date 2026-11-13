<?php

namespace Tests\Feature\Auth;

use App\Enums\Role;
use App\Models\User;
use App\Services\Auth\SesiService;
use App\Services\Auth\SesiWeb;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Testing\TestResponse;
use Laravel\Sanctum\PersonalAccessToken;
use Laravel\Sanctum\Sanctum;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Component\HttpFoundation\Cookie;
use Tests\Support\CreatesSppg;
use Tests\TestCase;

/**
 * R04-1 jalur cookie web (ADR 2026-11-09 Keputusan 1-3, A1, A3): cookie `__Host-sigap_sesi` -> token,
 * penolakan silang cookie/Bearer (S-01..S-03), CSRF tiga lapis (S-04), atribut cookie (S-15), CORS (S-20),
 * jalur Bearer Mini App tidak berubah, dan regresi EnsureTokenAbility.
 */
class WebSesiCookieTest extends TestCase
{
    use CreatesSppg, RefreshDatabase;

    private const ORIGIN = 'http://localhost';

    /** Jalur cookie hanya aktif dengan header ini (semua metode, termasuk GET). */
    private const KLIEN = ['X-Sigap-Klien' => 'web'];

    private User $siti;

    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo(Carbon::parse('2026-11-09 08:00:00'));

        $this->siti = User::factory()->role(Role::KepalaSppg)->denganKredensial('siti.dapur')->create([
            'sppg_id' => $this->createSppg('SPPG Sukamaju'),
            'telegram_id' => 7012345678,
        ]);
    }

    private function sesiWeb(?User $user = null): string
    {
        return app(SesiService::class)->terbitkanWeb($user ?? $this->siti, 'Firefox · Linux')['token'];
    }

    private function sesiMiniApp(?User $user = null): string
    {
        return $this->beriSesiPenuh($user ?? $this->siti);
    }

    /**
     * Permintaan "browser": cookie hanya dari $sesi, header persis $headers (tanpa header bawaan tes).
     *
     * @param  array<string, string>  $headers
     * @param  array<string, mixed>  $body
     */
    private function web(string $method, string $uri, ?string $sesi, array $headers = [], array $body = []): TestResponse
    {
        $this->app['auth']->forgetGuards();
        $this->flushHeaders();

        $cookies = $sesi === null ? [] : [SesiWeb::namaCookieSesi() => $sesi];
        $server = $this->transformHeadersToServerVars(['Accept' => 'application/json', 'Content-Type' => 'application/json'] + $headers);

        return $this->call($method, $uri, [], $cookies, [], $server, (string) json_encode($body));
    }

    /** @return array<string, string> */
    private function headerWeb(string $sesi): array
    {
        return ['Origin' => self::ORIGIN, 'X-Sigap-Klien' => 'web', 'X-CSRF-Token' => SesiWeb::csrfSesi($sesi)];
    }

    private function cookieDiRespons(TestResponse $res): ?Cookie
    {
        foreach ($res->headers->getCookies() as $cookie) {
            if ($cookie->getName() === SesiWeb::namaCookieSesi()) {
                return $cookie;
            }
        }

        return null;
    }

    private function assertCookieDihapus(TestResponse $res): void
    {
        $cookie = $this->cookieDiRespons($res);
        $this->assertNotNull($cookie, 'Cookie sesi tidak dihapus.');
        $this->assertTrue($cookie->isCleared());
        $this->assertStringContainsString('Max-Age=0', (string) $cookie);
    }

    // ---------------------------------------------------------------- jalur cookie dasar

    public function test_sesi_web_lewat_cookie_diterima_dan_auth_me_memberi_csrf_terikat_sesi(): void
    {
        $sesi = $this->sesiWeb();

        $res = $this->web('GET', '/api/v1/auth/me', $sesi, self::KLIEN)->assertOk()
            ->assertJsonPath('user.id', $this->siti->id)
            ->assertJsonPath('jenis_sesi', 'web')
            ->assertJsonPath('csrf', SesiWeb::csrfSesi($sesi));

        $this->assertStringNotContainsString($sesi, (string) $res->getContent());

        $token = PersonalAccessToken::findToken($sesi);
        $this->assertSame([SesiService::ABILITY_PENUH, SesiService::ABILITY_WEB], array_slice($token->abilities, 0, 2));
        $this->assertNull($token->perangkat_tepercaya_id);
        $this->assertStringStartsWith(SesiService::PREFIX_WEB, $token->name);
    }

    public function test_csrf_berbeda_untuk_setiap_sesi_dan_berbeda_dari_csrf_pra_login(): void
    {
        $a = $this->sesiWeb();
        $b = $this->sesiWeb();

        $this->assertNotSame(SesiWeb::csrfSesi($a), SesiWeb::csrfSesi($b));
        $this->assertNotSame(SesiWeb::csrfSesi('1|x'), SesiWeb::csrfPraLogin('1|x'));
        $this->assertSame(64, strlen(SesiWeb::csrfSesi($a)));
    }

    // ---------------------------------------------------------------- S-01..S-03 penolakan silang

    public function test_s01_token_mini_app_lewat_cookie_ditolak_401_dan_cookie_dihapus(): void
    {
        $miniApp = $this->sesiMiniApp();

        $res = $this->web('GET', '/api/v1/auth/me', $miniApp, self::KLIEN)->assertUnauthorized()
            ->assertExactJson(['message' => 'Tidak terautentikasi. Silakan buka ulang Mini App.']);
        $this->assertCookieDihapus($res);

        // Token Mini App tetap sah di jalurnya sendiri.
        $this->app['auth']->forgetGuards();
        $this->withToken($miniApp)->getJson('/api/v1/auth/me')->assertOk();
    }

    public function test_s01_token_mini_app_lewat_cookie_ditolak_juga_untuk_metode_pengubah_dengan_csrf_sah(): void
    {
        $miniApp = $this->sesiMiniApp();

        $this->web('POST', '/api/v1/auth/logout', $miniApp, $this->headerWeb($miniApp))->assertUnauthorized();
        $this->assertNotNull(PersonalAccessToken::findToken($miniApp));
    }

    public function test_s02_token_web_lewat_bearer_ditolak_401_tanpa_mencabut_sesi(): void
    {
        $sesi = $this->sesiWeb();

        $this->app['auth']->forgetGuards();
        $this->withToken($sesi)->getJson('/api/v1/auth/me')->assertUnauthorized()
            ->assertExactJson(['message' => 'Tidak terautentikasi. Silakan buka ulang Mini App.']);

        $this->app['auth']->forgetGuards();
        $this->withToken($sesi)->deleteJson('/api/v1/auth/sesi/999999')->assertUnauthorized();

        $this->web('GET', '/api/v1/auth/me', $sesi, self::KLIEN)->assertOk();
    }

    public function test_s03_bearer_tidak_sah_dengan_cookie_web_sah_tetap_401_dan_cookie_tidak_disentuh(): void
    {
        $sesi = $this->sesiWeb();

        $res = $this->web('GET', '/api/v1/auth/me', $sesi, ['Authorization' => 'Bearer 999|palsu'])->assertUnauthorized();
        $this->assertNull($this->cookieDiRespons($res));

        // Header Authorization kosong/bukan Bearer juga mematikan jalur cookie.
        $this->web('GET', '/api/v1/auth/me', $sesi, ['Authorization' => ''])->assertUnauthorized();
        $this->web('GET', '/api/v1/auth/me', $sesi, ['Authorization' => 'Basic YTpi'])->assertUnauthorized();
    }

    public function test_s03_bearer_mini_app_sah_menang_atas_cookie_web(): void
    {
        $sesi = $this->sesiWeb();
        $budi = User::factory()->role(Role::KepalaSppg)->create(['sppg_id' => $this->createSppg('SPPG Lain'), 'telegram_id' => 7012345600]);
        $miniAppBudi = $this->sesiMiniApp($budi);

        $this->web('GET', '/api/v1/auth/me', $sesi, ['Authorization' => 'Bearer '.$miniAppBudi])->assertOk()
            ->assertJsonPath('user.id', $budi->id)
            ->assertJsonMissingPath('csrf');
    }

    // ---------------------------------------------------------------- S-04 CSRF tiga lapis

    /** @return array<string, array{0: callable(string): array<string, string>}> */
    public static function headerCsrfTidakSah(): array
    {
        $sah = fn (string $s): array => ['Origin' => self::ORIGIN, 'X-Sigap-Klien' => 'web', 'X-CSRF-Token' => SesiWeb::csrfSesi($s)];

        return [
            'tanpa X-CSRF-Token' => [fn (string $s) => array_diff_key($sah($s), ['X-CSRF-Token' => 1])],
            'X-CSRF-Token salah' => [fn (string $s) => ['X-CSRF-Token' => str_repeat('a', 64)] + $sah($s)],
            'X-CSRF-Token kosong' => [fn (string $s) => ['X-CSRF-Token' => ''] + $sah($s)],
            'CSRF pra-login dipakai untuk sesi' => [fn (string $s) => ['X-CSRF-Token' => SesiWeb::csrfPraLogin($s)] + $sah($s)],
            // Tanpa/selain `X-Sigap-Klien: web` cookie diabaikan sama sekali (401): lihat test_p1_*.
            'tanpa Origin' => [fn (string $s) => array_diff_key($sah($s), ['Origin' => 1])],
            'Origin kosong' => [fn (string $s) => ['Origin' => ''] + $sah($s)],
            'Origin null' => [fn (string $s) => ['Origin' => 'null'] + $sah($s)],
            'Origin asing' => [fn (string $s) => ['Origin' => 'https://evil.example'] + $sah($s)],
            'Origin skema lain' => [fn (string $s) => ['Origin' => 'https://localhost'] + $sah($s)],
            'Origin port lain' => [fn (string $s) => ['Origin' => 'http://localhost:8080'] + $sah($s)],
            'Origin dengan garis miring' => [fn (string $s) => ['Origin' => self::ORIGIN.'/'] + $sah($s)],
            'Origin subdomain' => [fn (string $s) => ['Origin' => 'http://evil.localhost'] + $sah($s)],
            'Origin asing sama dengan Host palsu' => [fn (string $s) => ['Origin' => 'http://evil.example', 'Host' => 'evil.example'] + $sah($s)],
        ];
    }

    /** @param callable(string): array<string, string> $header */
    #[DataProvider('headerCsrfTidakSah')]
    public function test_s04_metode_pengubah_jalur_cookie_tanpa_csrf_sah_ditolak_403(callable $header): void
    {
        $sesi = $this->sesiWeb();

        foreach (['POST' => '/api/v1/auth/logout', 'DELETE' => '/api/v1/auth/sesi/999999', 'PUT' => '/api/v1/auth/password'] as $metode => $uri) {
            $this->web($metode, $uri, $sesi, $header($sesi))->assertForbidden()
                ->assertExactJson(['message' => 'Permintaan ditolak. Muat ulang halaman lalu coba lagi.', 'alasan' => 'csrf_ditolak']);
        }

        // Ditolak SEBELUM autentikasi: sesi tidak dicabut dan pemakaian tidak tercatat.
        $token = PersonalAccessToken::findToken($sesi);
        $this->assertNotNull($token);
        $this->assertNull($token->last_used_at);
    }

    public function test_s04_override_metode_tidak_melewati_csrf(): void
    {
        $sesi = $this->sesiWeb();

        $this->web('POST', '/api/v1/auth/me', $sesi, self::KLIEN + ['X-HTTP-Method-Override' => 'GET'])->assertForbidden()->assertJsonPath('alasan', 'csrf_ditolak');
        $this->web('POST', '/api/v1/auth/sesi/999999?_method=DELETE', $sesi, self::KLIEN)->assertForbidden()->assertJsonPath('alasan', 'csrf_ditolak');
        $this->web('POST', '/api/v1/auth/sesi/999999', $sesi, ['X-HTTP-Method-Override' => 'DELETE'] + $this->headerWeb($sesi))->assertNotFound();
    }

    public function test_s04_csrf_milik_sesi_lain_ditolak(): void
    {
        $a = $this->sesiWeb();
        $b = $this->sesiWeb();

        $this->web('POST', '/api/v1/auth/logout', $a, $this->headerWeb($b))->assertForbidden()->assertJsonPath('alasan', 'csrf_ditolak');
        $this->assertNotNull(PersonalAccessToken::findToken($a));
    }

    public function test_s04_csrf_sah_lolos_dan_metode_aman_tidak_butuh_csrf(): void
    {
        $sesi = $this->sesiWeb();

        $this->web('GET', '/api/v1/auth/me', $sesi, self::KLIEN)->assertOk();
        $this->web('GET', '/api/v1/auth/sesi', $sesi, self::KLIEN + ['Origin' => 'https://evil.example'])->assertOk();
        $this->web('DELETE', '/api/v1/auth/sesi/999999', $sesi, $this->headerWeb($sesi))->assertNotFound();
    }

    public function test_s04_origin_mengikuti_app_url_bukan_header_host(): void
    {
        config(['app.url' => 'https://sigapsppg.app']);
        $sesi = $this->sesiWeb();
        $header = ['Origin' => 'https://sigapsppg.app'] + $this->headerWeb($sesi);

        $this->web('DELETE', '/api/v1/auth/sesi/999999', $sesi, $header)->assertNotFound();
        $this->web('DELETE', '/api/v1/auth/sesi/999999', $sesi, ['Origin' => 'http://sigapsppg.app'] + $header)->assertForbidden();
        $this->web('DELETE', '/api/v1/auth/sesi/999999', $sesi, ['Origin' => 'https://sigapsppg.app.evil.example', 'Host' => 'sigapsppg.app.evil.example'] + $header)->assertForbidden();
        $this->assertSame('https://sigapsppg.app', SesiWeb::originDiizinkan());

        config(['app.url' => 'bukan-url']);
        $this->assertNull(SesiWeb::originDiizinkan());
        $this->web('DELETE', '/api/v1/auth/sesi/999999', $sesi, $header)->assertForbidden();
    }

    public function test_logout_jalur_cookie_mencabut_token_dan_menghapus_cookie(): void
    {
        $sesi = $this->sesiWeb();

        $res = $this->web('POST', '/api/v1/auth/logout', $sesi, $this->headerWeb($sesi))->assertOk();
        $this->assertCookieDihapus($res);
        $this->assertNull(PersonalAccessToken::findToken($sesi));

        $this->web('GET', '/api/v1/auth/me', $sesi, self::KLIEN)->assertUnauthorized();

        // Cabut sesi ini lewat daftar sesi dan keluar semua juga menghapus cookie.
        $kedua = $this->sesiWeb();
        $id = PersonalAccessToken::findToken($kedua)->id;
        $this->assertCookieDihapus($this->web('DELETE', '/api/v1/auth/sesi/'.$id, $kedua, $this->headerWeb($kedua))->assertOk()->assertJsonPath('sesi_ini', true));

        $ketiga = $this->sesiWeb();
        $this->assertCookieDihapus($this->web('POST', '/api/v1/auth/keluar-semua', $ketiga, $this->headerWeb($ketiga))->assertOk());
        $this->assertNull(PersonalAccessToken::findToken($ketiga));
    }

    public function test_cabut_sesi_lain_lewat_cookie_tidak_menghapus_cookie_sendiri(): void
    {
        $sesi = $this->sesiWeb();
        $lain = PersonalAccessToken::findToken($this->sesiWeb());

        $res = $this->web('DELETE', '/api/v1/auth/sesi/'.$lain->id, $sesi, $this->headerWeb($sesi))->assertOk()->assertJsonPath('sesi_ini', false);
        // Hanya penerbitan ulang (pemakaian pertama menggeser masa), bukan penghapusan.
        $this->assertFalse($this->cookieDiRespons($res)?->isCleared() ?? false);
        $this->assertNull(PersonalAccessToken::find($lain->id));
    }

    // ---------------------------------------------------------------- P1 X-Sigap-Klien wajib di jalur cookie

    public function test_p1_get_dengan_cookie_web_sah_tanpa_x_sigap_klien_401_dan_cookie_tidak_dihapus(): void
    {
        $sesi = $this->sesiWeb();

        // Mini App satu origin tanpa Authorization (sebelum login senyap / setelah logout atau 401).
        foreach ([[], ['X-Sigap-Klien' => 'miniapp'], ['X-Sigap-Klien' => ''], ['X-Sigap-Klien' => 'WEB']] as $header) {
            foreach (['/api/v1/auth/me', '/api/v1/auth/sesi', '/api/v1/sppg'] as $uri) {
                $res = $this->web('GET', $uri, $sesi, $header)->assertUnauthorized()
                    ->assertExactJson(['message' => 'Tidak terautentikasi. Silakan buka ulang Mini App.']);
                $this->assertNull($this->cookieDiRespons($res), 'Cookie sesi web tidak boleh disentuh.');
            }
        }

        // Sesi web tidak tersentuh (tidak dicabut, pemakaian tidak tercatat) dan tetap sah dengan header yang benar.
        $this->assertNull(PersonalAccessToken::findToken($sesi)->last_used_at);
        $this->web('GET', '/api/v1/auth/me', $sesi, self::KLIEN)->assertOk()->assertJsonPath('user.id', $this->siti->id);
        $this->web('GET', '/api/v1/sppg', $sesi, self::KLIEN)->assertOk();
    }

    public function test_p1_metode_pengubah_tanpa_x_sigap_klien_cookie_diabaikan_401_tanpa_mencabut_sesi(): void
    {
        $sesi = $this->sesiWeb();
        $tanpaKlien = array_diff_key($this->headerWeb($sesi), self::KLIEN);

        foreach ([$tanpaKlien, ['X-Sigap-Klien' => 'miniapp'] + $tanpaKlien] as $header) {
            $res = $this->web('POST', '/api/v1/auth/logout', $sesi, $header)->assertUnauthorized();
            $this->assertNull($this->cookieDiRespons($res));
            $res = $this->web('POST', '/api/v1/auth/keluar-semua', $sesi, $header)->assertUnauthorized();
            $this->assertNull($this->cookieDiRespons($res));
        }

        $this->assertNotNull(PersonalAccessToken::findToken($sesi));
        $this->assertNull(PersonalAccessToken::findToken($sesi)->last_used_at);
    }

    // ---------------------------------------------------------------- P2 pemeriksaan kedua (PastikanJalurSesi)

    /** Simulasikan pemeriksaan pertama (validasiToken) gagal terbuka: penolakan silang dilewati. */
    private function lewatiPenolakanSilangPertama(): void
    {
        $this->app->instance(SesiService::class, new class extends SesiService
        {
            public function validasiToken(PersonalAccessToken $token, bool $sah): bool
            {
                return $sah;
            }
        });
    }

    public function test_p2_token_mini_app_lewat_cookie_ditolak_walau_validasi_token_lolos_dan_controller_tidak_berjalan(): void
    {
        $this->lewatiPenolakanSilangPertama();
        $miniApp = $this->sesiMiniApp();

        $res = $this->web('GET', '/api/v1/auth/me', $miniApp, self::KLIEN)->assertUnauthorized();
        $this->assertCookieDihapus($res);

        // Logout dengan CSRF sah: ditolak sebelum controller, token Mini App tidak dicabut.
        $res = $this->web('POST', '/api/v1/auth/logout', $miniApp, $this->headerWeb($miniApp))->assertUnauthorized();
        $this->assertCookieDihapus($res);
        $this->assertNotNull(PersonalAccessToken::findToken($miniApp));

        // Jalur Bearer token Mini App tetap normal.
        $this->web('GET', '/api/v1/auth/me', null, ['Authorization' => 'Bearer '.$miniApp])->assertOk();
    }

    public function test_p2_token_web_lewat_bearer_ditolak_walau_validasi_token_lolos(): void
    {
        $this->lewatiPenolakanSilangPertama();
        $sesi = $this->sesiWeb();

        $this->web('POST', '/api/v1/auth/logout', null, ['Authorization' => 'Bearer '.$sesi])->assertUnauthorized();
        $this->assertNotNull(PersonalAccessToken::findToken($sesi));
        $this->web('GET', '/api/v1/auth/me', $sesi, self::KLIEN)->assertOk();
    }

    public function test_p2_atribut_cookie_terisi_tetapi_jalur_tercatat_bukan_cookie_401(): void
    {
        $this->lewatiPenolakanSilangPertama();
        $sesi = $this->sesiWeb();
        $miniApp = $this->sesiMiniApp();

        // Simulasi request guard berbeda: token diambil dari cookie, tetapi jalur tercatat sebagai Bearer.
        Sanctum::getAccessTokenFromRequestUsing(function (Request $request): ?string {
            $request->attributes->set(SesiWeb::ATRIBUT_JALUR, SesiWeb::JALUR_BEARER);
            $cookie = $request->attributes->get(SesiWeb::ATRIBUT_COOKIE);

            return is_string($cookie) ? $cookie : $request->bearerToken();
        });

        try {
            $this->web('GET', '/api/v1/auth/me', $sesi, self::KLIEN)->assertUnauthorized();
            $this->web('POST', '/api/v1/auth/logout', $miniApp, $this->headerWeb($miniApp))->assertUnauthorized();
            $this->assertNotNull(PersonalAccessToken::findToken($miniApp));
        } finally {
            Sanctum::getAccessTokenFromRequestUsing(fn (Request $request): ?string => SesiWeb::tokenDariRequest($request));
        }
    }

    // ---------------------------------------------------------------- S6 cache jalur cookie

    public function test_s6_semua_respons_jalur_cookie_no_store_private_dan_vary_cookie(): void
    {
        $sesi = $this->sesiWeb();

        $respons = [
            $this->web('GET', '/api/v1/sppg', $sesi, self::KLIEN)->assertOk(),
            $this->web('GET', '/api/v1/sppg/999999', $sesi, self::KLIEN)->assertNotFound(),
            $this->web('DELETE', '/api/v1/auth/sesi/999999', $sesi, ['X-CSRF-Token' => 'salah'] + $this->headerWeb($sesi))->assertForbidden(),
            $this->web('GET', '/api/v1/auth/me', '999|palsu', self::KLIEN)->assertUnauthorized(),
        ];

        foreach ($respons as $res) {
            $this->assertStringContainsString('no-store', (string) $res->headers->get('Cache-Control'));
            $this->assertStringContainsString('private', (string) $res->headers->get('Cache-Control'));
            $this->assertContains('Cookie', array_map('trim', explode(',', implode(',', $res->headers->all('vary')))));
        }

        // Jalur Bearer di rute data tidak diubah.
        $miniApp = $this->sesiMiniApp();
        $res = $this->web('GET', '/api/v1/sppg', null, ['Authorization' => 'Bearer '.$miniApp])->assertOk();
        $this->assertStringNotContainsString('no-store', (string) $res->headers->get('Cache-Control'));
    }

    // ---------------------------------------------------------------- Bearer tidak berubah

    public function test_jalur_bearer_tidak_memeriksa_csrf_origin_maupun_cookie(): void
    {
        $miniApp = $this->sesiMiniApp();
        $web = $this->sesiWeb();

        // Tanpa Origin/CSRF, Origin asing, dan cookie web tanpa CSRF: Bearer tetap dilayani seperti sebelumnya.
        $this->web('DELETE', '/api/v1/auth/sesi/999999', null, ['Authorization' => 'Bearer '.$miniApp])->assertNotFound();
        $this->web('DELETE', '/api/v1/auth/sesi/999999', null, ['Authorization' => 'Bearer '.$miniApp, 'Origin' => 'https://evil.example'])->assertNotFound();
        $res = $this->web('DELETE', '/api/v1/auth/sesi/999999', $web, ['Authorization' => 'Bearer '.$miniApp])->assertNotFound();
        $this->assertNull($this->cookieDiRespons($res));

        $this->web('GET', '/api/v1/auth/me', null, ['Authorization' => 'Bearer '.$miniApp])->assertOk()
            ->assertJsonMissingPath('csrf')
            ->assertJsonMissingPath('jenis_sesi');
    }

    public function test_rute_publik_dan_pra_login_tidak_menerima_cookie_web(): void
    {
        $sesi = $this->sesiWeb();

        $this->web('POST', '/api/v1/auth/masuk', $sesi, $this->headerWeb($sesi), ['identitas' => 'siti.dapur', 'password' => 'Rahasia123x'])
            ->assertUnauthorized();
        $this->web('GET', '/api/v1/auth/lupa-password', $sesi, self::KLIEN)->assertUnauthorized();
        $this->assertNotNull(PersonalAccessToken::findToken($sesi));
    }

    public function test_cookie_rusak_atau_tidak_dikenal_401(): void
    {
        foreach (['abc', '1|', '|abc', 'x|abc', '999|tidak-ada', str_repeat('9', 300).'|a'] as $nilai) {
            $this->web('GET', '/api/v1/auth/me', $nilai, self::KLIEN)->assertUnauthorized();
        }
    }

    // ---------------------------------------------------------------- regresi EnsureTokenAbility

    public function test_regresi_ensure_token_ability_pada_jalur_cookie_dan_bearer(): void
    {
        // Token web tanpa auth:penuh -> 403 perlu_login_penuh (ability tetap wajib eksplisit).
        $hanyaWeb = $this->siti->createToken(SesiService::PREFIX_WEB.'uji', [SesiService::ABILITY_WEB], now()->addHour())->plainTextToken;
        $this->web('GET', '/api/v1/auth/me', $hanyaWeb, self::KLIEN)->assertForbidden()->assertJsonPath('alasan', 'perlu_login_penuh');

        // Token wildcard tetap ditolak di jalur Bearer; lewat cookie ditolak lebih awal (bukan token web).
        $wildcard = $this->siti->createToken('lama', ['*'])->plainTextToken;
        $this->web('GET', '/api/v1/auth/me', null, ['Authorization' => 'Bearer '.$wildcard])->assertForbidden()->assertJsonPath('alasan', 'perlu_login_penuh');
        $this->web('GET', '/api/v1/auth/me', $wildcard, self::KLIEN)->assertUnauthorized();

        // Token pra-login: Bearer di rute data -> 403; lewat cookie -> 401.
        $pra = app(SesiService::class)->terbitkanPraLogin($this->siti, null)['token'];
        $this->web('GET', '/api/v1/auth/me', null, ['Authorization' => 'Bearer '.$pra])->assertForbidden()->assertJsonPath('alasan', 'perlu_login_penuh');
        $this->web('GET', '/api/v1/auth/me', $pra, self::KLIEN)->assertUnauthorized();

        // Token web + ability peran: lolos sampai Policy (data SPPG sendiri).
        $sesi = $this->sesiWeb();
        $this->web('GET', '/api/v1/sppg', $sesi, self::KLIEN)->assertOk();
    }

    public function test_akun_dinonaktifkan_menolak_sesi_web(): void
    {
        $sesi = $this->sesiWeb();
        $this->siti->forceFill(['is_active' => false])->save();

        $this->web('GET', '/api/v1/auth/me', $sesi, self::KLIEN)->assertUnauthorized();
        $this->assertNull(PersonalAccessToken::findToken($sesi));
    }

    // ---------------------------------------------------------------- S-15 atribut cookie

    public function test_s15_atribut_set_cookie_host_secure_httponly_samesite_strict_path_tanpa_domain(): void
    {
        $sampai = now()->addHours(12);
        $header = (string) SesiWeb::cookieSesi('5|rahasia', $sampai);

        $this->assertStringStartsWith('__Host-sigap_sesi=', $header);
        $this->assertMatchesRegularExpression('/Max-Age=(43200|43199);/', $header);
        $this->assertStringContainsString('path=/;', $header);
        $this->assertStringContainsString('secure', $header);
        $this->assertStringContainsString('httponly', $header);
        $this->assertStringContainsString('samesite=strict', $header);
        $this->assertStringNotContainsStringIgnoringCase('domain=', $header);

        $hapus = (string) SesiWeb::lupakanCookieSesi();
        $this->assertStringStartsWith('__Host-sigap_sesi=deleted', $hapus);
        $this->assertStringContainsString('Max-Age=0', $hapus);
        $this->assertStringContainsString('secure', $hapus);
        $this->assertStringContainsString('path=/;', $hapus);
        $this->assertStringNotContainsStringIgnoringCase('domain=', $hapus);
    }

    public function test_s15_cookie_diterbitkan_ulang_saat_masa_bergeser_dengan_max_age_mengikuti_expires_at(): void
    {
        $sesi = $this->sesiWeb();
        $this->travel(2)->hours();

        $res = $this->web('GET', '/api/v1/auth/me', $sesi, self::KLIEN)->assertOk();
        $cookie = $this->cookieDiRespons($res);
        $token = PersonalAccessToken::findToken($sesi);

        $this->assertNotNull($cookie);
        $this->assertSame($sesi, $cookie->getValue());
        // Max-Age = expires_at token - sekarang (jam aplikasi) = idle 12 jam sejak pemakaian ini.
        $this->assertTrue($token->expires_at->equalTo(now()->addHours(12)));
        $this->assertEqualsWithDelta(12 * 3600, $cookie->getMaxAge(), 2);
        $this->assertTrue($cookie->isSecure());
        $this->assertTrue($cookie->isHttpOnly());
        $this->assertSame('strict', $cookie->getSameSite());
        $this->assertSame('/', $cookie->getPath());
        $this->assertNull($cookie->getDomain());
        $this->assertStringStartsWith('__Host-', $cookie->getName());
    }

    public function test_s15_nama_tanpa_prefiks_hanya_untuk_app_env_local_dengan_config(): void
    {
        config(['kredensial.web.cookie_lokal_http' => true]);
        $this->assertSame('__Host-sigap_sesi', SesiWeb::namaCookieSesi()); // APP_ENV=testing: diabaikan

        $this->app['env'] = 'production';
        $this->assertSame('__Host-sigap_sesi', SesiWeb::namaCookieSesi());
        $this->assertTrue(SesiWeb::cookieSesi('1|a', now()->addHour())->isSecure());

        $this->app['env'] = 'local';
        $this->assertSame('sigap_sesi', SesiWeb::namaCookieSesi());
        $this->assertFalse(SesiWeb::cookieSesi('1|a', now()->addHour())->isSecure());
        $this->assertSame('sigap_pra', SesiWeb::namaCookie(SesiWeb::COOKIE_PRA));

        config(['kredensial.web.cookie_lokal_http' => false]);
        $this->assertSame('__Host-sigap_sesi', SesiWeb::namaCookieSesi());
        $this->app['env'] = 'testing';
    }

    // ---------------------------------------------------------------- S-20 CORS

    public function test_s20_preflight_dan_permintaan_dari_origin_asing_tanpa_header_cors(): void
    {
        $this->assertSame([], config('cors.allowed_origins'));
        $this->assertSame([], config('cors.allowed_origins_patterns'));
        $this->assertFalse(config('cors.supports_credentials'));

        $preflight = $this->call('OPTIONS', '/api/v1/auth/me', [], [], [], $this->transformHeadersToServerVars([
            'Origin' => 'https://evil.example',
            'Access-Control-Request-Method' => 'POST',
            'Access-Control-Request-Headers' => 'x-csrf-token, x-sigap-klien',
        ]));
        $this->assertFalse($preflight->headers->has('Access-Control-Allow-Origin'));
        $this->assertFalse($preflight->headers->has('Access-Control-Allow-Credentials'));

        $sesi = $this->sesiWeb();
        $res = $this->web('GET', '/api/v1/auth/me', $sesi, self::KLIEN + ['Origin' => 'https://evil.example'])->assertOk();
        $this->assertFalse($res->headers->has('Access-Control-Allow-Origin'));
        $this->assertFalse($res->headers->has('Access-Control-Allow-Credentials'));
    }
}
