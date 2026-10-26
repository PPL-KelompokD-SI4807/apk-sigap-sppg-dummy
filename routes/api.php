<?php

use App\Http\Middleware\EnsureTokenAbility;
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
|                                    Peran & scope SPPG tetap diputuskan Policy.
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

    Route::middleware(['auth:sanctum', 'active', EnsureTokenAbility::class.':'.SesiService::ABILITY_PRA_LOGIN, 'throttle:auth-kredensial'])->group(function () {
        foreach (glob(__DIR__.'/api/v1/_pra-login/*.php') ?: [] as $file) {
            require $file;
        }
    });

    Route::middleware(['auth:sanctum', 'active', EnsureTokenAbility::class.':'.SesiService::ABILITY_PENUH, 'throttle:api'])->group(function () {
        foreach (glob(__DIR__.'/api/v1/*.php') ?: [] as $file) {
            require $file;
        }
    });
});
