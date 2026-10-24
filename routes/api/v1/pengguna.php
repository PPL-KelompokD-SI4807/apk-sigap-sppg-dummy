<?php

use App\Http\Controllers\Api\V1\PenggunaController;
use App\Http\Controllers\Api\V1\PenggunaUndanganController;
use Illuminate\Support\Facades\Route;

// Undangan didaftarkan sebelum {user} agar "undangan" tidak dianggap id pengguna.
Route::get('pengguna/undangan', [PenggunaUndanganController::class, 'index'])->name('pengguna.undangan.index');
Route::post('pengguna/undangan', [PenggunaUndanganController::class, 'store'])->name('pengguna.undangan.store');
Route::delete('pengguna/undangan/{invitation}', [PenggunaUndanganController::class, 'destroy'])
    ->whereNumber('invitation')->name('pengguna.undangan.destroy');

Route::apiResource('pengguna', PenggunaController::class)->parameters(['pengguna' => 'user'])->whereNumber('user');
