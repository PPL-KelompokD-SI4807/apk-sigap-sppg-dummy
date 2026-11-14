<?php

namespace Tests\Feature\Auth;

use App\Enums\Role;
use App\Models\AuditLog;
use App\Models\TantanganToken;
use App\Models\User;
use App\Services\Auth\AuditAuth;
use App\Services\Auth\MasukWebService;
use App\Services\Auth\SesiService;
use App\Services\Auth\SesiWeb;
use App\Services\Auth\TokenSekaliPakaiService;
use App\Services\Telegram\Bot\BotCallbackHandler;
use App\Support\LabelBrowser;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Hashing\HashManager;
use Illuminate\Http\Client\Request as HttpRequest;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Http;
use Illuminate\Testing\TestResponse;
use Laravel\Sanctum\PersonalAccessToken;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Component\HttpFoundation\Cookie;
use Tests\Support\AlurLogin;
use Tests\Support\CreatesSppg;
use Tests\TestCase;

/**
 * R04-2 alur login web (ADR 2026-11-09 Keputusan 4, 5, 7; rencana S-04..S-07, S-15, S-19, S-21; issue #76):
 * CSRF pra-login, respons langkah 1 seragam (anti-enumerasi), kode hanya terkirim bila sandi benar, kode ter-hash
 * sekali pakai + kedaluwarsa + batas percobaan, penguncian kanal web terpisah dari Mini App, tombol "Bukan saya",
 * dan respons login yang tidak pernah memuat token.
 */
class WebLoginTest extends TestCase
{
    use AlurLogin, CreatesSppg, RefreshDatabase;

    private const ORIGIN = 'http://localhost';

    private const UA = 'Mozilla/5.0 (X11; Linux x86_64; rv:132.0) Gecko/20100101 Firefox/132.0';

    private const SANDI = 'Rahasia123x';

    private const CHAT = 7012345678;

    private User $siti;

    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo(Carbon::parse('2026-11-13 08:00:00'));
        $this->fakeTelegram();

        // Batas laju dilonggarkan di tes alur; tes batas laju memakai nilai bawaan config.
        config([
            'kredensial.web.throttle.per_ip_per_menit' => 1000,
            'kredensial.web.throttle.masuk_per_ip_per_menit' => 1000,
            'kredensial.web.throttle.masuk_per_ip_per_jam' => 1000,
            'kredensial.web.throttle.masuk_per_identitas' => 1000,
            'kredensial.web.throttle.kode_per_ip_per_menit' => 1000,
        ]);

        $this->siti = User::factory()->role(Role::KepalaSppg)->denganKredensial('siti.dapur', self::SANDI)->create([
            'sppg_id' => $this->createSppg('SPPG Sukamaju'),
            'telegram_id' => self::CHAT,
            'phone' => '+6281200001111',
        ]);
    }

    // ---------------------------------------------------------------- helper

    /**
     * Permintaan "browser" dengan cookie dan header persis (tanpa header bawaan tes).
     *
     * @param  array<string, string>  $cookies
     * @param  array<string, string>  $headers
     * @param  array<string, mixed>  $body
     */
    private function web(string $method, string $uri, array $cookies = [], array $headers = [], array $body = []): TestResponse
    {
        $this->app['auth']->forgetGuards();
        $this->flushHeaders();

        $server = $this->transformHeadersToServerVars(['Accept' => 'application/json', 'Content-Type' => 'application/json', 'User-Agent' => self::UA] + $headers);

        return $this->call($method, $uri, [], $cookies, [], $server, (string) json_encode($body));
    }

    /** @return array{0: string, 1: string} [id pra-login, csrf] */
    private function praLoginWeb(): array
    {
        $res = $this->web('GET', '/api/v1/web/auth/csrf', [], ['X-Sigap-Klien' => 'web'])->assertOk();
        $cookie = $this->cookie($res, SesiWeb::namaCookie(SesiWeb::COOKIE_PRA));
        $this->assertNotNull($cookie);

        return [(string) $cookie->getValue(), (string) $res->json('csrf')];
    }

    /** @return array<string, string> */
    private function header(string $csrf): array
    {
        return ['Origin' => self::ORIGIN, 'X-Sigap-Klien' => 'web', 'X-CSRF-Token' => $csrf];
    }

    /** @return array<string, string> */
    private function cookiePra(string $pra): array
    {
        return [SesiWeb::namaCookie(SesiWeb::COOKIE_PRA) => $pra];
    }

    private function masuk(string $pra, string $csrf, string $identitas, string $password): TestResponse
    {
        return $this->web('POST', '/api/v1/web/auth/masuk', $this->cookiePra($pra), $this->header($csrf), ['identitas' => $identitas, 'password' => $password]);
    }

    /** @param array<string, string> $cookieTambahan */
    private function kode(string $pra, string $csrf, string $tantangan, string $kode, array $cookieTambahan = []): TestResponse
    {
        return $this->web('POST', '/api/v1/web/auth/kode', $this->cookiePra($pra) + $cookieTambahan, $this->header($csrf), ['tantangan_id' => $tantangan, 'kode' => $kode]);
    }

    private function kirimUlang(string $pra, string $csrf, string $tantangan): TestResponse
    {
        return $this->web('POST', '/api/v1/web/auth/kode/kirim-ulang', $this->cookiePra($pra), $this->header($csrf), ['tantangan_id' => $tantangan]);
    }

    private function cookie(TestResponse $res, string $nama): ?Cookie
    {
        foreach ($res->headers->getCookies() as $cookie) {
            if ($cookie->getName() === $nama) {
                return $cookie;
            }
        }

        return null;
    }

    /** @return list<HttpRequest> pesan kode MASUK WEB ke chat */
    private function pesanKode(int $chat = self::CHAT): array
    {
        return $this->messagesTo($chat)->filter(fn (HttpRequest $r) => str_contains((string) $r['text'], 'Kode MASUK WEB SiGAP'))->values()->all();
    }

    private function kodeWebTerakhir(int $chat = self::CHAT): string
    {
        $pesan = $this->pesanKode($chat);
        $this->assertNotEmpty($pesan, 'Bot tidak mengirim kode masuk web.');
        $this->assertSame(1, preg_match('/Kode MASUK WEB SiGAP: (\d{6})/', (string) end($pesan)['text'], $m));

        return $m[1];
    }

    /** Alur penuh: csrf -> masuk -> kode. Mengembalikan respons langkah kode. */
    private function loginWeb(?User $user = null, string $identitas = 'siti.dapur'): TestResponse
    {
        $user ??= $this->siti;
        [$pra, $csrf] = $this->praLoginWeb();
        $tantangan = (string) $this->masuk($pra, $csrf, $identitas, self::SANDI)->assertOk()->json('tantangan.id');

        return $this->kode($pra, $csrf, $tantangan, $this->kodeWebTerakhir((int) $user->telegram_id));
    }

    private function salahKan(string $tantangan, string $benar): string
    {
        return $benar === '000000' ? '111111' : '000000';
    }

    // ---------------------------------------------------------------- csrf pra-login

    public function test_csrf_menerbitkan_cookie_pra_login_host_dan_token_terikat(): void
    {
        $sebelum = time();
        $res = $this->web('GET', '/api/v1/web/auth/csrf', [], ['X-Sigap-Klien' => 'web'])->assertOk()
            ->assertJsonStructure(['csrf', 'berlaku_sampai'])
            ->assertHeader('Cache-Control', 'no-store, private');

        $this->assertStringContainsString('Cookie', (string) $res->headers->get('Vary'));

        $cookie = $this->cookie($res, '__Host-sigap_pra');
        $this->assertNotNull($cookie);
        $this->assertTrue($cookie->isSecure());
        $this->assertTrue($cookie->isHttpOnly());
        $this->assertSame(Cookie::SAMESITE_STRICT, $cookie->getSameSite());
        $this->assertSame('/', $cookie->getPath());
        $this->assertNull($cookie->getDomain());
        // Expires dibangun dari time() nyata (Symfony), bukan jam beku Carbon; getMaxAge() membaca time() lagi sehingga
        // berubah bila detik berganti. Periksa Expires terhadap rentang time() nyata sebelum/sesudah permintaan.
        $sesudah = time();
        $this->assertGreaterThanOrEqual($sebelum + 15 * 60, $cookie->getExpiresTime());
        $this->assertLessThanOrEqual($sesudah + 15 * 60, $cookie->getExpiresTime());
        $this->assertMatchesRegularExpression('/^[A-Za-z0-9_-]{43}$/', (string) $cookie->getValue());
        $this->assertSame(SesiWeb::csrfPraLogin((string) $cookie->getValue()), $res->json('csrf'));

        // Setiap panggilan = id pra-login baru.
        [$a] = $this->praLoginWeb();
        [$b] = $this->praLoginWeb();
        $this->assertNotSame($a, $b);
    }

    public function test_semua_rute_web_auth_wajib_header_klien_web(): void
    {
        $this->web('GET', '/api/v1/web/auth/csrf')->assertForbidden()->assertJsonPath('alasan', 'csrf_ditolak');
        $this->web('GET', '/api/v1/web/auth/csrf', [], ['X-Sigap-Klien' => 'miniapp'])->assertForbidden();

        [$pra, $csrf] = $this->praLoginWeb();
        $tanpaKlien = ['Origin' => self::ORIGIN, 'X-CSRF-Token' => $csrf];

        $this->web('POST', '/api/v1/web/auth/masuk', $this->cookiePra($pra), $tanpaKlien, ['identitas' => 'siti.dapur', 'password' => self::SANDI])
            ->assertForbidden()->assertJsonPath('alasan', 'csrf_ditolak')->assertHeader('Cache-Control', 'no-store, private');
        $this->assertSame([], $this->pesanKode());
    }

    /** @return array<string, array{0: \Closure(string, string): array{0: array<string, string>, 1: array<string, string>}}> */
    public static function csrfPraLoginTidakSah(): array
    {
        $cookie = fn (string $pra) => [SesiWeb::namaCookie(SesiWeb::COOKIE_PRA) => $pra];
        $sah = fn (string $csrf) => ['Origin' => self::ORIGIN, 'X-Sigap-Klien' => 'web', 'X-CSRF-Token' => $csrf];

        return [
            'tanpa cookie pra-login' => [fn (string $pra, string $csrf) => [[], $sah($csrf)]],
            'cookie pra-login format salah' => [fn (string $pra, string $csrf) => [$cookie('pendek'), $sah(SesiWeb::csrfPraLogin('pendek'))]],
            'tanpa X-CSRF-Token' => [fn (string $pra, string $csrf) => [$cookie($pra), ['Origin' => self::ORIGIN, 'X-Sigap-Klien' => 'web']]],
            'X-CSRF-Token salah' => [fn (string $pra, string $csrf) => [$cookie($pra), $sah(str_repeat('a', 64))]],
            'CSRF milik pra-login lain' => [fn (string $pra, string $csrf) => [$cookie($pra), $sah(SesiWeb::csrfPraLogin(SesiWeb::idPraLoginBaru()))]],
            'CSRF sesi dipakai sebagai CSRF pra-login' => [fn (string $pra, string $csrf) => [$cookie($pra), $sah(SesiWeb::csrfSesi($pra))]],
            'tanpa Origin' => [fn (string $pra, string $csrf) => [$cookie($pra), ['X-Sigap-Klien' => 'web', 'X-CSRF-Token' => $csrf]]],
            'Origin asing' => [fn (string $pra, string $csrf) => [$cookie($pra), ['Origin' => 'https://jahat.example'] + $sah($csrf)]],
            'Origin null' => [fn (string $pra, string $csrf) => [$cookie($pra), ['Origin' => 'null'] + $sah($csrf)]],
        ];
    }

    /** @param \Closure(string, string): array{0: array<string, string>, 1: array<string, string>} $buat */
    #[DataProvider('csrfPraLoginTidakSah')]
    public function test_metode_pengubah_web_auth_wajib_csrf_pra_login(\Closure $buat): void
    {
        [$pra, $csrf] = $this->praLoginWeb();
        [$cookies, $headers] = $buat($pra, $csrf);

        foreach (['/api/v1/web/auth/masuk', '/api/v1/web/auth/kode', '/api/v1/web/auth/kode/kirim-ulang'] as $uri) {
            $this->web('POST', $uri, $cookies, $headers, ['identitas' => 'siti.dapur', 'password' => self::SANDI, 'tantangan_id' => (string) str()->ulid(), 'kode' => '123456'])
                ->assertForbidden()
                ->assertExactJson(['message' => 'Permintaan ditolak. Muat ulang halaman lalu coba lagi.', 'alasan' => 'csrf_ditolak']);
        }

        $this->assertSame([], $this->pesanKode());
        $this->assertSame(0, TantanganToken::query()->count());
    }

    public function test_bearer_atau_cookie_sesi_tidak_menggantikan_csrf_pra_login(): void
    {
        $bearer = $this->beriSesiPenuh($this->siti);
        $sesiWeb = app(SesiService::class)->terbitkanWeb($this->siti, 'Firefox')['cookie']->getValue();

        // Bearer Mini App sah tanpa CSRF pra-login -> tetap 403 (rute ini tidak memakai auth:sanctum).
        $this->web('POST', '/api/v1/web/auth/masuk', [], ['Authorization' => 'Bearer '.$bearer, 'Origin' => self::ORIGIN, 'X-Sigap-Klien' => 'web'], ['identitas' => 'siti.dapur', 'password' => self::SANDI])
            ->assertForbidden();

        // Cookie sesi web + CSRF sesinya juga tidak berlaku sebagai CSRF pra-login.
        $this->web('POST', '/api/v1/web/auth/masuk', [SesiWeb::namaCookieSesi() => $sesiWeb], $this->header(SesiWeb::csrfSesi($sesiWeb)), ['identitas' => 'siti.dapur', 'password' => self::SANDI])
            ->assertForbidden();

        // Cookie pra-login tidak pernah menjadi sesi.
        [$pra] = $this->praLoginWeb();
        $this->web('GET', '/api/v1/auth/me', [SesiWeb::namaCookieSesi() => $pra, ...$this->cookiePra($pra)], ['X-Sigap-Klien' => 'web'])->assertUnauthorized();

        $this->assertSame([], $this->pesanKode());
    }

    // ---------------------------------------------------------------- alur sukses + issue #76

    public function test_alur_sandi_lalu_kode_menerbitkan_cookie_sesi_tanpa_token_di_badan(): void
    {
        [$pra, $csrfPra] = $this->praLoginWeb();

        $masuk = $this->masuk($pra, $csrfPra, 'siti.dapur', self::SANDI)->assertOk()
            ->assertJsonPath('langkah', 'kode')
            ->assertJsonPath('message', 'Bila data benar, kode telah dikirim ke Telegram Anda. Tidak menerima kode dalam 1 menit? Periksa kembali sandi.')
            ->assertJsonPath('tantangan.kedaluwarsa_pada', now()->addMinutes(5)->toIso8601String())
            ->assertJsonPath('tantangan.kirim_ulang_tersedia_pada', now()->addSeconds(60)->toIso8601String())
            ->assertJsonPath('tantangan.maks_kirim_ulang', 3)
            ->assertJsonPath('tantangan.maks_percobaan', 5);
        $this->assertNull($this->cookie($masuk, SesiWeb::namaCookieSesi()));

        // Pesan bot: kode, label browser, domain resmi, tombol "Bukan saya", tidak dapat diteruskan.
        $pesan = $this->pesanKode();
        $this->assertCount(1, $pesan);
        $teks = (string) $pesan[0]['text'];
        $this->assertStringContainsString('Firefox · Linux', $teks);
        $this->assertStringContainsString('http://localhost', $teks);
        $this->assertStringContainsString('13-11-2026', $teks);
        $this->assertSame('true', $pesan[0]['protect_content']);
        $tombol = $this->markupOf($pesan[0])['inline_keyboard'][0][0];
        $this->assertSame('Bukan saya', $tombol['text']);
        $this->assertSame(MasukWebService::dataBukanSaya((string) $masuk->json('tantangan.id'), self::CHAT), $tombol['callback_data']);
        $this->assertLessThanOrEqual(64, strlen($tombol['callback_data']));

        $kode = $this->kodeWebTerakhir();
        $sebelum = time();
        $res = $this->kode($pra, $csrfPra, (string) $masuk->json('tantangan.id'), $kode)->assertOk()
            ->assertJsonPath('langkah', 'selesai')
            ->assertJsonPath('jenis_sesi', 'web')
            ->assertJsonPath('user.id', $this->siti->id)
            ->assertJsonPath('user.username', 'siti.dapur')
            ->assertJsonMissingPath('token')
            ->assertJsonMissingPath('token_type')
            ->assertHeader('Cache-Control', 'no-store, private');

        // Cookie sesi `__Host-` (S-15) dan token hanya di Set-Cookie (issue #76).
        $sesi = $this->cookie($res, '__Host-sigap_sesi');
        $this->assertNotNull($sesi);
        $this->assertTrue($sesi->isSecure() && $sesi->isHttpOnly());
        $this->assertSame(Cookie::SAMESITE_STRICT, $sesi->getSameSite());
        $this->assertSame('/', $sesi->getPath());
        $this->assertNull($sesi->getDomain());
        // Expires dibangun dari time() nyata (Symfony), bukan jam beku Carbon; getMaxAge() membaca time() lagi sehingga
        // berubah bila detik berganti. Periksa Expires terhadap rentang time() nyata sebelum/sesudah permintaan.
        $sesudah = time();
        $this->assertGreaterThanOrEqual($sebelum + 12 * 3600, $sesi->getExpiresTime());
        $this->assertLessThanOrEqual($sesudah + 12 * 3600, $sesi->getExpiresTime());

        $nilai = (string) $sesi->getValue();
        [, $rahasia] = explode('|', $nilai, 2);
        $badan = (string) $res->getContent();
        $this->assertStringNotContainsString($nilai, $badan);
        $this->assertStringNotContainsString($rahasia, $badan);
        $this->assertArrayNotHasKey('token', (array) $res->json());

        // S-21: cookie pra-login dihapus, CSRF baru terikat sesi (bukan CSRF pra-login).
        $praDihapus = $this->cookie($res, '__Host-sigap_pra');
        $this->assertNotNull($praDihapus);
        $this->assertTrue($praDihapus->isCleared());
        $this->assertSame(SesiWeb::csrfSesi($nilai), $res->json('csrf'));
        $this->assertNotSame($csrfPra, $res->json('csrf'));
        $this->assertSame(now()->addHours(12)->toIso8601String(), $res->json('expires_at'));

        // Token: web, tanpa perangkat Mini App; cookie dipakai di jalur cookie R04-1.
        $token = PersonalAccessToken::findToken($nilai);
        $this->assertTrue(SesiService::tokenWeb($token));
        $this->assertSame('web:Firefox · Linux', $token->name);
        $this->assertNull($token->perangkat_tepercaya_id);
        $this->web('GET', '/api/v1/auth/me', [SesiWeb::namaCookieSesi() => $nilai], ['X-Sigap-Klien' => 'web'])->assertOk()
            ->assertJsonPath('user.id', $this->siti->id)->assertJsonPath('csrf', $res->json('csrf'));

        // Token web lewat Bearer -> 401 (penolakan silang R04-1).
        $this->web('GET', '/api/v1/auth/me', [], ['Authorization' => 'Bearer '.$nilai])->assertUnauthorized();

        // Notifikasi login + audit tanpa kode/token.
        $this->assertTrue($this->messagesTo(self::CHAT)->contains(fn (HttpRequest $r) => str_starts_with((string) $r['text'], 'Login web SiGAP SPPG berhasil dari Firefox · Linux')));
        $this->assertSame(1, AuditLog::query()->where('event', AuditAuth::WEB_MASUK_BERHASIL)->count());
        $this->assertSame(1, AuditLog::query()->where('event', AuditAuth::WEB_KODE_DIKIRIM)->count());
        foreach (AuditLog::query()->get() as $log) {
            $isi = (string) json_encode($log->new_values);
            $this->assertStringNotContainsString($kode, $isi);
            $this->assertStringNotContainsString($rahasia, $isi);
            $this->assertStringNotContainsString(self::SANDI, $isi);
            $this->assertStringNotContainsString($pra, $isi);
        }

        // Mini App tidak tersentuh.
        $this->assertSame(0, $this->siti->perangkatTepercaya()->count());
        $this->assertSame(0, (int) $this->siti->fresh()->failed_login_attempts);
    }

    public function test_masuk_dengan_no_telepon_dalam_format_lain(): void
    {
        [$pra, $csrf] = $this->praLoginWeb();
        $tantangan = (string) $this->masuk($pra, $csrf, '0812-0000-1111', self::SANDI)->assertOk()->json('tantangan.id');

        $this->kode($pra, $csrf, $tantangan, $this->kodeWebTerakhir())->assertOk()->assertJsonPath('user.id', $this->siti->id);
    }

    public function test_kode_tersimpan_hanya_sebagai_hash_terikat_browser(): void
    {
        [$pra, $csrf] = $this->praLoginWeb();
        $id = (string) $this->masuk($pra, $csrf, 'siti.dapur', self::SANDI)->json('tantangan.id');
        $kode = $this->kodeWebTerakhir();

        $baris = DB::table('tantangan_tokens')->where('id', $id)->first();
        $this->assertSame(TantanganToken::TUJUAN_MASUK_WEB, $baris->tujuan);
        $this->assertNull($baris->personal_access_token_id);
        $this->assertNull($baris->payload);
        $this->assertSame(TokenSekaliPakaiService::hashKode($id, $kode), $baris->kode_hash);
        $this->assertSame(SesiWeb::hashPraLogin($pra), $baris->device_hash);
        $this->assertStringNotContainsString($kode, (string) json_encode($baris));
        $this->assertStringNotContainsString($pra, (string) json_encode($baris));
    }

    public function test_login_ulang_di_browser_yang_sama_mencabut_sesi_web_lama(): void
    {
        $lama = (string) $this->cookie($this->loginWeb()->assertOk(), SesiWeb::namaCookieSesi())->getValue();

        [$pra, $csrf] = $this->praLoginWeb();
        $tantangan = (string) $this->masuk($pra, $csrf, 'siti.dapur', self::SANDI)->json('tantangan.id');
        $this->kode($pra, $csrf, $tantangan, $this->kodeWebTerakhir(), [SesiWeb::namaCookieSesi() => $lama])->assertOk();

        $this->assertNull(PersonalAccessToken::findToken($lama));
        $this->assertSame(1, $this->siti->tokens()->where('name', 'like', 'web:%')->count());
    }

    // ---------------------------------------------------------------- anti-enumerasi (S-05)

    /** Badan + header yang relevan, dinormalkan dari id tantangan. */
    private function sidikRespons(TestResponse $res): array
    {
        $json = (array) $res->json();
        $this->assertMatchesRegularExpression('/^[0-9a-z]{26}$/', (string) ($json['tantangan']['id'] ?? ''));
        $json['tantangan']['id'] = 'ULID';

        return [
            'status' => $res->getStatusCode(),
            'json' => $json,
            'cache' => $res->headers->get('Cache-Control'),
            'vary' => $res->headers->get('Vary'),
            'type' => $res->headers->get('Content-Type'),
            'cookies' => array_map(fn (Cookie $c) => $c->getName(), $res->headers->getCookies()),
        ];
    }

    public function test_respons_langkah_masuk_identik_untuk_semua_kasus_dan_kode_hanya_dikirim_bila_sandi_benar(): void
    {
        $sppg = $this->siti->sppg_id;
        User::factory()->role(Role::AhliGizi)->denganKredensial('budi.nonaktif', self::SANDI)->create(['sppg_id' => $sppg, 'telegram_id' => 7000000001, 'is_active' => false]);
        User::factory()->role(Role::AhliGizi)->create(['sppg_id' => $sppg, 'telegram_id' => 7000000002, 'username' => 'belum.aktif']);
        User::factory()->role(Role::AhliGizi)->denganKredensial('tanpa.telegram', self::SANDI)->withoutTelegram()->create(['sppg_id' => $sppg]);
        $terkunci = User::factory()->role(Role::AhliGizi)->denganKredensial('rina.kunci', self::SANDI)->create(['sppg_id' => $sppg, 'telegram_id' => 7000000003]);
        DB::table('users')->where('id', $terkunci->id)->update(['web_gagal_login' => 5, 'web_terkunci_sampai' => now()->addMinutes(10)]);
        $hapus = User::factory()->role(Role::AhliGizi)->denganKredensial('dewi.hapus', self::SANDI)->create(['sppg_id' => $sppg, 'telegram_id' => 7000000004]);
        $hapus->delete();

        $kasus = [
            'username tidak ada' => ['tidak.ada', self::SANDI],
            'telepon tidak ada' => ['081299998888', self::SANDI],
            'identitas aneh' => ['%%%', self::SANDI],
            'sandi salah' => ['siti.dapur', 'SalahSekali9'],
            'akun nonaktif' => ['budi.nonaktif', self::SANDI],
            'belum aktivasi' => ['belum.aktif', self::SANDI],
            'tanpa telegram' => ['tanpa.telegram', self::SANDI],
            'kanal web terkunci' => ['rina.kunci', self::SANDI],
            'akun terhapus' => ['dewi.hapus', self::SANDI],
            'sandi benar' => ['siti.dapur', self::SANDI],
        ];

        $sidik = [];

        foreach ($kasus as $nama => [$identitas, $sandi]) {
            [$pra, $csrf] = $this->praLoginWeb();
            $sidik[$nama] = $this->sidikRespons($this->masuk($pra, $csrf, $identitas, $sandi)->assertOk());
        }

        $acuan = $sidik['sandi benar'];

        foreach ($sidik as $nama => $s) {
            $this->assertSame($acuan, $s, "Respons '{$nama}' berbeda dari 'sandi benar'.");
        }

        // Hanya satu pesan kode, hanya ke pemilik sandi benar; tantangan tersimpan hanya satu.
        $this->assertCount(1, $this->telegramCalls('sendMessage'));
        $this->assertCount(1, $this->pesanKode());
        $this->assertSame(1, TantanganToken::query()->count());

        // Setiap cabang benar-benar dilalui (alasan internal hanya di audit, tidak pernah di respons).
        $hasil = AuditLog::query()->where('event', AuditAuth::WEB_MASUK_LANGKAH1)->get()->map(fn (AuditLog $l) => $l->new_values['hasil'])->sort()->values()->all();
        $this->assertSame(['akun_nonaktif', 'belum_aktivasi', 'kode_dibuat', 'sandi_salah', 'telegram_belum_terhubung', 'terkunci'], $hasil);
    }

    /** @return array<string, array{0: string, 1: string}> */
    public static function cabangLangkahMasuk(): array
    {
        return [
            'akun tidak ada' => ['tidak.ada', self::SANDI],
            'sandi salah' => ['siti.dapur', 'SalahSekali9'],
            'akun nonaktif' => ['nonaktif', self::SANDI],
            'belum aktivasi' => ['belum', self::SANDI],
            'kanal web terkunci' => ['kunci', self::SANDI],
            'sandi benar' => ['siti.dapur', self::SANDI],
        ];
    }

    /** Waktu seragam: tepat satu Hash::check (sandi asli atau hash tiruan) di setiap cabang. */
    #[DataProvider('cabangLangkahMasuk')]
    public function test_setiap_cabang_langkah_masuk_memanggil_hash_check_tepat_sekali(string $identitas, string $sandi): void
    {
        $sppg = $this->siti->sppg_id;
        User::factory()->role(Role::AhliGizi)->denganKredensial('nonaktif', self::SANDI)->create(['sppg_id' => $sppg, 'telegram_id' => 7000000001, 'is_active' => false]);
        User::factory()->role(Role::AhliGizi)->create(['sppg_id' => $sppg, 'telegram_id' => 7000000002, 'username' => 'belum']);
        $kunci = User::factory()->role(Role::AhliGizi)->denganKredensial('kunci', self::SANDI)->create(['sppg_id' => $sppg, 'telegram_id' => 7000000003]);
        DB::table('users')->where('id', $kunci->id)->update(['web_gagal_login' => 5, 'web_terkunci_sampai' => now()->addMinutes(10)]);
        [$pra, $csrf] = $this->praLoginWeb();
        $hitung = new class($this->app) extends HashManager
        {
            public int $cek = 0;

            public function check($value, $hashedValue, array $options = []): bool
            {
                $this->cek++;

                return parent::check($value, $hashedValue, $options);
            }
        };
        Hash::swap($hitung);

        $this->masuk($pra, $csrf, $identitas, $sandi)->assertOk();

        $this->assertSame(1, $hitung->cek);
    }

    // ---------------------------------------------------------------- kode (S-06)

    public function test_kode_sekali_pakai_replay_ditolak_seragam(): void
    {
        [$pra, $csrf] = $this->praLoginWeb();
        $id = (string) $this->masuk($pra, $csrf, 'siti.dapur', self::SANDI)->json('tantangan.id');
        $kode = $this->kodeWebTerakhir();

        $this->kode($pra, $csrf, $id, $kode)->assertOk();

        $this->kode($pra, $csrf, $id, $kode)->assertStatus(422)
            ->assertExactJson(['message' => 'Kode salah atau kedaluwarsa.', 'errors' => ['kode' => ['Kode salah atau kedaluwarsa.']], 'alasan' => 'kode_tidak_valid']);
        $this->assertSame(1, $this->siti->tokens()->count());
    }

    public function test_semua_kegagalan_kode_memberi_galat_yang_sama(): void
    {
        $galat = ['message' => 'Kode salah atau kedaluwarsa.', 'errors' => ['kode' => ['Kode salah atau kedaluwarsa.']], 'alasan' => 'kode_tidak_valid'];

        [$pra, $csrf] = $this->praLoginWeb();
        $id = (string) $this->masuk($pra, $csrf, 'siti.dapur', self::SANDI)->json('tantangan.id');
        $kode = $this->kodeWebTerakhir();

        // Kode salah.
        $this->kode($pra, $csrf, $id, $this->salahKan($id, $kode))->assertStatus(422)->assertExactJson($galat);
        // Tantangan tiruan (dari sandi salah) dan id acak.
        [$pra2, $csrf2] = $this->praLoginWeb();
        $tiruan = (string) $this->masuk($pra2, $csrf2, 'siti.dapur', 'SalahSekali9')->json('tantangan.id');
        $this->kode($pra2, $csrf2, $tiruan, $kode)->assertStatus(422)->assertExactJson($galat);
        $this->kode($pra, $csrf, (string) str()->ulid(), $kode)->assertStatus(422)->assertExactJson($galat);
        // Tantangan milik browser lain (cookie pra-login lain), walau kode benar.
        $this->kode($pra2, $csrf2, $id, $kode)->assertStatus(422)->assertExactJson($galat);
        // Tantangan Mini App tidak dapat dipakai di web.
        $praMini = $this->praLogin($this->siti, self::HP);
        $idMini = (string) $this->kirim($praMini, '/api/v1/auth/masuk', ['identitas' => 'siti.dapur', 'password' => self::SANDI, 'device_id' => self::HP])->assertStatus(202)->json('tantangan.id');
        $this->kode($pra, $csrf, $idMini, $this->kodeTerakhir(self::CHAT))->assertStatus(422)->assertExactJson($galat);

        // Kode web tetap berlaku untuk browser pemiliknya (gagal di atas tidak menghanguskannya: 1 salah < 5).
        $this->kode($pra, $csrf, $id, $kode)->assertOk();
    }

    public function test_kode_kedaluwarsa_setelah_5_menit(): void
    {
        [$pra, $csrf] = $this->praLoginWeb();
        $id = (string) $this->masuk($pra, $csrf, 'siti.dapur', self::SANDI)->json('tantangan.id');
        $kode = $this->kodeWebTerakhir();

        $this->travel(5)->minutes();
        $this->travel(1)->seconds();

        $this->kode($pra, $csrf, $id, $kode)->assertStatus(422)->assertJsonPath('alasan', 'kode_tidak_valid');
        $this->assertSame(0, $this->siti->tokens()->count());
    }

    public function test_lima_kode_salah_menghanguskan_tantangan(): void
    {
        [$pra, $csrf] = $this->praLoginWeb();
        $id = (string) $this->masuk($pra, $csrf, 'siti.dapur', self::SANDI)->json('tantangan.id');
        $kode = $this->kodeWebTerakhir();

        for ($i = 0; $i < 5; $i++) {
            $this->kode($pra, $csrf, $id, $this->salahKan($id, $kode))->assertStatus(422)->assertJsonPath('alasan', 'kode_tidak_valid');
        }

        $this->assertNotNull(TantanganToken::query()->find($id)->hangus_pada);
        $this->kode($pra, $csrf, $id, $kode)->assertStatus(422);
        $this->assertSame(0, $this->siti->tokens()->count());
        // 5 salah + 1 percobaan pada tantangan hangus.
        $this->assertSame(6, AuditLog::query()->where('event', AuditAuth::WEB_KODE_GAGAL)->where('auditable_id', $this->siti->id)->count());
    }

    // ---------------------------------------------------------------- kirim ulang

    public function test_kirim_ulang_memberi_kode_baru_dengan_jeda_dan_batas_serta_respons_seragam(): void
    {
        [$pra, $csrf] = $this->praLoginWeb();
        $masuk = $this->masuk($pra, $csrf, 'siti.dapur', self::SANDI);
        $id = (string) $masuk->json('tantangan.id');
        $kodeLama = $this->kodeWebTerakhir();

        // Sebelum jeda 60 detik: 200 bentuk sama, tanpa pesan.
        $awal = $this->kirimUlang($pra, $csrf, $id)->assertOk();
        $this->assertSame($this->sidikRespons($masuk), $this->sidikRespons($awal));
        $this->assertCount(1, $this->pesanKode());

        for ($i = 1; $i <= 3; $i++) {
            $this->travel(61)->seconds();
            $this->kirimUlang($pra, $csrf, $id)->assertOk()->assertJsonPath('tantangan.id', $id);
            $this->assertCount(1 + $i, $this->pesanKode());
        }

        // Batas 3 kirim ulang.
        $this->travel(61)->seconds();
        $this->kirimUlang($pra, $csrf, $id)->assertOk();
        $this->assertCount(4, $this->pesanKode());

        // Kode lama tidak berlaku; kode terbaru berlaku.
        $kodeBaru = $this->kodeWebTerakhir();
        if ($kodeBaru !== $kodeLama) {
            $this->kode($pra, $csrf, $id, $kodeLama)->assertStatus(422);
        }
        $this->kode($pra, $csrf, $id, $kodeBaru)->assertOk();
    }

    public function test_kirim_ulang_tantangan_tiruan_atau_browser_lain_tidak_mengirim_pesan(): void
    {
        [$pra, $csrf] = $this->praLoginWeb();
        $tiruan = (string) $this->masuk($pra, $csrf, 'siti.dapur', 'SalahSekali9')->json('tantangan.id');
        [$praB, $csrfB] = $this->praLoginWeb();
        $id = (string) $this->masuk($praB, $csrfB, 'siti.dapur', self::SANDI)->json('tantangan.id');
        $this->travel(61)->seconds();

        $res = $this->kirimUlang($pra, $csrf, $tiruan)->assertOk()->assertJsonPath('langkah', 'kode')->assertJsonPath('tantangan.id', $tiruan);
        $lain = $this->kirimUlang($pra, $csrf, $id)->assertOk()->assertJsonPath('tantangan.id', $id);

        $this->assertSame($this->sidikRespons($res), $this->sidikRespons($lain));
        $this->assertCount(1, $this->pesanKode());
    }

    // ---------------------------------------------------------------- penguncian kanal web (S-07)

    public function test_lima_sandi_salah_mengunci_kanal_web_saja_dan_mini_app_tetap_bisa_masuk(): void
    {
        for ($i = 0; $i < 5; $i++) {
            [$pra, $csrf] = $this->praLoginWeb();
            $this->masuk($pra, $csrf, 'siti.dapur', 'SalahSekali9')->assertOk();
        }

        $segar = $this->siti->fresh();
        $this->assertNotNull($segar->web_terkunci_sampai);
        $this->assertTrue($segar->web_terkunci_sampai->equalTo(now()->addMinutes(15)));
        $this->assertSame(1, AuditLog::query()->where('event', AuditAuth::WEB_TERKUNCI)->count());
        // Penguncian Mini App tidak tersentuh.
        $this->assertSame(0, (int) $segar->failed_login_attempts);
        $this->assertNull($segar->locked_until);

        // Sandi benar saat terkunci: respons sama, kode tidak dikirim.
        [$pra, $csrf] = $this->praLoginWeb();
        $this->masuk($pra, $csrf, 'siti.dapur', self::SANDI)->assertOk()->assertJsonPath('langkah', 'kode');
        $this->assertSame([], $this->pesanKode());

        // Mini App tetap bisa login penuh (initData + sandi + token bot).
        $token = $this->loginPenuh($this->siti, 'siti.dapur', self::SANDI);
        $this->assertNotSame('', $token);

        // Setelah 15 menit kanal web terbuka lagi.
        $this->travel(15)->minutes();
        $this->travel(1)->seconds();
        $this->loginWeb()->assertOk();
        $this->assertSame(0, (int) $this->siti->fresh()->web_gagal_login);
    }

    public function test_terkunci_lagi_dalam_24_jam_menjadi_60_menit(): void
    {
        $gagal5 = function (): void {
            for ($i = 0; $i < 5; $i++) {
                [$pra, $csrf] = $this->praLoginWeb();
                $this->masuk($pra, $csrf, 'siti.dapur', 'SalahSekali9')->assertOk();
            }
        };

        $gagal5();
        $this->assertTrue($this->siti->fresh()->web_terkunci_sampai->equalTo(now()->addMinutes(15)));

        $this->travel(16)->minutes();
        $gagal5();
        $this->assertTrue($this->siti->fresh()->web_terkunci_sampai->equalTo(now()->addMinutes(60)));
        $this->assertSame(2, AuditLog::query()->where('event', AuditAuth::WEB_TERKUNCI)->count());
    }

    public function test_kode_salah_ikut_menghitung_penguncian_web_dan_kode_benar_ditolak_saat_terkunci(): void
    {
        // 4 kode salah pada tantangan pertama, lalu sandi salah sekali -> 5 gagal -> terkunci.
        [$pra, $csrf] = $this->praLoginWeb();
        $id = (string) $this->masuk($pra, $csrf, 'siti.dapur', self::SANDI)->json('tantangan.id');
        $kode = $this->kodeWebTerakhir();

        for ($i = 0; $i < 4; $i++) {
            $this->kode($pra, $csrf, $id, $this->salahKan($id, $kode))->assertStatus(422);
        }

        [$pra2, $csrf2] = $this->praLoginWeb();
        $this->masuk($pra2, $csrf2, 'siti.dapur', 'SalahSekali9')->assertOk();
        $this->assertNotNull($this->siti->fresh()->web_terkunci_sampai);

        // Kode benar pada tantangan yang masih terbuka -> ditolak seragam selama terkunci.
        $this->kode($pra, $csrf, $id, $kode)->assertStatus(422)->assertJsonPath('alasan', 'kode_tidak_valid');
        $this->assertSame(0, $this->siti->tokens()->count());
    }

    // ---------------------------------------------------------------- batas laju

    public function test_batas_laju_masuk_per_identitas_dan_per_ip(): void
    {
        config([
            'kredensial.web.throttle.masuk_per_identitas' => 5,
            'kredensial.web.throttle.masuk_per_ip_per_menit' => 10,
        ]);
        [$pra, $csrf] = $this->praLoginWeb();

        for ($i = 0; $i < 5; $i++) {
            $this->masuk($pra, $csrf, 'Siti.Dapur', 'SalahSekali9')->assertOk();
        }

        // Identitas sama (ter-normalisasi: huruf besar/kecil, @) -> 429; identitas lain dari IP sama masih boleh.
        $this->masuk($pra, $csrf, '@siti.dapur', self::SANDI)->assertStatus(429)->assertHeader('Retry-After');
        $this->masuk($pra, $csrf, 'tidak.ada', self::SANDI)->assertOk();

        for ($i = 0; $i < 4; $i++) {
            $this->masuk($pra, $csrf, 'lain'.$i, self::SANDI)->assertOk();
        }

        // 10 per menit per IP.
        $this->masuk($pra, $csrf, 'lagi.lain', self::SANDI)->assertStatus(429);
    }

    // ---------------------------------------------------------------- validasi

    public function test_validasi_format_masuk_dan_kode(): void
    {
        [$pra, $csrf] = $this->praLoginWeb();

        $this->masuk($pra, $csrf, '', '')->assertStatus(422)->assertJsonValidationErrors(['identitas', 'password']);
        $this->kode($pra, $csrf, 'bukan-ulid', '12ab')->assertStatus(422)->assertJsonValidationErrors(['tantangan_id', 'kode']);
        $this->kirimUlang($pra, $csrf, '')->assertStatus(422)->assertJsonValidationErrors(['tantangan_id']);
        $this->assertSame([], $this->pesanKode());
    }

    // ---------------------------------------------------------------- tombol "Bukan saya" (S-19)

    /** @return array<string, mixed> */
    private function klik(string $data, int $fromId = self::CHAT): array
    {
        return [
            'update_id' => random_int(1000, 999999),
            'callback_query' => [
                'id' => 'cb-'.random_int(1, 99999),
                'from' => ['id' => $fromId, 'is_bot' => false, 'first_name' => 'Siti'],
                'message' => ['message_id' => 77, 'chat' => ['id' => $fromId, 'type' => 'private'], 'date' => time()],
                'data' => $data,
            ],
        ];
    }

    public function test_bukan_saya_membatalkan_tantangan_tanpa_mengunci_akun(): void
    {
        [$pra, $csrf] = $this->praLoginWeb();
        $id = (string) $this->masuk($pra, $csrf, 'siti.dapur', self::SANDI)->json('tantangan.id');
        $kode = $this->kodeWebTerakhir();
        $data = $this->markupOf($this->pesanKode()[0])['inline_keyboard'][0][0]['callback_data'];

        $this->webhook($this->klik($data))->assertOk();

        $this->assertNotNull(TantanganToken::query()->find($id)->hangus_pada);
        $this->assertSame(1, AuditLog::query()->where('event', AuditAuth::WEB_BUKAN_SAYA)->count());
        $edit = $this->telegramCalls('editMessageText')->last();
        $this->assertSame(BotCallbackHandler::BUKAN_SAYA_DIBATALKAN, $edit['text']);
        $this->assertStringNotContainsString($kode, (string) $edit['text']);

        $this->kode($pra, $csrf, $id, $kode)->assertStatus(422);

        // Akun tidak dikunci (kanal web dan Mini App).
        $segar = $this->siti->fresh();
        $this->assertNull($segar->web_terkunci_sampai);
        $this->assertNull($segar->locked_until);
        $this->loginWeb()->assertOk();
    }

    public function test_bukan_saya_dari_akun_telegram_lain_atau_data_palsu_ditolak(): void
    {
        [$pra, $csrf] = $this->praLoginWeb();
        $id = (string) $this->masuk($pra, $csrf, 'siti.dapur', self::SANDI)->json('tantangan.id');
        $lain = User::factory()->role(Role::AhliGizi)->denganKredensial('budi.gizi')->create(['sppg_id' => $this->siti->sppg_id, 'telegram_id' => 7000000009]);

        // Data sah milik Siti diteruskan oleh akun lain -> MAC tidak cocok.
        $this->webhook($this->klik(MasukWebService::dataBukanSaya($id, self::CHAT), (int) $lain->telegram_id))->assertOk();
        // Data dibuat untuk akun lain atas tantangan Siti -> bukan pemilik.
        $this->webhook($this->klik(MasukWebService::dataBukanSaya($id, (int) $lain->telegram_id), (int) $lain->telegram_id))->assertOk();
        // MAC palsu.
        $this->webhook($this->klik('w1.'.$id.'.AAAAAAAA'))->assertOk();

        $this->assertNull(TantanganToken::query()->find($id)->hangus_pada);
        $this->assertSame(0, AuditLog::query()->where('event', AuditAuth::WEB_BUKAN_SAYA)->count());
        $this->assertTrue($this->telegramCalls('answerCallbackQuery')->every(fn (HttpRequest $r) => $r['text'] === BotCallbackHandler::TIDAK_BERLAKU));
        $this->kode($pra, $csrf, $id, $this->kodeWebTerakhir())->assertOk();
    }

    public function test_bukan_saya_setelah_kode_dipakai_memberi_peringatan(): void
    {
        $this->loginWeb()->assertOk();
        $data = $this->markupOf($this->pesanKode()[0])['inline_keyboard'][0][0]['callback_data'];

        $this->webhook($this->klik($data))->assertOk();

        $this->assertSame(BotCallbackHandler::BUKAN_SAYA_SUDAH_DIPAKAI, $this->telegramCalls('editMessageText')->last()['text']);
        $this->assertSame('sudah_dipakai', AuditLog::query()->where('event', AuditAuth::WEB_BUKAN_SAYA)->first()->new_values['status']);
    }

    // ---------------------------------------------------------------- lain-lain

    public function test_gagal_kirim_bot_menghanguskan_tantangan_tanpa_mengubah_respons(): void
    {
        $this->fakeTelegram(['api.telegram.org/*/sendMessage' => Http::response(['ok' => false, 'error_code' => 403, 'description' => 'Forbidden: bot was blocked by the user'], 403)]);
        [$pra, $csrf] = $this->praLoginWeb();

        $id = (string) $this->masuk($pra, $csrf, 'siti.dapur', self::SANDI)->assertOk()->assertJsonPath('langkah', 'kode')->json('tantangan.id');

        $this->assertNotNull(TantanganToken::query()->find($id)->hangus_pada);
        $this->assertSame(1, AuditLog::query()->where('event', AuditAuth::WEB_KODE_GAGAL_KIRIM)->count());
    }

    public function test_ubah_password_menghanguskan_tantangan_web_terbuka(): void
    {
        [$pra, $csrf] = $this->praLoginWeb();
        $id = (string) $this->masuk($pra, $csrf, 'siti.dapur', self::SANDI)->json('tantangan.id');
        $kode = $this->kodeWebTerakhir();

        $this->siti->forceFill(['password' => Hash::make('BaruSekali123')])->save();

        $this->kode($pra, $csrf, $id, $kode)->assertStatus(422);
    }

    public function test_label_browser_dari_user_agent(): void
    {
        $this->assertSame('Firefox · Linux', LabelBrowser::dari(self::UA));
        $this->assertSame('Edge · Windows', LabelBrowser::dari('Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/130.0 Safari/537.36 Edg/130.0'));
        $this->assertSame('Chrome · Android', LabelBrowser::dari('Mozilla/5.0 (Linux; Android 14) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/130.0 Mobile Safari/537.36'));
        $this->assertSame('Safari · iOS', LabelBrowser::dari('Mozilla/5.0 (iPhone; CPU iPhone OS 17_0 like Mac OS X) AppleWebKit/605.1.15 (KHTML, like Gecko) Version/17.0 Mobile/15E148 Safari/604.1'));
        $this->assertSame('Browser', LabelBrowser::dari('<script>alert(1)</script>'));
        $this->assertSame('Browser', LabelBrowser::dari(null));
    }
}
