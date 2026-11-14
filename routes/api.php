<?php

use App\Http\Middleware\BatasLajuSesiWeb;
use App\Http\Middleware\EnsureTokenAbility;
use App\Http\Middleware\PastikanJalurSesi;
use App\Http\Middleware\PraLoginWeb;
use App\Http\Middleware\ResolveSppgAktif;
use App\Http\Middleware\SesiWebCookie;
use App\Services\Auth\SesiService;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Loader rute API v1 (prefix /api sudah diberikan bootstrap/app.php)
|--------------------------------------------------------------------------
|
| - routes/api/v1/_public/*.php    : rute publik (tanpa token), throttle ketat per IP.
| - routes/api/v1/_pra-login/*.php : proses masuk R-03, HANYA token pra-login
|                                    (auth:sanctum + akun aktif + ability auth:pra-login).
| - routes/api/v1/*.php            : semua rute lain, WAJIB token login penuh
|                                    (auth:sanctum + akun aktif + ability auth:penuh + throttle:api).
|                                    Peran & scope SPPG tetap diputuskan Policy; header SPPG aktif
|                                    X-SPPG-Id divalidasi ResolveSppgAktif (R-06a).
| - routes/api/v1/_web/*.php       : login web R04-2 (prefix web/, nama api.v1.web.*), TANPA token dan TANPA
|                                    SesiWebCookie: throttle:auth-web + PraLoginWeb (X-Sigap-Klien: web, CSRF
|                                    pra-login cookie `__Host-sigap_pra` untuk metode pengubah).
|
| Satu berkas per modul (mis. routes/api/v1/bahan-baku.php) agar agen modul tidak bentrok.
| Nama rute otomatis diawali "api.v1.".
*/

Route::prefix('v1')->name('api.v1.')->group(function () {
    Route::middleware('throttle:auth-telegram')->group(function () {
        foreach (glob(__DIR__.'/api/v1/_public/*.php') ?: [] as $file) {
            require $file;
        }
    });

    // Login web (R04-2): glob tingkat atas di bawah TIDAK memuat subfolder _web/.
    Route::prefix('web')->name('web.')->middleware(['throttle:auth-web', PraLoginWeb::class])->group(function () {
        foreach (glob(__DIR__.'/api/v1/_web/*.php') ?: [] as $file) {
            require $file;
        }
    });

    Route::middleware(['auth:sanctum', 'active', EnsureTokenAbility::class.':'.SesiService::ABILITY_PRA_LOGIN, 'throttle:auth-kredensial'])->group(function () {
        foreach (glob(__DIR__.'/api/v1/_pra-login/*.php') ?: [] as $file) {
            require $file;
        }
    });

    // SesiWebCookie (R-04) WAJIB sebelum auth:sanctum: cookie `__Host-sigap_sesi` -> token + CSRF metode pengubah.
    // Hanya grup ini yang menerima cookie web; Bearer (Mini App) tidak tersentuh. PastikanJalurSesi memeriksa ulang
    // penolakan silang cookie/Bearer sesudah auth:sanctum, sebelum controller (fail closed).
    // BatasLajuSesiWeb (R04-4, #81) PALING DEPAN: batas per IP jalur cookie sebelum CSRF/autentikasi (Bearer tidak dihitung).
    Route::middleware([BatasLajuSesiWeb::class, SesiWebCookie::class, 'auth:sanctum', PastikanJalurSesi::class, 'active', EnsureTokenAbility::class.':'.SesiService::ABILITY_PENUH, ResolveSppgAktif::class, 'throttle:api'])->group(function () {
        foreach (glob(__DIR__.'/api/v1/*.php') ?: [] as $file) {
            require $file;
        }
    });
});
