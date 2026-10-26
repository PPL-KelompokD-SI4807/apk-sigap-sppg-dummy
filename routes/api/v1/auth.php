<?php

use App\Http\Controllers\Api\V1\AuthController;
use App\Http\Controllers\Api\V1\SesiController;
use Illuminate\Support\Facades\Route;

// Login penuh (ability auth:penuh dari loader).
Route::get('auth/me', [AuthController::class, 'me'])->name('auth.me');
Route::post('auth/logout', [AuthController::class, 'logout'])->name('auth.logout');
Route::get('auth/sesi', [SesiController::class, 'index'])->name('auth.sesi.index');
Route::delete('auth/sesi/{sesi}', [SesiController::class, 'destroy'])->whereNumber('sesi')->name('auth.sesi.destroy');
Route::post('auth/keluar-semua', [SesiController::class, 'keluarSemua'])->name('auth.keluar-semua');
// Password lama ikut penguncian akun; throttle per akun/IP sama dengan rute masuk.
Route::put('auth/password', [SesiController::class, 'ubahPassword'])->middleware('throttle:auth-kredensial')->name('auth.password');
