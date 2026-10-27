<?php

use App\Http\Controllers\Api\V1\KeuanganController;
use App\Http\Controllers\Api\V1\PencairanDanaController;
use App\Http\Controllers\Api\V1\PengeluaranController;
use App\Http\Middleware\IdempotencyKey;
use Illuminate\Support\Facades\Route;

// PBI 14 Data Keuangan. Multipart ganti berkas: POST {id} dengan _method=PUT.
Route::prefix('keuangan')->name('keuangan.')->group(function () {
    Route::get('ringkasan', [KeuanganController::class, 'ringkasan'])->name('ringkasan');

    Route::get('pencairan', [PencairanDanaController::class, 'index'])->name('pencairan.index');
    Route::post('pencairan', [PencairanDanaController::class, 'store'])
        ->middleware(['throttle:unggah', IdempotencyKey::class])->name('pencairan.store');
    Route::get('pencairan/{pencairanDana}', [PencairanDanaController::class, 'show'])->whereNumber('pencairanDana')->name('pencairan.show');
    Route::match(['put', 'patch'], 'pencairan/{pencairanDana}', [PencairanDanaController::class, 'update'])
        ->middleware('throttle:unggah')->whereNumber('pencairanDana')->name('pencairan.update');
    Route::delete('pencairan/{pencairanDana}', [PencairanDanaController::class, 'destroy'])->whereNumber('pencairanDana')->name('pencairan.destroy');

    Route::get('pengeluaran', [PengeluaranController::class, 'index'])->name('pengeluaran.index');
    Route::post('pengeluaran', [PengeluaranController::class, 'store'])
        ->middleware(['throttle:unggah', IdempotencyKey::class])->name('pengeluaran.store');
    Route::get('pengeluaran/{pengeluaran}', [PengeluaranController::class, 'show'])->whereNumber('pengeluaran')->name('pengeluaran.show');
    Route::match(['put', 'patch'], 'pengeluaran/{pengeluaran}', [PengeluaranController::class, 'update'])
        ->middleware('throttle:unggah')->whereNumber('pengeluaran')->name('pengeluaran.update');
    Route::delete('pengeluaran/{pengeluaran}', [PengeluaranController::class, 'destroy'])->whereNumber('pengeluaran')->name('pengeluaran.destroy');
    // R-06b-2: keputusan investor atas pengajuan pengeluaran (satu-satunya tulis investor).
    Route::post('pengeluaran/{pengeluaran}/keputusan', [PengeluaranController::class, 'keputusan'])->whereNumber('pengeluaran')->name('pengeluaran.keputusan');
});
