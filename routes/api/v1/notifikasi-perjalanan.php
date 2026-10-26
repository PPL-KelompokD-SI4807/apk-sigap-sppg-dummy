<?php

use App\Http\Controllers\Api\V1\NotifikasiPerjalananController;
use Illuminate\Support\Facades\Route;

Route::prefix('notifikasi-perjalanan')->name('notifikasi-perjalanan.')->group(function () {
    Route::get('/', [NotifikasiPerjalananController::class, 'index'])->name('index');
    Route::post('/', [NotifikasiPerjalananController::class, 'store'])->name('store');
    Route::get('terakhir', [NotifikasiPerjalananController::class, 'terakhir'])->name('terakhir');
    Route::post('pratinjau', [NotifikasiPerjalananController::class, 'pratinjau'])->name('pratinjau');
    Route::get('{notifikasiPerjalanan}', [NotifikasiPerjalananController::class, 'show'])->whereNumber('notifikasiPerjalanan')->name('show');
    Route::post('{notifikasiPerjalanan}/status', [NotifikasiPerjalananController::class, 'ubahStatus'])->whereNumber('notifikasiPerjalanan')->name('status');
});
