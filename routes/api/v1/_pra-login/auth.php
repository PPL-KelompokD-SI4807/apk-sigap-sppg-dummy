<?php

use App\Http\Controllers\Api\V1\KredensialController;
use Illuminate\Support\Facades\Route;

// Token pra-login saja (auth:sanctum + active + ability auth:pra-login + throttle:auth-kredensial dari loader).
Route::post('auth/masuk', [KredensialController::class, 'masuk'])->name('auth.masuk');
Route::post('auth/aktivasi', [KredensialController::class, 'aktivasi'])->name('auth.aktivasi');
// Lupa password: permintaan ke Admin -> disetujui -> password baru + token bot.
Route::get('auth/lupa-password', [KredensialController::class, 'statusLupaPassword'])->name('auth.lupa-password.status');
Route::post('auth/lupa-password', [KredensialController::class, 'mintaReset'])->name('auth.lupa-password.minta');
Route::post('auth/lupa-password/lanjut', [KredensialController::class, 'lanjutReset'])->name('auth.lupa-password.lanjut');
Route::post('auth/token/verifikasi', [KredensialController::class, 'verifikasiToken'])->name('auth.token.verifikasi');
Route::post('auth/token/kirim-ulang', [KredensialController::class, 'kirimUlangToken'])->name('auth.token.kirim-ulang');
