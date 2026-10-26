<?php

use App\Http\Controllers\Api\V1\PenggunaController;
use App\Http\Controllers\Api\V1\PenggunaKepemilikanController;
use App\Http\Controllers\Api\V1\PenggunaKredensialController;
use App\Http\Controllers\Api\V1\PenggunaUndanganController;
use App\Http\Controllers\Api\V1\PermintaanResetController;
use Illuminate\Support\Facades\Route;

// Undangan didaftarkan sebelum {user} agar "undangan" tidak dianggap id pengguna.
Route::get('pengguna/undangan', [PenggunaUndanganController::class, 'index'])->name('pengguna.undangan.index');
Route::post('pengguna/undangan', [PenggunaUndanganController::class, 'store'])->name('pengguna.undangan.store');
Route::delete('pengguna/undangan/{invitation}', [PenggunaUndanganController::class, 'destroy'])
    ->whereNumber('invitation')->name('pengguna.undangan.destroy');

// R-03: permintaan reset password dari pengguna, diputus Admin dalam scope-nya ({permintaan} = ULID).
Route::get('pengguna/permintaan-reset', [PermintaanResetController::class, 'index'])->name('pengguna.permintaan-reset.index');
Route::post('pengguna/permintaan-reset/{permintaan}/setujui', [PermintaanResetController::class, 'setujui'])
    ->whereUlid('permintaan')->name('pengguna.permintaan-reset.setujui');
Route::post('pengguna/permintaan-reset/{permintaan}/tolak', [PermintaanResetController::class, 'tolak'])
    ->whereUlid('permintaan')->name('pengguna.permintaan-reset.tolak');

// R-03: Admin mereset password (dikosongkan, tidak pernah dilihat), melihat, dan mencabut sesi pengguna.
Route::post('pengguna/{user}/reset-password', [PenggunaKredensialController::class, 'resetPassword'])
    ->whereNumber('user')->name('pengguna.reset-password');
Route::get('pengguna/{user}/sesi', [PenggunaKredensialController::class, 'daftarSesi'])
    ->whereNumber('user')->name('pengguna.sesi.index');
Route::delete('pengguna/{user}/sesi', [PenggunaKredensialController::class, 'cabutSesi'])
    ->whereNumber('user')->name('pengguna.sesi.destroy');
Route::delete('pengguna/{user}/sesi/{sesi}', [PenggunaKredensialController::class, 'cabutSatuSesi'])
    ->whereNumber(['user', 'sesi'])->name('pengguna.sesi.destroy-satu');

// R-06a: kepemilikan SPPG investor (pivot investor_sppg), hanya Admin.
Route::get('pengguna/{user}/kepemilikan-sppg', [PenggunaKepemilikanController::class, 'show'])
    ->whereNumber('user')->name('pengguna.kepemilikan-sppg.show');
Route::put('pengguna/{user}/kepemilikan-sppg', [PenggunaKepemilikanController::class, 'update'])
    ->whereNumber('user')->name('pengguna.kepemilikan-sppg.update');
Route::post('pengguna/{user}/kepemilikan-sppg', [PenggunaKepemilikanController::class, 'store'])
    ->whereNumber('user')->name('pengguna.kepemilikan-sppg.store');
Route::delete('pengguna/{user}/kepemilikan-sppg/{sppg}', [PenggunaKepemilikanController::class, 'destroy'])
    ->whereNumber(['user', 'sppg'])->name('pengguna.kepemilikan-sppg.destroy');

Route::apiResource('pengguna', PenggunaController::class)->parameters(['pengguna' => 'user'])->whereNumber('user');
