<?php

use App\Http\Controllers\Api\V1\RekapHarianController;
use Illuminate\Support\Facades\Route;

Route::prefix('rekap-harian')->name('rekap-harian.')->group(function () {
    Route::get('/', [RekapHarianController::class, 'index'])->name('index');
    Route::post('/', [RekapHarianController::class, 'store'])->name('store');
    Route::get('ringkasan', [RekapHarianController::class, 'ringkasan'])->name('ringkasan');
    Route::get('{rekapHarian}', [RekapHarianController::class, 'show'])->whereNumber('rekapHarian')->name('show');
    Route::patch('{rekapHarian}', [RekapHarianController::class, 'update'])->whereNumber('rekapHarian')->name('update');
    Route::post('{rekapHarian}/kirim-ulang', [RekapHarianController::class, 'kirimUlang'])->whereNumber('rekapHarian')->name('kirim-ulang');
});
