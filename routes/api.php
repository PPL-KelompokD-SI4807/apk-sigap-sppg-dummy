<?php

use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Loader rute API v1 (prefix /api sudah diberikan bootstrap/app.php)
|--------------------------------------------------------------------------
|
| - routes/api/v1/_public/*.php : rute publik (tanpa token), throttle ketat per IP.
| - routes/api/v1/*.php         : rute terautentikasi (auth:sanctum + akun aktif + throttle:api).
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

    Route::middleware(['auth:sanctum', 'active', 'throttle:api'])->group(function () {
        foreach (glob(__DIR__.'/api/v1/*.php') ?: [] as $file) {
            require $file;
        }
    });
});
