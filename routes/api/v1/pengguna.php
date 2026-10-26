<?php

use App\Http\Controllers\Api\V1\PenggunaController;
use App\Http\Controllers\Api\V1\PenggunaKredensialController;
use App\Http\Controllers\Api\V1\PenggunaUndanganController;
use Illuminate\Support\Facades\Route;

// Undangan didaftarkan sebelum {user} agar "undangan" tidak dianggap id pengguna.
Route::get('pengguna/undangan', [PenggunaUndanganController::class, 'index'])->name('pengguna.undangan.index');
Route::post('pengguna/undangan', [PenggunaUndanganController::class, 'store'])->name('pengguna.undangan.store');
Route::delete('pengguna/undangan/{invitation}', [PenggunaUndanganController::class, 'destroy'])
    ->whereNumber('invitation')->name('pengguna.undangan.destroy');

// R-03: Admin mereset password (dikosongkan, tidak pernah dilihat) dan mencabut semua sesi pengguna.
Route::post('pengguna/{user}/reset-password', [PenggunaKredensialController::class, 'resetPassword'])
    ->whereNumber('user')->name('pengguna.reset-password');
Route::delete('pengguna/{user}/sesi', [PenggunaKredensialController::class, 'cabutSesi'])
    ->whereNumber('user')->name('pengguna.sesi.destroy');

Route::apiResource('pengguna', PenggunaController::class)->parameters(['pengguna' => 'user'])->whereNumber('user');
