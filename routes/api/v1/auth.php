<?php

use App\Http\Controllers\Api\V1\AuthController;
use App\Http\Controllers\Api\V1\SesiController;
use Illuminate\Support\Facades\Route;

// Login penuh (ability auth:penuh dari loader).
Route::get('auth/me', [AuthController::class, 'me'])->name('auth.me');
Route::post('auth/logout', [AuthController::class, 'logout'])->name('auth.logout');
Route::get('auth/sesi', [SesiController::class, 'index'])->name('auth.sesi.index');
Route::post('auth/keluar-semua', [SesiController::class, 'keluarSemua'])->name('auth.keluar-semua');
