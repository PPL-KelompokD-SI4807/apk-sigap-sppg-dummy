<?php

namespace Tests\Feature\Investor;

use App\Http\Middleware\EnsureTokenAbility;
use App\Http\Middleware\EnsureUserIsActive;
use App\Http\Middleware\ResolveSppgAktif;
use App\Services\Auth\GalatAuth;
use App\Services\Auth\SesiService;
use App\Support\SppgAktif;
use Illuminate\Routing\Middleware\SubstituteBindings;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Route;
use Mockery;

/**
 * R06a-S-03 (urutan middleware), S-08 (log penolakan header), S-10 (format header maks. 17 digit).
 */
class SppgAktifMiddlewareTest extends InvestorTestCase
{
    // ---------------------------------------------------------------- S-03

    public function test_urutan_middleware_active_lalu_ability_lalu_sppg_aktif_lalu_binding(): void
    {
        $route = Route::getRoutes()->getByName('api.v1.keluhan.show');
        $this->assertNotNull($route);

        $urutan = array_map(fn ($m) => is_string($m) ? explode(':', $m, 2)[0] : '', app('router')->gatherRouteMiddleware($route));
        $posisi = fn (string $kelas) => array_search($kelas, $urutan, true);

        foreach ([EnsureUserIsActive::class, EnsureTokenAbility::class, ResolveSppgAktif::class, SubstituteBindings::class] as $kelas) {
            $this->assertIsInt($posisi($kelas), $kelas.' tidak terpasang: '.implode(', ', $urutan));
        }

        $this->assertLessThan($posisi(EnsureTokenAbility::class), $posisi(EnsureUserIsActive::class));
        $this->assertLessThan($posisi(ResolveSppgAktif::class), $posisi(EnsureTokenAbility::class));
        $this->assertLessThan($posisi(SubstituteBindings::class), $posisi(ResolveSppgAktif::class));
    }

    public function test_token_pra_login_dengan_header_asing_mendapat_galat_ability_bukan_sppg_aktif_ditolak(): void
    {
        $token = $this->investorAB->createToken('pra-login uji', [SesiService::ABILITY_PRA_LOGIN])->plainTextToken;
        Log::spy();

        // Header SPPG milik investor lain dan SPPG miliknya sendiri harus menghasilkan galat yang SAMA
        // (bukan oracle daftar kepemilikan sebelum login penuh).
        foreach ([$this->sppgC, $this->sppgA, 999999] as $header) {
            $this->forgetActor();
            $this->flushHeaders();
            $res = $this->withToken($token)->withHeader('X-SPPG-Id', (string) $header)->getJson('/api/v1/keluhan');
            $this->assertAlasan($res, 403, GalatAuth::PERLU_LOGIN_PENUH);
        }

        Log::shouldNotHaveReceived('notice', ['sppg_aktif.ditolak', Mockery::any()]);
    }

    public function test_akun_nonaktif_dengan_header_asing_ditolak_sebagai_nonaktif(): void
    {
        $this->sebagai($this->investorAB, $this->sppgC);
        $this->investorAB->forceFill(['is_active' => false])->save();

        $this->getJson('/api/v1/keluhan')->assertForbidden()
            ->assertJsonPath('message', 'Akun Anda nonaktif. Hubungi Admin SPPG.')
            ->assertJsonMissingPath('alasan');
    }

    // ---------------------------------------------------------------- S-08

    public function test_penolakan_header_dicatat_log_notice_dengan_header_terpotong(): void
    {
        Log::spy();
        $panjang = '1 OR 1=1; DROP TABLE sppgs; -- sangat panjang sekali';

        $this->sebagai($this->investorAB, $panjang);
        $this->assertAlasan($this->getJson('/api/v1/keluhan'), 403, SppgAktif::DITOLAK);

        Log::shouldHaveReceived('notice')->once()->with('sppg_aktif.ditolak', Mockery::on(
            fn (array $ctx) => $ctx === ['user_id' => $this->investorAB->id, 'header' => mb_substr($panjang, 0, 20)],
        ));
    }

    public function test_header_sah_tidak_dicatat_dan_header_untuk_log_dibersihkan(): void
    {
        Log::spy();

        $this->sebagai($this->investorAB, $this->sppgA)->getJson('/api/v1/keluhan')->assertOk();
        Log::shouldNotHaveReceived('notice', ['sppg_aktif.ditolak', Mockery::any()]);

        $this->assertSame('12?34', ResolveSppgAktif::potongHeader("12\n34"));
        $this->assertSame(20, strlen(ResolveSppgAktif::potongHeader(str_repeat('9', 50))));
        $this->assertSame('', ResolveSppgAktif::potongHeader(null));
    }

    // ---------------------------------------------------------------- S-10

    public function test_format_header_maksimal_17_digit(): void
    {
        $this->assertNull(SppgAktif::idDariHeader(null));
        $this->assertSame(1, SppgAktif::idDariHeader('1'));
        $this->assertSame(12345678901234567, SppgAktif::idDariHeader('12345678901234567'));

        foreach (['123456789012345678', '9223372036854775807', '9223372036854775808', '0', '01', '-1', '1e3', ' 1', "1\n", 'abc', ''] as $tidakSah) {
            $this->assertSame(0, SppgAktif::idDariHeader($tidakSah), var_export($tidakSah, true));
        }

        // Lewat HTTP: 18 digit ditolak sebagai format salah (fail closed), sama seperti SPPG asing.
        $this->sebagai($this->investorAB, '123456789012345678');
        $this->assertAlasan($this->getJson('/api/v1/keluhan'), 403, SppgAktif::DITOLAK);
    }
}
