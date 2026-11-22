<?php

namespace Tests\Feature\Auth;

use App\Enums\RelawanUnit;
use App\Enums\Role;
use App\Http\Middleware\BatasLajuSesiWeb;
use App\Http\Middleware\PastikanJalurSesi;
use App\Http\Middleware\SesiWebCookie;
use App\Models\TitikLayanan;
use App\Models\User;
use App\Services\Auth\SesiService;
use App\Services\Auth\SesiWeb;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Routing\Route as RuteLaravel;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Storage;
use Illuminate\Testing\TestResponse;
use Laravel\Sanctum\PersonalAccessToken;
use Tests\Support\CreatesSppg;
use Tests\Support\GambarUji;
use Tests\TestCase;

/**
 * Audit jalur cookie web (R04-1) terhadap SEMUA rute CRUD /api/v1/*: cookie + X-CSRF-Token berhasil, tanpa CSRF
 * 403 csrf_ditolak, Bearer tetap jalan, multipart + _method, dan X-SPPG-Id pada sesi cookie.
 */
class WebCookieRuteCrudTest extends TestCase
{
    use CreatesSppg, GambarUji, RefreshDatabase;

    private const ORIGIN = 'http://localhost';

    private int $sppgA;

    private int $sppgB;

    private int $urut = 0;

    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo(Carbon::parse('2026-11-14 08:00:00'));
        $this->sppgA = $this->createSppg('SPPG A');
        $this->sppgB = $this->createSppg('SPPG B');

        // Penyapuan ratusan permintaan dari satu IP: batas laju jalur cookie (R04-4) dilonggarkan khusus tes ini.
        config(['kredensial.web.throttle.sesi_per_ip_per_menit' => 100000, 'kredensial.web.throttle.sesi_gagal_per_ip_per_menit' => 100000]);
    }

    private function sesiWeb(User $user): string
    {
        return app(SesiService::class)->terbitkanWeb($user, 'Firefox Linux')['cookie']->getValue();
    }

    /**
     * Permintaan "browser" (cookie + header persis). $csrf=false menghilangkan X-CSRF-Token.
     *
     * @param  array<string, mixed>  $body
     * @param  array<string, string>  $extra
     * @param  array<string, UploadedFile>  $files
     */
    private function web(string $method, string $uri, string $sesi, array $body = [], bool $csrf = true, array $extra = [], array $files = []): TestResponse
    {
        $this->app['auth']->forgetGuards();
        $this->flushHeaders();

        $header = ['Accept' => 'application/json', 'Origin' => self::ORIGIN, 'X-Sigap-Klien' => 'web'] + $extra;

        if ($csrf) {
            $header['X-CSRF-Token'] = SesiWeb::csrfSesi($sesi);
        }

        $server = $this->transformHeadersToServerVars($header);
        $multipart = $files !== [];

        if (! $multipart) {
            $server['CONTENT_TYPE'] = 'application/json';
        }

        return $this->call(
            $method, $uri, $multipart ? $body : [], [SesiWeb::namaCookieSesi() => $sesi], $files, $server,
            $multipart ? null : (string) json_encode($body),
        );
    }

    private function user(Role $role, ?int $sppgId = null, ?RelawanUnit $unit = null): User
    {
        return User::factory()->role($role, $unit)->denganKredensial('uji.'.$role->value.'.'.(++$this->urut))
            ->create(['sppg_id' => $role === Role::Admin ? null : ($sppgId ?? $this->sppgA)]);
    }

    /** @return list<RuteLaravel> rute pengubah login penuh (ber-SesiWebCookie + auth:sanctum). */
    private function rutePengubahLoginPenuh(): array
    {
        $hasil = [];

        foreach (Route::getRoutes()->getRoutes() as $rute) {
            if (! str_starts_with($rute->uri(), 'api/v1/') || array_diff($rute->methods(), ['GET', 'HEAD']) === []) {
                continue;
            }

            $mw = $rute->gatherMiddleware();

            if (in_array('auth:sanctum', $mw, true) && in_array(SesiWebCookie::class, $mw, true)) {
                $hasil[] = $rute;
            }
        }

        return $hasil;
    }

    /** Isi parameter rute: `{uuid}` memakai UUID sah (rute ber-whereUuid), lainnya $id. */
    private function isiParameter(RuteLaravel $rute, string $id): string
    {
        $uri = str_replace(['{uuid}', '{permintaan}'], ['00000000-0000-4000-8000-000000000001', '01ARZ3NDEKTSV4RRFFQ69G5FAV'], $rute->uri());

        return '/'.preg_replace('/\{[^}]+\??\}/', $id, $uri);
    }

    // ------------------------------------------------------------------ struktural (semua rute)

    public function test_semua_rute_login_penuh_memasang_rantai_cookie_lengkap_dan_rute_lain_tidak_menerima_cookie(): void
    {
        $penuh = 0;
        $tanpaToken = ['auth/masuk', 'auth/aktivasi', 'auth/telegram', 'auth/lupa-password', 'auth/lupa-password/lanjut', 'auth/token/kirim-ulang', 'auth/token/verifikasi'];

        foreach (Route::getRoutes()->getRoutes() as $rute) {
            if (! str_starts_with($rute->uri(), 'api/v1/')) {
                continue;
            }

            $mw = $rute->gatherMiddleware();
            $publik = str_starts_with($rute->uri(), 'api/v1/web/') || $rute->uri() === 'api/v1/telegram/webhook'
                || in_array(substr($rute->uri(), 7), $tanpaToken, true);

            if ($publik) {
                $this->assertNotContains(SesiWebCookie::class, $mw, "Rute publik/pra-login menerima cookie: {$rute->uri()}");

                continue;
            }

            $penuh++;
            foreach ([BatasLajuSesiWeb::class, SesiWebCookie::class, 'auth:sanctum', PastikanJalurSesi::class, 'active', 'throttle:api'] as $wajib) {
                $this->assertContains($wajib, $mw, "Rute {$rute->uri()} tidak memasang {$wajib}.");
            }

            // Urutan: batas laju -> CSRF cookie -> auth:sanctum (SesiWebCookie harus SEBELUM auth).
            $this->assertLessThan(array_search('auth:sanctum', $mw, true), array_search(SesiWebCookie::class, $mw, true));
            $this->assertLessThan(array_search(SesiWebCookie::class, $mw, true), array_search(BatasLajuSesiWeb::class, $mw, true));
        }

        $this->assertGreaterThan(100, $penuh);
    }

    public function test_setiap_rute_pengubah_tanpa_csrf_ditolak_403_sebelum_controller_dan_sesi_tetap(): void
    {
        $sesi = $this->sesiWeb($this->user(Role::Admin));
        $rute = $this->rutePengubahLoginPenuh();
        $this->assertGreaterThan(80, count($rute));

        foreach ($rute as $r) {
            $uri = $this->isiParameter($r, '1');

            foreach (array_diff($r->methods(), ['HEAD']) as $metode) {
                $res = $this->web($metode, $uri, $sesi, [], csrf: false);
                $this->assertSame(403, $res->getStatusCode(), "{$metode} {$uri} -> {$res->getStatusCode()}");
                $res->assertJsonPath('alasan', 'csrf_ditolak');
            }
        }

        $this->assertNotNull(PersonalAccessToken::findToken($sesi), 'Sesi tidak boleh tercabut oleh CSRF gagal.');
    }

    public function test_setiap_rute_pengubah_dengan_csrf_sah_melewati_gerbang_cookie_dan_auth(): void
    {
        $sesi = $this->sesiWeb($this->user(Role::Admin));

        // Rute yang sengaja mencabut/mengganti sesi dikecualikan dari penyapuan (diuji di WebSesiCookieTest).
        $kecuali = ['api/v1/auth/logout', 'api/v1/auth/keluar-semua', 'api/v1/auth/password'];

        foreach ($this->rutePengubahLoginPenuh() as $r) {
            if (in_array($r->uri(), $kecuali, true)) {
                continue;
            }

            $uri = $this->isiParameter($r, '999999');

            foreach (array_diff($r->methods(), ['HEAD']) as $metode) {
                $res = $this->web($metode, $uri, $sesi);
                $this->assertNotSame(401, $res->getStatusCode(), "{$metode} {$uri} -> 401 pada jalur cookie.");
                $this->assertNotSame('csrf_ditolak', $res->json('alasan'), "{$metode} {$uri} ditolak CSRF padahal sah.");
                $this->assertNotSame('perlu_login_penuh', $res->json('alasan'), "{$metode} {$uri}: ability token web ditolak.");
                $this->assertLessThan(500, $res->getStatusCode(), "{$metode} {$uri} -> {$res->getStatusCode()}");
            }
        }

        $this->assertNotNull(PersonalAccessToken::findToken($sesi));
    }

    // ------------------------------------------------------------------ CRUD nyata

    /** @return array<string, mixed> */
    private function titik(array $o = []): array
    {
        return $o + ['nama' => 'SDN 01 Fiktif', 'jenis' => 'sekolah', 'alamat' => 'Jl. Fiktif No. 5', 'jarak_km' => 3.5, 'jumlah_sasaran' => 240, 'pic_nama' => 'Bu Ani', 'pic_telepon' => '0812-0000-5555'];
    }

    public function test_crud_titik_layanan_lewat_cookie_csrf_berhasil_dan_tanpa_csrf_403(): void
    {
        $sesi = $this->sesiWeb($this->user(Role::KepalaSppg));

        $this->web('POST', '/api/v1/titik-layanan', $sesi, $this->titik(), csrf: false)->assertForbidden()->assertJsonPath('alasan', 'csrf_ditolak');
        $this->assertSame(0, TitikLayanan::withoutGlobalScopes()->count());

        $id = $this->web('POST', '/api/v1/titik-layanan', $sesi, $this->titik())->assertCreated()->json('data.id');
        $this->web('PUT', "/api/v1/titik-layanan/{$id}", $sesi, $this->titik(['nama' => 'UD Baru']))->assertOk()->assertJsonPath('data.nama', 'UD Baru');
        $this->web('PATCH', "/api/v1/titik-layanan/{$id}", $sesi, ['nama' => 'UD Patch'])->assertOk();
        $this->web('DELETE', "/api/v1/titik-layanan/{$id}", $sesi, csrf: false)->assertForbidden()->assertJsonPath('alasan', 'csrf_ditolak');
        $this->web('DELETE', "/api/v1/titik-layanan/{$id}", $sesi)->assertSuccessful();
    }

    public function test_bearer_tetap_berjalan_tanpa_csrf_pada_rute_crud(): void
    {
        $token = $this->beriSesiPenuh($this->user(Role::KepalaSppg));
        $this->app['auth']->forgetGuards();
        $this->flushHeaders();

        $id = $this->withToken($token)->postJson('/api/v1/titik-layanan', $this->titik())->assertCreated()->json('data.id');
        $this->app['auth']->forgetGuards();
        $this->withToken($token)->putJson("/api/v1/titik-layanan/{$id}", $this->titik(['nama' => 'Via Bearer']))->assertOk();
        $this->app['auth']->forgetGuards();
        $this->withToken($token)->deleteJson("/api/v1/titik-layanan/{$id}")->assertSuccessful();
    }

    public function test_crud_cookie_tetap_terisolasi_per_sppg_dan_hak_peran(): void
    {
        $milikB = TitikLayanan::factory()->create(['sppg_id' => $this->sppgB]);
        $sesi = $this->sesiWeb($this->user(Role::KepalaSppg));

        // Lintas SPPG lewat cookie: 404 (scope SPPG berlaku sama seperti Bearer).
        $this->web('PUT', "/api/v1/titik-layanan/{$milikB->id}", $sesi, $this->titik())->assertNotFound();
        $this->web('DELETE', "/api/v1/titik-layanan/{$milikB->id}", $sesi)->assertNotFound();

        // Peran tanpa hak tulis: 403 Policy (bukan csrf_ditolak).
        $relawan = $this->sesiWeb($this->user(Role::Relawan, unit: RelawanUnit::Dapur));
        $this->web('POST', '/api/v1/titik-layanan', $relawan, $this->titik())->assertForbidden()->assertJsonMissing(['alasan' => 'csrf_ditolak']);
    }

    public function test_multipart_unggah_lampiran_lewat_cookie_csrf_dan_override_metode(): void
    {
        Storage::fake('uploads');
        $sesi = $this->sesiWeb($this->user(Role::KepalaSppg));
        $berkas = fn (): array => ['berkas' => UploadedFile::fake()->createWithContent('foto.jpg', $this->bytesJpeg(64, 48))];

        $this->web('POST', '/api/v1/lampiran', $sesi, ['modul' => 'keluhan'], csrf: false, files: $berkas())
            ->assertForbidden()->assertJsonPath('alasan', 'csrf_ditolak');
        $this->web('POST', '/api/v1/lampiran', $sesi, ['modul' => 'keluhan'], files: $berkas())->assertCreated();

        // _method di badan multipart (spoofing PUT/DELETE klien form) tetap wajib CSRF.
        $milik = TitikLayanan::factory()->create(['sppg_id' => $this->sppgA]);
        $staf = $this->sesiWeb($this->user(Role::KepalaSppg));
        $this->web('POST', "/api/v1/titik-layanan/{$milik->id}", $staf, ['_method' => 'DELETE'], csrf: false, files: $berkas())
            ->assertForbidden()->assertJsonPath('alasan', 'csrf_ditolak');
        $this->assertNotNull(TitikLayanan::withoutGlobalScopes()->find($milik->id));

        // Dengan CSRF sah, _method=DELETE dihormati (spoofing berjalan di jalur cookie).
        $this->web('POST', "/api/v1/titik-layanan/{$milik->id}", $staf, ['_method' => 'DELETE'], files: $berkas())->assertSuccessful();
        $this->assertSoftDeleted($milik);
    }

    // ------------------------------------------------------------------ X-SPPG-Id pada sesi cookie

    public function test_header_x_sppg_id_dihormati_pada_sesi_cookie_investor_dan_ditolak_bila_bukan_miliknya(): void
    {
        $investor = User::factory()->role(Role::Investor)->denganKredensial('investor.uji')->create(['sppg_id' => null]);

        foreach ([$this->sppgA, $this->sppgB] as $id) {
            DB::table('investor_sppg')->insert(['user_id' => $investor->id, 'sppg_id' => $id, 'created_at' => now(), 'updated_at' => now()]);
        }

        $sppgC = $this->createSppg('SPPG C');
        $sesi = $this->sesiWeb($investor);

        $this->web('GET', '/api/v1/keluhan', $sesi)->assertStatus(409)->assertJsonPath('alasan', 'sppg_aktif_belum_dipilih');
        $this->web('GET', '/api/v1/keluhan', $sesi, extra: ['X-SPPG-Id' => (string) $this->sppgA])->assertOk();
        $this->web('GET', '/api/v1/keluhan', $sesi, extra: ['X-SPPG-Id' => (string) $sppgC])->assertForbidden()->assertJsonPath('alasan', 'sppg_aktif_ditolak');

        // Pengubah: CSRF lebih dulu, lalu SPPG aktif, lalu Policy (investor baca saja).
        $this->web('POST', '/api/v1/titik-layanan', $sesi, $this->titik(), csrf: false, extra: ['X-SPPG-Id' => (string) $this->sppgA])->assertForbidden()->assertJsonPath('alasan', 'csrf_ditolak');
        $this->web('POST', '/api/v1/titik-layanan', $sesi, $this->titik(), extra: ['X-SPPG-Id' => (string) $sppgC])->assertForbidden()->assertJsonPath('alasan', 'sppg_aktif_ditolak');
        $this->web('POST', '/api/v1/titik-layanan', $sesi, $this->titik(), extra: ['X-SPPG-Id' => (string) $this->sppgA])->assertForbidden()->assertJsonMissing(['alasan' => 'csrf_ditolak']);
    }

    public function test_header_x_sppg_id_beda_dari_sppg_peran_satu_sppg_ditolak_pada_sesi_cookie(): void
    {
        $sesi = $this->sesiWeb($this->user(Role::KepalaSppg));

        $this->web('POST', '/api/v1/titik-layanan', $sesi, $this->titik(), extra: ['X-SPPG-Id' => (string) $this->sppgB])
            ->assertForbidden()->assertJsonPath('alasan', 'sppg_aktif_ditolak');
        $this->web('POST', '/api/v1/titik-layanan', $sesi, $this->titik(), extra: ['X-SPPG-Id' => (string) $this->sppgA])->assertCreated();
    }
}
