<?php

use App\Http\Controllers\Api\V1\SppgController;
use App\Http\Controllers\Api\V1\SppgSayaController;
use Illuminate\Support\Facades\Route;

// R-06a: SPPG yang boleh dipilih sebagai SPPG aktif (header X-SPPG-Id).
Route::get('sppg-saya', SppgSayaController::class)->name('sppg-saya');

Route::match(['put', 'patch'], 'sppg/{sppg}/saldo-awal', [SppgController::class, 'saldoAwal'])->whereNumber('sppg')->name('sppg.saldo-awal');

Route::apiResource('sppg', SppgController::class)->parameters(['sppg' => 'sppg'])->whereNumber('sppg');
