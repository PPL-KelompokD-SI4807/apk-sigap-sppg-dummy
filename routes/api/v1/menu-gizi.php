<?php

use App\Http\Controllers\Api\V1\MenuController;
use App\Http\Controllers\Api\V1\StandarGiziController;
use Illuminate\Support\Facades\Route;

// Standar gizi lebih dulu dari {menu} agar segmen "standar-gizi" tidak terikat sebagai id menu.
Route::apiResource('menu-gizi/standar-gizi', StandarGiziController::class)->parameters(['standar-gizi' => 'standar_gizi']);

Route::get('menu-gizi/{menu}/ringkasan-gizi', [MenuController::class, 'ringkasanGizi'])->whereNumber('menu')->name('menu-gizi.ringkasan-gizi');
// R-06b-1: rincian biaya (pratinjau harga terkini) dan pengajuan persetujuan investor.
Route::get('menu-gizi/{menu}/rincian-biaya', [MenuController::class, 'rincianBiaya'])->whereNumber('menu')->name('menu-gizi.rincian-biaya');
Route::post('menu-gizi/{menu}/ajukan', [MenuController::class, 'ajukan'])->whereNumber('menu')->name('menu-gizi.ajukan');
Route::apiResource('menu-gizi', MenuController::class)->parameters(['menu-gizi' => 'menu']);
