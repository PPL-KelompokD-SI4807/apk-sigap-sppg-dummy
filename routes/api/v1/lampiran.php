<?php

use App\Http\Controllers\Api\V1\LampiranController;
use App\Http\Middleware\IdempotencyKey;
use Illuminate\Support\Facades\Route;

// Unggahan aman (NFR-06). Tidak ada URL publik: berkas hanya lewat GET /lampiran/{id}.
Route::prefix('lampiran')->name('lampiran.')->group(function () {
    Route::post('/', [LampiranController::class, 'store'])
        ->middleware(['throttle:unggah', IdempotencyKey::class])
        ->name('store');
    Route::get('{lampiran}', [LampiranController::class, 'show'])->whereNumber('lampiran')->name('show');
});
