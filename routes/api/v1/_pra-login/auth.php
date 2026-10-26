<?php

use App\Http\Controllers\Api\V1\KredensialController;
use Illuminate\Support\Facades\Route;

// Token pra-login saja (auth:sanctum + active + ability auth:pra-login + throttle:auth-kredensial dari loader).
Route::post('auth/masuk', [KredensialController::class, 'masuk'])->name('auth.masuk');
Route::post('auth/aktivasi', [KredensialController::class, 'aktivasi'])->name('auth.aktivasi');
Route::post('auth/reset-password', [KredensialController::class, 'resetPassword'])->name('auth.reset-password');
Route::post('auth/token/verifikasi', [KredensialController::class, 'verifikasiToken'])->name('auth.token.verifikasi');
Route::post('auth/token/kirim-ulang', [KredensialController::class, 'kirimUlangToken'])->name('auth.token.kirim-ulang');
