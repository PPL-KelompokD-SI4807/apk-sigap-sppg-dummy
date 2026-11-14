<?php

namespace App\Providers;

use App\Policies\DashboardPolicy;
use App\Services\Auth\MasukWebService;
use App\Services\Auth\SesiService;
use App\Services\Auth\SesiWeb;
use App\Services\LaporanBgn\Pdf\FpdfRenderer;
use App\Services\LaporanBgn\Pdf\PdfRenderer;
use App\Services\Telegram\InitDataValidator;
use App\Support\SppgAktif;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;
use Illuminate\Support\Str;
use Laravel\Sanctum\Events\TokenAuthenticated;
use Laravel\Sanctum\PersonalAccessToken;
use Laravel\Sanctum\Sanctum;

class AppServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        // Dibuat ulang tiap resolve agar perubahan config (tes) langsung berlaku.
        $this->app->bind(InitDataValidator::class, fn () => InitDataValidator::fromConfig());

        // SPPG aktif (R-06a): satu instance per permintaan/job, tidak terbawa ke permintaan lain.
        $this->app->scoped(SppgAktif::class);

        // R07-3: render PDF laporan BGN (setasign/fpdf); dapat ditukar tanpa mengubah agregator/job.
        $this->app->bind(PdfRenderer::class, FpdfRenderer::class);
    }

    public function boot(): void
    {
        // Konvensi Policy: App\Models\X -> App\Policies\XPolicy (termasuk sub-namespace,
        // mis. App\Models\Keuangan\Pengeluaran -> App\Policies\Keuangan\PengeluaranPolicy).
        Gate::guessPolicyNamesUsing(function (string $modelClass): array {
            if (! Str::startsWith($modelClass, 'App\\Models\\')) {
                return [];
            }

            return ['App\\Policies\\'.Str::after($modelClass, 'App\\Models\\').'Policy'];
        });

        // Sesi login (R-03): masa sesi bergeser + batas absolut diperiksa di setiap request ber-Bearer;
        // pemakaian (last_used_at/expires_at) ditulis maksimal sekali per jam per token (SesiService).
        Sanctum::authenticateAccessTokensUsing(
            fn (PersonalAccessToken $token, bool $sah): bool => app(SesiService::class)->validasiToken($token, $sah),
        );
        Event::listen(TokenAuthenticated::class, fn (TokenAuthenticated $e) => app(SesiService::class)->catatPemakaian($e->token));

        // Akses web R-04: header Authorization ada -> hanya Bearer; tanpa Authorization -> cookie
        // `__Host-sigap_sesi` bila SesiWebCookie berjalan di rute itu. Penolakan silang di validasiToken.
        Sanctum::getAccessTokenFromRequestUsing(fn (Request $request): ?string => SesiWeb::tokenDariRequest($request));

        // R-07: gerbang dashboard per peran (isi disaring lagi per modul oleh DashboardService).
        Gate::define('dashboard.lihat', [DashboardPolicy::class, 'lihat']);

        // NFR-05: 60 permintaan/menit per pengguna (fallback IP).
        RateLimiter::for('api', fn (Request $request) => Limit::perMinute(60)
            ->by('u:'.($request->user()?->getAuthIdentifier() ?? $request->ip())));

        // Unggah berkas (NFR-06): per pengguna, di atas batas umum 'api'.
        RateLimiter::for('unggah', fn (Request $request) => Limit::perMinute(max(1, (int) config('unggahan.rate_limit_per_menit', 20)))
            ->by('unggah:'.($request->user()?->getAuthIdentifier() ?? $request->ip())));

        // R07-3: permintaan PDF laporan BGN per pengguna (pembuatan PDF mahal; berjalan di antrean).
        RateLimiter::for('laporan-bgn-pdf', fn (Request $request) => Limit::perMinute(max(1, (int) config('laporan-bgn.rate_limit_per_menit', 5)))
            ->by('lapbgn:'.($request->user()?->getAuthIdentifier() ?? $request->ip())));

        // Tukar initData -> token: 10/menit per IP.
        RateLimiter::for('auth-telegram', fn (Request $request) => Limit::perMinute(10)
            ->by('ip:'.$request->ip()));

        // R-03 rute pra-login (masuk/aktivasi/reset/token): per akun dan per IP. Penguncian akun
        // dan batas percobaan token ada di KredensialService/TokenSekaliPakaiService.
        RateLimiter::for('auth-kredensial', fn (Request $request) => [
            Limit::perMinute(max(1, (int) config('kredensial.throttle.per_pengguna', 10)))
                ->by('kred:u:'.($request->user()?->getAuthIdentifier() ?? $request->ip())),
            Limit::perMinute(max(1, (int) config('kredensial.throttle.per_ip', 30)))
                ->by('kred:ip:'.$request->ip()),
        ]);

        // R04-2 login web `web/auth/*` (ADR Keputusan 5): tanpa pemutus arus global (tuas DoS; limit_req nginx +
        // BatasLajuSesiWeb per IP). Masuk: per IP per menit/jam + per identitas ter-normalisasi (HMAC, tanpa identitas mentah di cache);
        // kode/kirim ulang: per IP. Batas tidak bergantung keberadaan akun (bukan oracle). Penguncian per akun
        // ada di MasukWebService.
        $web = fn (string $kunci, int $bawaan): int => max(1, (int) config('kredensial.web.throttle.'.$kunci, $bawaan));

        RateLimiter::for('auth-web-masuk', fn (Request $request) => [
            Limit::perMinute($web('masuk_per_ip_per_menit', 10))->by('web:masuk:ip:'.$request->ip()),
            Limit::perHour($web('masuk_per_ip_per_jam', 100))->by('web:masuk:ip-jam:'.$request->ip()),
            Limit::perMinutes($web('masuk_per_identitas_menit', 15), $web('masuk_per_identitas', 5))
                ->by('web:masuk:id:'.MasukWebService::hashIdentitas(is_string($request->input('identitas')) ? $request->input('identitas') : '')),
        ]);

        RateLimiter::for('auth-web-kode', fn (Request $request) => Limit::perMinute($web('kode_per_ip_per_menit', 10))
            ->by('web:kode:ip:'.$request->ip()));
    }
}
