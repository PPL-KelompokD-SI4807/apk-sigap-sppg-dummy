<?php

use App\Http\Controllers\Api\V1\BuktiDistribusiController;
use App\Http\Middleware\IdempotencyKey;
use Illuminate\Support\Facades\Route;

// PBI 13. Multipart: ganti foto lewat POST {id} dengan _method=PUT (PHP tidak mengurai multipart PUT).
Route::prefix('bukti-distribusi')->name('bukti-distribusi.')->group(function () {
    Route::get('/', [BuktiDistribusiController::class, 'index'])->name('index');
    Route::post('/', [BuktiDistribusiController::class, 'store'])
        ->middleware(['throttle:unggah', IdempotencyKey::class])->name('store');
    Route::get('{buktiDistribusi}', [BuktiDistribusiController::class, 'show'])->whereNumber('buktiDistribusi')->name('show');
    Route::match(['put', 'patch'], '{buktiDistribusi}', [BuktiDistribusiController::class, 'update'])
        ->middleware('throttle:unggah')->whereNumber('buktiDistribusi')->name('update');
    Route::delete('{buktiDistribusi}', [BuktiDistribusiController::class, 'destroy'])->whereNumber('buktiDistribusi')->name('destroy');
});
