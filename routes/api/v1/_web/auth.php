<?php

use App\Http\Controllers\Api\V1\WebAuthController;
use Illuminate\Support\Facades\Route;

// Login web R04-2 (prefix api/v1/web, nama api.v1.web.*). Dari loader: PraLoginWeb
// (X-Sigap-Klien: web; metode pengubah wajib CSRF pra-login). TANPA SesiWebCookie/auth:sanctum.
Route::get('auth/csrf', [WebAuthController::class, 'csrf'])->name('auth.csrf');
Route::post('auth/masuk', [WebAuthController::class, 'masuk'])->middleware('throttle:auth-web-masuk')->name('auth.masuk');
Route::post('auth/kode', [WebAuthController::class, 'kode'])->middleware('throttle:auth-web-kode')->name('auth.kode');
Route::post('auth/kode/kirim-ulang', [WebAuthController::class, 'kirimUlang'])->middleware('throttle:auth-web-kode')->name('auth.kode.kirim-ulang');
