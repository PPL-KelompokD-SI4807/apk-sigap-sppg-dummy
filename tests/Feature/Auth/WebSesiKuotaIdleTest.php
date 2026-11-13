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
use Illuminate\Testing\TestResponse;
use Laravel\Sanctum\PersonalAccessToken;
use Symfony\Component\HttpFoundation\Cookie;
use Tests\Support\CreatesSppg;
use Tests\TestCase;

/**
 * R04-1 perluasan SesiService untuk sesi web: kuota terpisah 5 Mini App + 3 web (S-11, Q2), idle 12 jam untuk
 * sesi web (S-22, Q3), jeda tulis <= 15 menit, Max-Age cookie mengikuti expires_at, batas 90/30/180 tetap (S-12,
 * lewat titik ekstensi pengecualian idle R04-3), dan `GET auth/sesi` berjenis.
 * Catatan: uji serentak lockForUpdate hanya bermakna di MySQL 8.4 staging (SQLite tanpa SELECT ... FOR UPDATE).
 */
class WebSesiKuotaIdleTest extends TestCase
{
    use CreatesSppg, RefreshDatabase;

    private User $siti;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo(Carbon::parse('2026-11-09 08:00:00'));

        $this->siti = User::factory()->role(Role::KepalaSppg)->denganKredensial('siti.dapur')->create([
            'sppg_id' => $this->createSppg('SPPG Sukamaju'),
            'telegram_id' => 7012345678,
        ]);
        $this->admin = User::factory()->admin()->denganKredensial('admin.pusat')->create(['telegram_id' => 7012345679]);
    }

    private function sesiWeb(?User $user = null, string $label = 'Firefox · Linux'): string
    {
        return app(SesiService::class)->terbitkanWeb($user ?? $this->siti, $label)['cookie']->getValue();
    }

    private function sesiMiniApp(int $ke, ?User $user = null): string
    {
        return $this->beriSesiPenuh($user ?? $this->siti, 'perangkat-'.$ke.'-aaaaaaaaaaaaaaaaaaaaaaaa');
    }

    private function web(string $method, string $uri, string $sesi): TestResponse
    {
        $this->app['auth']->forgetGuards();
        $this->flushHeaders();
        // Jalur cookie selalu wajib `X-Sigap-Klien: web`; metode pengubah + Origin dan CSRF.
        $headers = ['Accept' => 'application/json', 'X-Sigap-Klien' => 'web'];

        if ($method !== 'GET') {
            $headers += ['Origin' => 'http://localhost', 'X-CSRF-Token' => SesiWeb::csrfSesi($sesi)];
        }

        return $this->call($method, $uri, [], [SesiWeb::namaCookieSesi() => $sesi], [], $this->transformHeadersToServerVars($headers));
    }

    private function bearer(string $token, string $uri = '/api/v1/auth/me'): TestResponse
    {
        $this->app['auth']->forgetGuards();
        $this->flushHeaders();

        return $this->withToken($token)->getJson($uri);
    }

    private function ada(string $token): bool
    {
        return PersonalAccessToken::findToken($token) !== null;
    }

    private function cookie(TestResponse $res): ?Cookie
    {
        return collect($res->headers->getCookies())->first(fn (Cookie $c) => $c->getName() === SesiWeb::namaCookieSesi());
    }

    /** Bind SesiService yang mengecualikan semua sesi web dari idle (simulasi browser tepercaya R04-3). */
    private function kecualikanIdle(): void
    {
        $this->app->instance(SesiService::class, new class extends SesiService
        {
            protected function dikecualikanDariIdle(PersonalAccessToken $token): bool
            {
                return true;
            }
        });
    }

    // ---------------------------------------------------------------- S-11 kuota terpisah (Q2)

    public function test_s11_login_mini_app_ke_6_hanya_mencabut_sesi_mini_app_terlama(): void
    {
        $web = [];
        foreach ([1, 2, 3] as $i) {
            $web[$i] = $this->sesiWeb();
            $this->travel(1)->minutes();
        }

        $mini = [];
        foreach ([1, 2, 3, 4, 5, 6] as $i) {
            $mini[$i] = $this->sesiMiniApp($i);
            $this->travel(1)->minutes();
        }

        $this->assertFalse($this->ada($mini[1]));
        foreach ([2, 3, 4, 5, 6] as $i) {
            $this->assertTrue($this->ada($mini[$i]));
        }
        foreach ([1, 2, 3] as $i) {
            $this->assertTrue($this->ada($web[$i]), "Sesi web {$i} tidak boleh dicabut login Mini App.");
        }
    }

    public function test_s11_login_web_ke_4_hanya_mencabut_sesi_web_paling_lama_tidak_dipakai(): void
    {
        $mini = [];
        foreach ([1, 2, 3, 4, 5] as $i) {
            $mini[$i] = $this->sesiMiniApp($i);
            $this->travel(1)->minutes();
        }

        $web = [];
        foreach ([1, 2, 3] as $i) {
            $web[$i] = $this->sesiWeb();
            $this->travel(1)->minutes();
        }

        // Sesi web 1 dipakai -> sesi web 2 menjadi yang paling lama tidak dipakai.
        $this->web('GET', '/api/v1/auth/me', $web[1])->assertOk();
        $this->travel(1)->minutes();

        $web[4] = $this->sesiWeb();

        $this->assertFalse($this->ada($web[2]));
        foreach ([1, 3, 4] as $i) {
            $this->assertTrue($this->ada($web[$i]));
        }
        foreach ([1, 2, 3, 4, 5] as $i) {
            $this->assertTrue($this->ada($mini[$i]), "Sesi Mini App {$i} tidak boleh dicabut login web.");
        }

        $log = AuditLog::query()->withoutGlobalScopes()->where('event', AuditAuth::SESI_DICABUT_BATAS)->sole();
        $this->assertSame(3, $log->new_values['maks_sesi']);
        $this->assertSame('web', $log->new_values['jenis']);
        $this->assertSame(PersonalAccessToken::query()->count(), 8);
    }

    public function test_s11_env_tidak_dapat_menaikkan_batas_di_atas_5_dan_3(): void
    {
        config(['kredensial.sesi.maks_per_pengguna' => 10, 'kredensial.sesi.maks_web_per_pengguna' => 10]);
        $sesi = app(SesiService::class);
        $this->assertSame(5, $sesi->maksSesi());
        $this->assertSame(3, $sesi->maksSesiWeb());

        $web = [];
        foreach ([1, 2, 3, 4, 5] as $i) {
            $web[$i] = $this->sesiWeb();
            $this->travel(1)->minutes();
        }
        $this->assertSame(3, $this->siti->tokens()->where('name', 'like', 'web:%')->count());
        $this->assertFalse($this->ada($web[1]));
        $this->assertFalse($this->ada($web[2]));

        config(['kredensial.sesi.idle_web_jam' => 48]);
        $this->assertSame(12, $sesi->idleWebJam());
    }

    public function test_s11_env_boleh_mengetatkan_dan_sesi_baru_tidak_pernah_ikut_dicabut(): void
    {
        config(['kredensial.sesi.maks_web_per_pengguna' => 1]);
        $mini = $this->sesiMiniApp(1);
        $a = $this->sesiWeb();
        $this->travel(1)->minutes();
        $b = $this->sesiWeb();

        $this->assertFalse($this->ada($a));
        $this->assertTrue($this->ada($b));
        $this->assertTrue($this->ada($mini));
        $this->web('GET', '/api/v1/auth/me', $b)->assertOk();

        config(['kredensial.sesi.idle_web_jam' => 2]);
        $this->assertSame(2, app(SesiService::class)->idleWebJam());
    }

    public function test_s11_sesi_web_kedaluwarsa_tidak_dihitung_dalam_kuota(): void
    {
        $a = $this->sesiWeb();
        $b = $this->sesiWeb();
        $this->travel(13)->hours();
        $c = $this->sesiWeb();
        $d = $this->sesiWeb();
        $e = $this->sesiWeb();

        // a dan b sudah idle: dibersihkan, bukan dicabut karena batas.
        $this->assertFalse($this->ada($a));
        $this->assertFalse($this->ada($b));
        foreach ([$c, $d, $e] as $t) {
            $this->assertTrue($this->ada($t));
        }
        $this->assertSame(0, AuditLog::query()->withoutGlobalScopes()->where('event', AuditAuth::SESI_DICABUT_BATAS)->count());
    }

    public function test_terbitkan_web_menolak_akun_nonaktif(): void
    {
        $this->siti->forceFill(['is_active' => false])->saveQuietly();

        $this->expectException(\DomainException::class);
        $this->sesiWeb();
    }

    // ---------------------------------------------------------------- S-22 idle 12 jam (Q3)

    public function test_s22_sesi_web_tidak_dipakai_12_jam_lebih_1_menit_ditolak_dan_dicabut(): void
    {
        $sesi = $this->sesiWeb();
        $this->assertTrue(PersonalAccessToken::findToken($sesi)->expires_at->equalTo(now()->addHours(12)));

        $this->travel(12 * 60 + 1)->minutes();

        $res = $this->web('GET', '/api/v1/auth/me', $sesi)->assertUnauthorized()
            ->assertExactJson(['message' => 'Sesi Anda telah berakhir. Silakan masuk kembali.', 'alasan' => 'sesi_kedaluwarsa']);
        $this->assertTrue($this->cookie($res)?->isCleared());
        $this->assertFalse($this->ada($sesi));
    }

    public function test_s22_idle_dihitung_dari_pemakaian_terakhir_tanpa_bergantung_expires_at(): void
    {
        $sesi = $this->sesiWeb();
        // Simulasi expires_at longgar (mis. data lama): idle tetap ditegakkan dari last_used_at/created_at.
        PersonalAccessToken::findToken($sesi)->forceFill(['expires_at' => now()->addDays(90)])->save();

        $this->travel(12 * 60 + 1)->minutes();
        $this->web('GET', '/api/v1/auth/me', $sesi)->assertUnauthorized()->assertJsonPath('alasan', 'sesi_kedaluwarsa');
        $this->assertFalse($this->ada($sesi));
    }

    public function test_s22_sesi_web_yang_dipakai_tiap_kurang_dari_12_jam_tetap_hidup(): void
    {
        $sesi = $this->sesiWeb();

        for ($i = 0; $i < 6; $i++) {
            $this->travel(11)->hours();
            $res = $this->web('GET', '/api/v1/auth/me', $sesi)->assertOk()
                ->assertJsonPath('expires_at', now()->addHours(12)->toIso8601String());
            $this->assertEqualsWithDelta(12 * 3600, $this->cookie($res)?->getMaxAge(), 2);
        }

        $this->travel(12 * 60 + 1)->minutes();
        $this->web('GET', '/api/v1/auth/me', $sesi)->assertUnauthorized();
    }

    public function test_s22_sesi_web_admin_selalu_terkena_idle_12_jam(): void
    {
        $sesi = $this->sesiWeb($this->admin);
        $this->assertTrue(PersonalAccessToken::findToken($sesi)->expires_at->equalTo(now()->addHours(12)));

        $this->travel(11)->hours();
        $this->web('GET', '/api/v1/auth/me', $sesi)->assertOk();

        $this->travel(12 * 60 + 1)->minutes();
        $this->web('GET', '/api/v1/auth/me', $sesi)->assertUnauthorized()->assertJsonPath('alasan', 'sesi_kedaluwarsa');
    }

    public function test_s22_sesi_mini_app_tidak_terkena_idle_12_jam(): void
    {
        $mini = $this->sesiMiniApp(1);

        $this->travel(13)->hours();
        $this->bearer($mini)->assertOk();

        $this->travel(20)->days();
        $this->bearer($mini)->assertOk();
        $this->assertTrue(PersonalAccessToken::findToken($mini)->expires_at->equalTo(now()->addDays(90)));
    }

    public function test_jeda_tulis_last_used_at_token_web_paling_lama_15_menit_dan_cookie_hanya_diterbitkan_saat_menulis(): void
    {
        $sesi = $this->sesiWeb();

        $this->travel(1)->minutes();
        $pertama = now()->copy();
        $this->assertNotNull($this->cookie($this->web('GET', '/api/v1/auth/me', $sesi)->assertOk()));
        $this->assertTrue(PersonalAccessToken::findToken($sesi)->last_used_at->equalTo($pertama));

        $this->travel(10)->minutes();
        $this->assertNull($this->cookie($this->web('GET', '/api/v1/auth/me', $sesi)->assertOk()));
        $this->assertTrue(PersonalAccessToken::findToken($sesi)->last_used_at->equalTo($pertama));

        $this->travel(6)->minutes();
        $res = $this->web('GET', '/api/v1/auth/me', $sesi)->assertOk();
        $this->assertTrue(PersonalAccessToken::findToken($sesi)->last_used_at->equalTo(now()));
        $this->assertTrue(PersonalAccessToken::findToken($sesi)->expires_at->equalTo(now()->addHours(12)));
        $this->assertEqualsWithDelta(12 * 3600, $this->cookie($res)?->getMaxAge(), 2);
    }

    public function test_jeda_tulis_mini_app_tetap_60_menit(): void
    {
        $mini = $this->sesiMiniApp(1);
        $this->bearer($mini)->assertOk();
        $awal = PersonalAccessToken::findToken($mini)->last_used_at->copy();

        $this->travel(30)->minutes();
        $this->bearer($mini)->assertOk();
        $this->assertTrue(PersonalAccessToken::findToken($mini)->last_used_at->equalTo($awal));
    }

    // ---------------------------------------------------------------- S-12 batas 90/30/180 (pengecualian idle R04-3)

    public function test_s12_sesi_web_dikecualikan_idle_mengikuti_90_hari_bergeser_dan_absolut_180_dengan_max_age_mengikuti(): void
    {
        $this->kecualikanIdle();
        $login = now()->copy();
        $sesi = $this->sesiWeb();

        $this->travel(1)->hours();
        $res = $this->web('GET', '/api/v1/auth/me', $sesi)->assertOk()
            ->assertJsonPath('expires_at', now()->addDays(90)->toIso8601String());
        $this->assertEqualsWithDelta(90 * 86400, $this->cookie($res)?->getMaxAge(), 2);

        $this->travel(80)->days();
        $this->web('GET', '/api/v1/auth/me', $sesi)->assertOk()->assertJsonPath('expires_at', now()->addDays(90)->toIso8601String());

        $this->travel(80)->days();
        $res = $this->web('GET', '/api/v1/auth/me', $sesi)->assertOk()
            ->assertJsonPath('expires_at', $login->copy()->addDays(180)->toIso8601String())
            ->assertJsonPath('batas_absolut_at', $login->copy()->addDays(180)->toIso8601String());
        $this->assertEqualsWithDelta($login->copy()->addDays(180)->getTimestamp() - now()->getTimestamp(), $this->cookie($res)?->getMaxAge(), 2);

        $this->travel(20)->days();
        $this->web('GET', '/api/v1/auth/me', $sesi)->assertUnauthorized()->assertJsonPath('alasan', 'sesi_kedaluwarsa');
        $this->assertFalse($this->ada($sesi));
    }

    public function test_s12_sesi_web_admin_dikecualikan_idle_tetap_30_hari(): void
    {
        $this->kecualikanIdle();
        $sesi = $this->sesiWeb($this->admin);

        $this->travel(1)->hours();
        $this->web('GET', '/api/v1/auth/me', $sesi)->assertOk()->assertJsonPath('expires_at', now()->addDays(30)->toIso8601String());

        $this->travel(30 * 24 * 60 + 1)->minutes();
        $this->web('GET', '/api/v1/auth/me', $sesi)->assertUnauthorized()->assertJsonPath('alasan', 'sesi_kedaluwarsa');
    }

    // ---------------------------------------------------------------- GET auth/sesi berjenis

    public function test_daftar_sesi_memuat_jenis_label_dan_kuota_serta_menyembunyikan_sesi_web_idle(): void
    {
        $mini = $this->sesiMiniApp(1);
        $idle = $this->sesiWeb(label: 'Chrome · Windows');
        $this->travel(1)->hours();
        $web = $this->sesiWeb(label: '<b>Firefox</b> · Linux');

        $res = $this->web('GET', '/api/v1/auth/sesi', $web)->assertOk()
            ->assertJsonCount(3, 'data')
            ->assertJsonPath('meta.jenis_sesi_ini', 'web')
            ->assertJsonPath('meta.kuota', ['miniapp' => 5, 'web' => 3]);

        $data = collect($res->json('data'));
        $ini = $data->firstWhere('sesi_ini', true);
        $this->assertSame('web', $ini['jenis']);
        $this->assertSame('Firefox · Linux', $ini['perangkat']);
        $this->assertNull($ini['diverifikasi_pada']);
        $this->assertSame(['miniapp', 'web', 'web'], $data->pluck('jenis')->sort()->values()->all());
        $this->assertStringNotContainsString($web, (string) $res->getContent());

        $this->bearer($mini, '/api/v1/auth/sesi')->assertOk()->assertJsonPath('meta.jenis_sesi_ini', 'miniapp');

        // Sesi web pertama melewati idle: tidak tampil lagi.
        $this->travel(11 * 60 + 30)->minutes();
        $this->assertSame(['miniapp', 'web'], collect($this->bearer($mini, '/api/v1/auth/sesi')->json('data'))->pluck('jenis')->sort()->values()->all());
        $this->assertFalse(collect($this->bearer($mini, '/api/v1/auth/sesi')->json('data'))->contains('perangkat', 'Chrome · Windows'));
        $this->assertTrue($this->ada($idle)); // belum dicabut sampai dipakai/rapikan, tetapi tidak berlaku
    }

    public function test_mini_app_dapat_mencabut_sesi_web_dan_keluar_semua_menghitung_kedua_jenis(): void
    {
        $mini = $this->sesiMiniApp(1);
        $web = $this->sesiWeb();
        $idWeb = PersonalAccessToken::findToken($web)->id;

        $this->app['auth']->forgetGuards();
        $this->withToken($mini)->deleteJson('/api/v1/auth/sesi/'.$idWeb)->assertOk()->assertJsonPath('sesi_ini', false);
        $this->assertFalse($this->ada($web));
        $this->web('GET', '/api/v1/auth/me', $web)->assertUnauthorized();

        $this->sesiWeb();
        $this->sesiWeb();
        $this->app['auth']->forgetGuards();
        $this->withToken($mini)->postJson('/api/v1/auth/keluar-semua')->assertOk()->assertJsonPath('jumlah_sesi', 3);
        $this->assertSame(0, $this->siti->tokens()->count());
    }

    public function test_bot_dan_agen_ai_tetap_hanya_memakai_sesi_mini_app(): void
    {
        $sesi = app(SesiService::class);
        $this->sesiWeb();

        $this->assertFalse($sesi->punyaSesiPenuh($this->siti));
        $this->assertNull($sesi->tokenSesiPenuh($this->siti));

        $this->sesiMiniApp(1);
        $this->assertTrue($sesi->punyaSesiPenuh($this->siti));
        $this->assertFalse(SesiService::tokenWeb($sesi->tokenSesiPenuh($this->siti)));
    }
}
