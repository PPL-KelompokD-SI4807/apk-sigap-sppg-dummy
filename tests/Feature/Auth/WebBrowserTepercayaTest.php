<?php

namespace Tests\Feature\Auth;

use App\Enums\Role;
use App\Models\AuditLog;
use App\Models\BrowserTepercaya;
use App\Models\User;
use App\Services\Auth\AuditAuth;
use App\Services\Auth\BrowserTepercayaService;
use App\Services\Auth\KredensialService;
use App\Services\Auth\SesiService;
use App\Services\Auth\SesiWeb;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request as HttpRequest;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Testing\TestResponse;
use Laravel\Sanctum\PersonalAccessToken;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Component\HttpFoundation\Cookie;
use Tests\Support\AlurLogin;
use Tests\Support\CreatesSppg;
use Tests\TestCase;

/**
 * R04-3 browser tepercaya "Ingat browser ini 30 hari" (ADR 2026-11-09 Keputusan 6, A2, Q1-Q3; rencana S-08, S-09,
 * S-10, S-12, S-15): hanya melewati kode (sandi tetap wajib), cookie `__Host-sigap_tb` (selektor.validator, validator
 * disimpan SHA-256), tetap 30 hari, maks 5 terpisah dari kuota sesi, Admin dikecualikan, pencabutan otomatis dan
 * berantai, sesi turunan bebas idle 12 jam tetapi tidak melewati berlaku_sampai browser. Juga issue #79 (invarian
 * jenis sesi) dan #78 (audit sesi mencatat jenis).
 */
class WebBrowserTepercayaTest extends TestCase
{
    use AlurLogin, CreatesSppg, RefreshDatabase;

    private const ORIGIN = 'http://localhost';

    private const UA = 'Mozilla/5.0 (X11; Linux x86_64; rv:132.0) Gecko/20100101 Firefox/132.0';

    private const SANDI = 'Rahasia123x';

    private const CHAT = 7012345678;

    private const CHAT_BUDI = 7012345670;

    private const CHAT_ADMIN = 7012345679;

    private User $siti;

    private User $budi;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo(Carbon::parse('2026-11-13 08:00:00'));
        $this->fakeTelegram();

        config([
            'kredensial.web.throttle.per_ip_per_menit' => 1000,
            'kredensial.web.throttle.masuk_per_ip_per_menit' => 1000,
            'kredensial.web.throttle.masuk_per_ip_per_jam' => 1000,
            'kredensial.web.throttle.masuk_per_identitas' => 1000,
            'kredensial.web.throttle.kode_per_ip_per_menit' => 1000,
            'kredensial.token.maks_tantangan' => 1000,
        ]);

        $sppg = $this->createSppg('SPPG Sukamaju');
        $this->siti = User::factory()->role(Role::KepalaSppg)->denganKredensial('siti.dapur', self::SANDI)->create(['sppg_id' => $sppg, 'telegram_id' => self::CHAT]);
        $this->budi = User::factory()->role(Role::KepalaSppg)->denganKredensial('budi.dapur', self::SANDI)->create(['sppg_id' => $sppg, 'telegram_id' => self::CHAT_BUDI]);
        $this->admin = User::factory()->admin()->denganKredensial('admin.pusat', self::SANDI)->create(['telegram_id' => self::CHAT_ADMIN]);
    }

    // ---------------------------------------------------------------- helper

    /**
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

    /** Permintaan API jalur cookie sesi web (metode pengubah: Origin + CSRF sesi). */
    private function api(string $method, string $uri, string $sesi, array $body = [], array $cookieTambahan = []): TestResponse
    {
        $headers = ['X-Sigap-Klien' => 'web'];

        if ($method !== 'GET') {
            $headers += ['Origin' => self::ORIGIN, 'X-CSRF-Token' => SesiWeb::csrfSesi($sesi)];
        }

        return $this->web($method, $uri, [SesiWeb::namaCookieSesi() => $sesi] + $cookieTambahan, $headers, $body);
    }

    /** @return array{0: string, 1: string} */
    private function praLoginWeb(): array
    {
        $res = $this->web('GET', '/api/v1/web/auth/csrf', [], ['X-Sigap-Klien' => 'web'])->assertOk();

        return [(string) $this->cookie($res, SesiWeb::namaCookie(SesiWeb::COOKIE_PRA))?->getValue(), (string) $res->json('csrf')];
    }

    /** @return array<string, string> */
    private function header(string $csrf): array
    {
        return ['Origin' => self::ORIGIN, 'X-Sigap-Klien' => 'web', 'X-CSRF-Token' => $csrf];
    }

    private function cookie(TestResponse $res, string $nama): ?Cookie
    {
        return collect($res->headers->getCookies())->first(fn (Cookie $c) => $c->getName() === $nama);
    }

    private function cookieTb(TestResponse $res): ?Cookie
    {
        return $this->cookie($res, '__Host-sigap_tb');
    }

    private function cookieSesi(TestResponse $res): ?Cookie
    {
        return $this->cookie($res, '__Host-sigap_sesi');
    }

    /** @return array<string, string> */
    private function tb(?string $nilai): array
    {
        return $nilai === null ? [] : ['__Host-sigap_tb' => $nilai];
    }

    private function masuk(string $identitas, string $sandi, ?string $tb = null, ?string $pra = null, ?string $csrf = null): TestResponse
    {
        if ($pra === null) {
            [$pra, $csrf] = $this->praLoginWeb();
        }

        return $this->web('POST', '/api/v1/web/auth/masuk', [SesiWeb::namaCookie(SesiWeb::COOKIE_PRA) => $pra] + $this->tb($tb), $this->header((string) $csrf), ['identitas' => $identitas, 'password' => $sandi]);
    }

    /** @return list<HttpRequest> */
    private function pesanKode(int $chat): array
    {
        return $this->messagesTo($chat)->filter(fn (HttpRequest $r) => str_contains((string) $r['text'], 'Kode MASUK WEB SiGAP'))->values()->all();
    }

    private function kodeWeb(int $chat): string
    {
        $pesan = $this->pesanKode($chat);
        $this->assertNotEmpty($pesan, 'Bot tidak mengirim kode masuk web.');
        preg_match('/Kode MASUK WEB SiGAP: (\d{6})/', (string) end($pesan)['text'], $m);

        return $m[1];
    }

    /** Alur penuh sandi + kode. */
    private function loginKode(User $user, string $identitas, bool $ingat, ?string $tb = null): TestResponse
    {
        [$pra, $csrf] = $this->praLoginWeb();
        $masuk = $this->masuk($identitas, self::SANDI, $tb, $pra, $csrf)->assertOk()->assertJsonPath('langkah', 'kode');

        return $this->web('POST', '/api/v1/web/auth/kode', [SesiWeb::namaCookie(SesiWeb::COOKIE_PRA) => $pra] + $this->tb($tb), $this->header($csrf), array_filter([
            'tantangan_id' => (string) $masuk->json('tantangan.id'),
            'kode' => $this->kodeWeb((int) $user->telegram_id),
            'ingat_browser' => $ingat ?: null,
        ], fn ($v) => $v !== null));
    }

    /** @return array{tb: string, sesi: string} login kode dengan "Ingat browser ini" */
    private function ingat(?User $user = null, string $identitas = 'siti.dapur'): array
    {
        $res = $this->loginKode($user ?? $this->siti, $identitas, true)->assertOk();

        return ['tb' => (string) $this->cookieTb($res)?->getValue(), 'sesi' => (string) $this->cookieSesi($res)?->getValue()];
    }

    private function sesiAda(string $nilai): bool
    {
        return PersonalAccessToken::findToken($nilai) !== null;
    }

    private function auditAda(string $event, ?User $user = null): bool
    {
        return AuditLog::query()->withoutGlobalScopes()->where('event', $event)->where('auditable_id', ($user ?? $this->siti)->id)->exists();
    }

    // ---------------------------------------------------------------- pembuatan (S-08, S-15)

    public function test_ingat_browser_membuat_cookie_host_dan_validator_tersimpan_sha256(): void
    {
        $sebelum = time();
        $res = $this->loginKode($this->siti, 'siti.dapur', true)->assertOk()
            ->assertJsonPath('langkah', 'selesai')
            ->assertJsonPath('browser_tepercaya.dipakai', false)
            ->assertJsonPath('browser_tepercaya.diingat', true);

        $cookie = $this->cookieTb($res);
        $this->assertNotNull($cookie);
        $this->assertTrue($cookie->isSecure());
        $this->assertTrue($cookie->isHttpOnly());
        $this->assertSame(Cookie::SAMESITE_STRICT, $cookie->getSameSite());
        $this->assertSame('/', $cookie->getPath());
        $this->assertNull($cookie->getDomain());
        // Expires dibangun dari time() nyata (Symfony), bukan jam beku Carbon; getMaxAge() membaca time() lagi sehingga
        // berubah bila detik berganti. Periksa Expires terhadap rentang time() nyata sebelum/sesudah permintaan.
        $sesudah = time();
        $this->assertGreaterThanOrEqual($sebelum + 30 * 86400, $cookie->getExpiresTime());
        $this->assertLessThanOrEqual($sesudah + 30 * 86400, $cookie->getExpiresTime());
        $this->assertMatchesRegularExpression('/^[A-Za-z0-9_-]{22}\.[A-Za-z0-9_-]{43}$/', (string) $cookie->getValue());

        [$selektor, $validator] = explode('.', (string) $cookie->getValue());
        $baris = BrowserTepercaya::query()->sole();
        $this->assertSame($this->siti->id, $baris->user_id);
        $this->assertSame($selektor, $baris->selektor);
        $this->assertSame(hash('sha256', $validator), $baris->validator_hash);
        $this->assertSame('2026-12-13 08:00:00', $baris->berlaku_sampai->format('Y-m-d H:i:s'));
        $this->assertSame('Firefox · Linux', $baris->label);
        $this->assertSame($res->json('browser_tepercaya.berlaku_sampai'), $baris->berlaku_sampai->toIso8601String());

        // Sesi login ini menjadi sesi turunan browser tepercaya.
        $sesi = PersonalAccessToken::findToken((string) $this->cookieSesi($res)?->getValue());
        $this->assertSame($baris->id, (int) $sesi->browser_tepercaya_id);

        // Nilai cookie/validator tidak pernah ada di respons JSON atau audit.
        $this->assertStringNotContainsString($validator, (string) $res->getContent());
        $this->assertStringNotContainsString($selektor, (string) $res->getContent());
        $semuaAudit = AuditLog::query()->withoutGlobalScopes()->get()->toJson();
        $this->assertStringNotContainsString($validator, $semuaAudit);
        $this->assertStringNotContainsString($selektor, $semuaAudit);
        $this->assertTrue($this->auditAda(AuditAuth::WEB_BROWSER_DIBUAT));
    }

    public function test_bawaan_mati_tanpa_ingat_browser_tidak_ada_baris_dan_cookie(): void
    {
        $res = $this->loginKode($this->siti, 'siti.dapur', false)->assertOk()->assertJsonPath('browser_tepercaya.diingat', false);

        $this->assertNull($this->cookieTb($res));
        $this->assertSame(0, BrowserTepercaya::query()->count());
        $this->assertNull(PersonalAccessToken::findToken((string) $this->cookieSesi($res)?->getValue())->browser_tepercaya_id);
    }

    // ---------------------------------------------------------------- melewati kode (S-08)

    public function test_browser_tepercaya_dengan_sandi_benar_melewati_kode_tanpa_menggeser_masa(): void
    {
        $awal = $this->ingat();
        $kodeAwal = count($this->pesanKode(self::CHAT));
        $baris = BrowserTepercaya::query()->sole();

        $this->travel(10)->days();
        $res = $this->masuk('siti.dapur', self::SANDI, $awal['tb'])->assertOk()
            ->assertJsonPath('langkah', 'selesai')
            ->assertJsonPath('jenis_sesi', 'web')
            ->assertJsonPath('browser_tepercaya.dipakai', true)
            ->assertJsonStructure(['csrf', 'expires_at', 'user' => ['id']]);

        $this->assertCount($kodeAwal, $this->pesanKode(self::CHAT), 'Tidak ada kode yang dikirim.');
        $sesi = (string) $this->cookieSesi($res)?->getValue();
        $this->assertNotSame('', $sesi);
        $this->assertNotNull($this->cookie($res, '__Host-sigap_pra'), 'Cookie pra-login dihapus (Max-Age=0).');
        $this->assertSame(SesiWeb::csrfSesi($sesi), $res->json('csrf'));
        $this->assertStringNotContainsString($sesi, (string) $res->getContent());

        $baris->refresh();
        $this->assertSame('2026-12-13 08:00:00', $baris->berlaku_sampai->format('Y-m-d H:i:s'), 'berlaku_sampai tidak bergeser saat dipakai.');
        $this->assertSame('2026-11-23 08:00:00', $baris->terakhir_dipakai_at->format('Y-m-d H:i:s'));
        $this->assertSame($baris->id, (int) PersonalAccessToken::findToken($sesi)->browser_tepercaya_id);
        $this->assertTrue($this->auditAda(AuditAuth::WEB_BROWSER_DIPAKAI));
        $this->assertTrue(AuditLog::query()->withoutGlobalScopes()->where('event', AuditAuth::WEB_MASUK_BERHASIL)->get()
            ->contains(fn (AuditLog $a) => ($a->new_values['jalur'] ?? null) === 'browser_tepercaya'));
        $this->assertTrue($this->messagesTo(self::CHAT)->contains(fn (HttpRequest $r) => str_contains((string) $r['text'], 'browser tepercaya')));

        $this->api('GET', '/api/v1/auth/me', $sesi)->assertOk();
        // Sesi lama dari browser ini (cookie sesi lama tidak dibawa di tes ini) tetap; kuota web mengurusnya.
        $this->assertTrue($this->sesiAda($awal['sesi']));
    }

    public function test_sandi_salah_pada_browser_tepercaya_422_dan_dihitung_gagal(): void
    {
        $awal = $this->ingat();

        $this->masuk('siti.dapur', 'SalahSekali9', $awal['tb'])
            ->assertStatus(422)
            ->assertJsonPath('alasan', 'kredensial_salah')
            ->assertJsonPath('message', 'Identitas atau sandi salah.');

        $this->assertSame(1, (int) DB::table('users')->where('id', $this->siti->id)->value('web_gagal_login'));
        $this->assertSame(1, PersonalAccessToken::query()->where('name', 'like', 'web:%')->count());
    }

    public function test_pencurian_cookie_tidak_melewati_sandi_dan_tetap_terkunci(): void
    {
        $awal = $this->ingat();
        $sebelum = PersonalAccessToken::query()->count();

        // Cookie saja tidak membuka API apa pun.
        $this->web('GET', '/api/v1/auth/me', $this->tb($awal['tb']), ['X-Sigap-Klien' => 'web'])->assertUnauthorized();

        // Penebak sandi dengan cookie curian: 5 gagal -> kanal web terkunci.
        foreach (range(1, 5) as $i) {
            $this->masuk('siti.dapur', 'Tebakan'.$i.'xx', $awal['tb'])->assertStatus(422);
        }

        // Terkunci: bahkan sandi benar tidak menerbitkan sesi (respons seragam langkah kode, tanpa kode terkirim).
        $kode = count($this->pesanKode(self::CHAT));
        $this->masuk('siti.dapur', self::SANDI, $awal['tb'])->assertOk()->assertJsonPath('langkah', 'kode');
        $this->assertCount($kode, $this->pesanKode(self::CHAT));
        $this->assertSame($sebelum, PersonalAccessToken::query()->count());
        $this->assertTrue($this->auditAda(AuditAuth::WEB_TERKUNCI));

        // Cookie yang dirusak (validator salah) tidak berlaku dan dihapus dari browser.
        [$sel] = explode('.', $awal['tb']);
        $this->travel(2)->hours();
        $res = $this->masuk('siti.dapur', self::SANDI, $sel.'.'.str_repeat('A', 43))->assertOk()->assertJsonPath('langkah', 'kode');
        $this->assertSame(0, $this->cookieTb($res)?->getMaxAge() ?? -1);
    }

    public function test_cookie_milik_akun_a_untuk_akun_b_tetap_langkah_kode(): void
    {
        $siti = $this->ingat();

        $res = $this->masuk('budi.dapur', self::SANDI, $siti['tb'])->assertOk()->assertJsonPath('langkah', 'kode');
        $this->assertNull($this->cookieTb($res), 'Cookie milik A tidak dihapus (tidak membedakan pemilik).');
        $this->assertCount(1, $this->pesanKode(self::CHAT_BUDI));
        $this->assertNull(BrowserTepercaya::query()->sole()->dicabut_at);

        // Sandi salah untuk akun B dengan cookie A: respons seragam (bukan 422 khusus browser tepercaya).
        $this->masuk('budi.dapur', 'SalahSekali9', $siti['tb'])->assertOk()->assertJsonPath('langkah', 'kode');
    }

    public function test_lewat_30_hari_kembali_ke_langkah_kode_dan_cookie_dihapus(): void
    {
        $awal = $this->ingat();

        $this->travel(30)->days();
        $this->travel(1)->minutes();
        $res = $this->masuk('siti.dapur', self::SANDI, $awal['tb'])->assertOk()->assertJsonPath('langkah', 'kode');

        $this->assertSame(0, $this->cookieTb($res)?->getMaxAge());
        $this->assertCount(2, $this->pesanKode(self::CHAT));
    }

    public function test_ingat_lagi_mengganti_browser_lama_yang_sama(): void
    {
        $awal = $this->ingat();
        $lama = BrowserTepercaya::query()->sole();

        // Browser yang sama ingat ulang lewat jalur kode (cookie tb hanya dibawa di langkah kode): baris lama dicabut
        // `diganti` beserta sesi turunannya.
        $this->travel(1)->days();
        [$pra, $csrf] = $this->praLoginWeb();
        $tantangan = (string) $this->masuk('siti.dapur', self::SANDI, null, $pra, $csrf)->assertJsonPath('langkah', 'kode')->json('tantangan.id');
        $res = $this->web('POST', '/api/v1/web/auth/kode', [SesiWeb::namaCookie(SesiWeb::COOKIE_PRA) => $pra] + $this->tb($awal['tb']), $this->header($csrf), [
            'tantangan_id' => $tantangan, 'kode' => $this->kodeWeb(self::CHAT), 'ingat_browser' => true,
        ])->assertOk();
        $this->assertFalse($this->sesiAda($awal['sesi']));

        $this->assertSame(BrowserTepercayaService::ALASAN_DIGANTI, $lama->refresh()->alasan_cabut);
        $this->assertNotSame($awal['tb'], $this->cookieTb($res)?->getValue());
        $this->assertSame(1, BrowserTepercaya::query()->aktif()->count());
    }

    // ---------------------------------------------------------------- Admin (S-10, Q1)

    public function test_admin_ingat_browser_diabaikan_tanpa_baris_dan_cookie(): void
    {
        $res = $this->loginKode($this->admin, 'admin.pusat', true)->assertOk()
            ->assertJsonPath('browser_tepercaya.diingat', false)
            ->assertJsonPath('browser_tepercaya.alasan', 'admin_selalu_kode');

        $this->assertNull($this->cookieTb($res));
        $this->assertSame(0, BrowserTepercaya::query()->count());

        // Sesi web Admin selalu terkena idle 12 jam.
        $sesi = PersonalAccessToken::findToken((string) $this->cookieSesi($res)?->getValue());
        $this->assertNull($sesi->browser_tepercaya_id);
        $this->assertTrue($sesi->expires_at->lte(now()->addHours(12)));

        $this->expectException(\DomainException::class);
        app(BrowserTepercayaService::class)->buat($this->admin, 'Firefox');
    }

    public function test_pengguna_menjadi_admin_browser_dicabut_dan_kode_tetap_diminta(): void
    {
        $awal = $this->ingat();

        $this->siti->forceFill(['role' => Role::Admin, 'sppg_id' => null])->save();

        $this->assertSame('hak_akses_berubah', BrowserTepercaya::query()->sole()->alasan_cabut);
        $this->assertFalse($this->sesiAda($awal['sesi']));

        $this->masuk('siti.dapur', self::SANDI, $awal['tb'])->assertOk()->assertJsonPath('langkah', 'kode');
    }

    public function test_baris_browser_milik_admin_tidak_pernah_berlaku_fail_closed(): void
    {
        // Baris tidak wajar (dibuat langsung, tidak lewat service) milik Admin: tetap tidak melewati kode.
        $nilai = $this->barisLangsung($this->admin);

        $res = $this->masuk('admin.pusat', self::SANDI, $nilai)->assertOk()->assertJsonPath('langkah', 'kode');
        $this->assertSame(BrowserTepercayaService::ALASAN_ADMIN, BrowserTepercaya::query()->sole()->alasan_cabut);
        $this->assertSame(0, $this->cookieTb($res)?->getMaxAge());
    }

    private function barisLangsung(User $user): string
    {
        $selektor = str_repeat('s', 22);
        $validator = str_repeat('v', 43);
        $b = new BrowserTepercaya;
        $b->forceFill([
            'user_id' => $user->id, 'selektor' => $selektor, 'validator_hash' => hash('sha256', $validator),
            'diverifikasi_pada' => now(), 'berlaku_sampai' => now()->addDays(30),
        ])->save();

        return $selektor.'.'.$validator;
    }

    // ---------------------------------------------------------------- kuota (S-08, Q2)

    public function test_kuota_maks_5_browser_ke_6_mencabut_tertua_dan_tidak_menyentuh_kuota_sesi(): void
    {
        $layanan = app(BrowserTepercayaService::class);
        $mini = $this->beriSesiPenuh($this->siti, 'perangkat-1-aaaaaaaaaaaaaaaaaaaaaaaa');
        $id = [];

        foreach (range(1, 6) as $i) {
            $id[$i] = (int) $layanan->buat($this->siti, 'Browser '.$i)['browser']->getKey();
            $this->travel(1)->minutes();
        }

        $this->assertSame(5, $this->siti->browserTepercaya()->aktif()->count());
        $this->assertSame(BrowserTepercayaService::ALASAN_BATAS, BrowserTepercaya::query()->find($id[1])->alasan_cabut);
        $this->assertTrue($this->sesiAda($mini), 'Kuota browser tepercaya tidak mencabut sesi Mini App.');

        // Kuota sesi web (3) tidak mencabut browser tepercaya.
        $sesi = [];
        foreach (range(2, 6) as $i) {
            $sesi[$i] = app(SesiService::class)->terbitkanWeb($this->siti, 'B', BrowserTepercaya::query()->find($id[$i]))['cookie']->getValue();
            $this->travel(1)->minutes();
        }

        $this->assertSame(3, PersonalAccessToken::query()->where('name', 'like', 'web:%')->count());
        $this->assertSame(5, $this->siti->browserTepercaya()->aktif()->count());
    }

    // ---------------------------------------------------------------- masa sesi turunan (S-12, S-22, Q3)

    public function test_sesi_turunan_bebas_idle_12_jam_tetapi_tidak_melewati_berlaku_sampai_browser(): void
    {
        $awal = $this->ingat();
        $token = PersonalAccessToken::findToken($awal['sesi']);
        $this->assertSame('2026-12-13 08:00:00', $token->expires_at->format('Y-m-d H:i:s'), 'expires_at dijepit ke berlaku_sampai browser (bukan +12 jam).');

        // 20 jam tidak dipakai: sesi biasa sudah mati, sesi browser tepercaya tetap hidup.
        $this->travel(20)->hours();
        $this->api('GET', '/api/v1/auth/me', $awal['sesi'])->assertOk();
        $this->api('GET', '/api/v1/auth/sesi', $awal['sesi'])->assertOk()->assertJsonPath('data.0.browser_tepercaya_id', BrowserTepercaya::query()->sole()->id);

        // Dipakai terus hingga lewat berlaku_sampai browser -> berakhir, walau 90 hari sliding belum habis.
        foreach (range(1, 5) as $i) {
            $this->travel(5)->days();
            $this->api('GET', '/api/v1/auth/me', $awal['sesi'])->assertOk();
        }
        $this->assertTrue(PersonalAccessToken::findToken($awal['sesi'])->expires_at->lte(Carbon::parse('2026-12-13 08:00:00')));

        $this->travelTo(Carbon::parse('2026-12-13 08:00:01'));
        $this->api('GET', '/api/v1/auth/me', $awal['sesi'])->assertUnauthorized();
        $this->assertFalse($this->sesiAda($awal['sesi']));
    }

    public function test_browser_dicabut_langsung_membuat_sesi_turunan_401_fail_closed(): void
    {
        $awal = $this->ingat();

        // Hanya baris browser yang ditandai dicabut (token sengaja tidak dihapus): validasi tetap menolak.
        BrowserTepercaya::query()->update(['dicabut_at' => now()]);
        $this->api('GET', '/api/v1/auth/me', $awal['sesi'])->assertUnauthorized();
    }

    public function test_logout_web_tidak_mencabut_browser_tepercaya(): void
    {
        $awal = $this->ingat();

        $this->api('POST', '/api/v1/auth/logout', $awal['sesi'])->assertOk();
        $this->assertFalse($this->sesiAda($awal['sesi']));
        $this->assertNull(BrowserTepercaya::query()->sole()->dicabut_at);
        $this->assertSame('web', AuditLog::query()->withoutGlobalScopes()->where('event', AuditAuth::LOGOUT)->sole()->new_values['jenis']);

        $this->masuk('siti.dapur', self::SANDI, $awal['tb'])->assertOk()->assertJsonPath('langkah', 'selesai');
    }

    // ---------------------------------------------------------------- pencabutan otomatis (S-09)

    /** @return array<string, array{0: string, 1: string}> */
    public static function pemicuPencabutan(): array
    {
        return [
            'peran berubah' => ['peran', 'hak_akses_berubah'],
            'status nonaktif' => ['nonaktif', 'hak_akses_berubah'],
            'sppg berubah' => ['sppg', 'hak_akses_berubah'],
            'telegram_id berubah' => ['telegram', 'hak_akses_berubah'],
            'password direset admin' => ['reset', 'password_direset'],
            'admin mencabut semua sesi' => ['admin_cabut', 'dicabut_admin'],
            'akun dihapus' => ['hapus', 'akun_dihapus'],
            'akun dipulihkan' => ['pulihkan', 'akun_dipulihkan'],
        ];
    }

    #[DataProvider('pemicuPencabutan')]
    public function test_pencabutan_otomatis_mencabut_browser_dan_sesi_turunan(string $pemicu, string $alasan): void
    {
        $awal = $this->ingat();

        match ($pemicu) {
            'peran' => $this->siti->forceFill(['role' => Role::AhliGizi])->save(),
            'nonaktif' => $this->siti->forceFill(['is_active' => false])->save(),
            'sppg' => $this->siti->forceFill(['sppg_id' => $this->createSppg('SPPG Lain')])->save(),
            'telegram' => $this->siti->forceFill(['telegram_id' => 7099999999])->save(),
            'reset' => app(KredensialService::class)->resetPassword($this->siti, $this->admin),
            'admin_cabut' => app(KredensialService::class)->cabutSesiOlehAdmin($this->admin, $this->siti),
            'hapus' => $this->siti->delete(),
            'pulihkan' => $this->pulihkan(),
        };

        $baris = BrowserTepercaya::query()->sole();
        $this->assertNotNull($baris->dicabut_at);
        $this->assertSame($alasan, $baris->alasan_cabut);
        $this->assertFalse($this->sesiAda($awal['sesi']));
        $this->assertTrue(AuditLog::query()->withoutGlobalScopes()->where('event', AuditAuth::WEB_BROWSER_DICABUT)->get()
            ->contains(fn (AuditLog $a) => ($a->new_values['pemicu'] ?? null) === $alasan));
    }

    private function pulihkan(): void
    {
        // Pulihkan memicu pencabutan juga untuk browser yang belum dicabut (baris dibuat ulang setelah hapus).
        $this->siti->deleteQuietly();
        $this->siti->restore();
    }

    public function test_ubah_password_dari_browser_tepercaya_mencabut_semua_browser_sesi_ini_tetap_sebagai_sesi_biasa(): void
    {
        $lain = $this->ingat();
        $this->travel(1)->minutes();
        $ini = $this->ingat();

        $this->api('PUT', '/api/v1/auth/password', $ini['sesi'], ['password_lama' => self::SANDI, 'password' => 'SandiBaru456y', 'password_confirmation' => 'SandiBaru456y'])->assertOk();

        $this->assertSame(0, BrowserTepercaya::query()->aktif()->count());
        $this->assertSame(['password_diubah'], BrowserTepercaya::query()->pluck('alasan_cabut')->unique()->values()->all());
        $this->assertFalse($this->sesiAda($lain['sesi']));

        // Sesi yang mengubah sandi tetap, tetapi tidak lagi turunan browser tepercaya (kembali terkena idle 12 jam).
        $token = PersonalAccessToken::findToken($ini['sesi']);
        $this->assertNotNull($token);
        $this->assertNull($token->browser_tepercaya_id);
        $this->api('GET', '/api/v1/auth/me', $ini['sesi'])->assertOk();
        $this->travel(13)->hours();
        $this->api('GET', '/api/v1/auth/me', $ini['sesi'])->assertUnauthorized();
    }

    public function test_keluar_semua_mencabut_browser_tepercaya(): void
    {
        $awal = $this->ingat();

        $this->api('POST', '/api/v1/auth/keluar-semua', $awal['sesi'])->assertOk();

        $this->assertSame('keluar_semua', BrowserTepercaya::query()->sole()->alasan_cabut);
        $this->masuk('siti.dapur', self::SANDI, $awal['tb'])->assertOk()->assertJsonPath('langkah', 'kode');
    }

    public function test_perintah_bersih_harian_menandai_browser_kedaluwarsa(): void
    {
        $awal = $this->ingat();
        $this->travel(31)->days();

        $this->artisan('sigap:bersihkan-browser-tepercaya')->assertSuccessful();

        $this->assertSame(BrowserTepercayaService::ALASAN_KEDALUWARSA, BrowserTepercaya::query()->sole()->alasan_cabut);
        $this->assertFalse($this->sesiAda($awal['sesi']));
        $this->artisan('sigap:bersihkan-browser-tepercaya')->expectsOutputToContain('0')->assertSuccessful();
    }

    // ---------------------------------------------------------------- daftar + cabut satu

    public function test_daftar_dan_cabut_satu_browser_milik_sendiri(): void
    {
        $lain = $this->ingat();
        $this->travel(1)->minutes();
        $ini = $this->ingat();

        $res = $this->api('GET', '/api/v1/auth/browser-tepercaya', $ini['sesi'])->assertOk()
            ->assertJsonCount(2, 'data')
            ->assertJsonPath('meta.kuota', 5)
            ->assertJsonPath('meta.masa_hari', 30);
        $this->assertSame(['id', 'label', 'diverifikasi_pada', 'berlaku_sampai', 'terakhir_dipakai_pada', 'browser_ini'], array_keys($res->json('data.0')));
        $this->assertSame(1, collect($res->json('data'))->where('browser_ini', true)->count());
        $this->assertStringNotContainsString(explode('.', $ini['tb'])[0], (string) $res->getContent());

        $idLain = (int) collect($res->json('data'))->firstWhere('browser_ini', false)['id'];
        $idIni = (int) collect($res->json('data'))->firstWhere('browser_ini', true)['id'];

        // Milik akun lain -> 404 (IDOR).
        $budi = $this->ingat($this->budi, 'budi.dapur');
        $this->api('DELETE', '/api/v1/auth/browser-tepercaya/'.$idLain, $budi['sesi'])->assertNotFound();
        $this->assertNull(BrowserTepercaya::query()->find($idLain)->dicabut_at);

        // Cabut browser lain: sesi turunannya ikut dicabut, sesi ini tetap.
        $this->api('DELETE', '/api/v1/auth/browser-tepercaya/'.$idLain, $ini['sesi'])->assertOk()->assertJsonPath('browser_ini', false);
        $this->assertSame(BrowserTepercayaService::ALASAN_DICABUT_PENGGUNA, BrowserTepercaya::query()->find($idLain)->alasan_cabut);
        $this->assertFalse($this->sesiAda($lain['sesi']));
        $this->assertTrue($this->sesiAda($ini['sesi']));
        $this->api('DELETE', '/api/v1/auth/browser-tepercaya/'.$idLain, $ini['sesi'])->assertNotFound();

        // Cabut browser ini: sesi ini berakhir, cookie sesi + cookie tb dihapus.
        $res = $this->api('DELETE', '/api/v1/auth/browser-tepercaya/'.$idIni, $ini['sesi'])->assertOk()->assertJsonPath('browser_ini', true);
        $this->assertSame(0, $this->cookieTb($res)?->getMaxAge());
        $this->assertSame(0, $this->cookieSesi($res)?->getMaxAge());
        $this->assertFalse($this->sesiAda($ini['sesi']));
    }

    public function test_rute_browser_tepercaya_wajib_login(): void
    {
        $this->getJson('/api/v1/auth/browser-tepercaya')->assertUnauthorized();
        $this->deleteJson('/api/v1/auth/browser-tepercaya/1')->assertUnauthorized();
    }

    // ---------------------------------------------------------------- issue #79 + #78

    public function test_invarian_jenis_sesi_nama_web_jika_dan_hanya_jika_ability_klien_web(): void
    {
        $web = app(SesiService::class)->terbitkanWeb($this->siti, 'Firefox')['cookie']->getValue();
        $mini = $this->beriSesiPenuh($this->siti, 'perangkat-1-aaaaaaaaaaaaaaaaaaaaaaaa');
        $this->ingat();

        foreach (PersonalAccessToken::query()->where(fn ($q) => SesiService::hanyaSesiLogin($q))->get() as $t) {
            $this->assertTrue(SesiService::jenisKonsisten($t), "Token {$t->name} melanggar invarian jenis.");
            $this->assertSame(str_starts_with($t->name, 'web:') ? 'web' : 'miniapp', SesiService::jenis($t));
        }

        // Token yang melanggar invarian ditolak di kedua jalur (fail closed).
        $tokenWeb = PersonalAccessToken::findToken($web);
        $tokenWeb->forceFill(['name' => 'sesi:palsu'])->save();
        $this->api('GET', '/api/v1/auth/me', $web)->assertUnauthorized();

        $tokenMini = PersonalAccessToken::findToken($mini);
        $tokenMini->forceFill(['name' => 'web:palsu'])->save();
        $this->app['auth']->forgetGuards();
        $this->withToken($mini)->getJson('/api/v1/auth/me')->assertUnauthorized();
    }

    public function test_audit_sesi_mencatat_jenis(): void
    {
        $a = app(SesiService::class)->terbitkanWeb($this->siti, 'Firefox')['cookie']->getValue();
        $b = app(SesiService::class)->terbitkanWeb($this->siti, 'Chrome')['cookie']->getValue();
        $mini = $this->beriSesiPenuh($this->siti, 'perangkat-1-aaaaaaaaaaaaaaaaaaaaaaaa');

        $this->api('DELETE', '/api/v1/auth/sesi/'.PersonalAccessToken::findToken($a)->id, $b)->assertOk();
        $this->assertSame('web', AuditLog::query()->withoutGlobalScopes()->where('event', AuditAuth::SESI_DICABUT)->sole()->new_values['jenis']);

        $this->travel(1)->days();
        $this->app['auth']->forgetGuards();
        $this->withToken($mini)->getJson('/api/v1/auth/me')->assertOk();
        $this->assertSame('miniapp', AuditLog::query()->withoutGlobalScopes()->where('event', AuditAuth::SESI_DIPERPANJANG)->latest('id')->first()->new_values['jenis']);
    }
}
