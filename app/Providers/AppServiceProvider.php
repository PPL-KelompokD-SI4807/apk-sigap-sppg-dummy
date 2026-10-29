<?php

namespace App\Providers;

use App\Policies\DashboardPolicy;
use App\Services\Telegram\InitDataValidator;
use App\Support\SppgAktif;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;
use Illuminate\Support\Str;

class AppServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        // Dibuat ulang tiap resolve agar perubahan config (tes) langsung berlaku.
        $this->app->bind(InitDataValidator::class, fn () => InitDataValidator::fromConfig());

        // SPPG aktif (R-06a): satu instance per permintaan/job, tidak terbawa ke permintaan lain.
        $this->app->scoped(SppgAktif::class);
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

        // R-07: gerbang dashboard per peran (isi disaring lagi per modul oleh DashboardService).
        Gate::define('dashboard.lihat', [DashboardPolicy::class, 'lihat']);

        // NFR-05: 60 permintaan/menit per pengguna (fallback IP).
        RateLimiter::for('api', fn (Request $request) => Limit::perMinute(60)
            ->by('u:'.($request->user()?->getAuthIdentifier() ?? $request->ip())));

        // Unggah berkas (NFR-06): per pengguna, di atas batas umum 'api'.
        RateLimiter::for('unggah', fn (Request $request) => Limit::perMinute(max(1, (int) config('unggahan.rate_limit_per_menit', 20)))
            ->by('unggah:'.($request->user()?->getAuthIdentifier() ?? $request->ip())));

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
    }
}
