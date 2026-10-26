<?php

use App\Http\Controllers\Api\V1\MenuController;
use App\Http\Controllers\Api\V1\StandarGiziController;
use Illuminate\Support\Facades\Route;

// Standar gizi lebih dulu dari {menu} agar segmen "standar-gizi" tidak terikat sebagai id menu.
Route::apiResource('menu-gizi/standar-gizi', StandarGiziController::class)->parameters(['standar-gizi' => 'standar_gizi']);

Route::get('menu-gizi/{menu}/ringkasan-gizi', [MenuController::class, 'ringkasanGizi'])->whereNumber('menu')->name('menu-gizi.ringkasan-gizi');
Route::apiResource('menu-gizi', MenuController::class)->parameters(['menu-gizi' => 'menu']);
