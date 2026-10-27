<?php

use App\Http\Controllers\Api\V1\PembelianBahanController;
use App\Http\Middleware\IdempotencyKey;
use Illuminate\Support\Facades\Route;

// R-06b-2: pembelian bahan (staf gudang CRU, akuntan verifikasi). Sengaja tanpa rute hapus.
// Nota diunggah lewat POST /lampiran (modul pembelian-bahan, tertunda) lalu `lampiran_ids`.
Route::get('pembelian-bahan', [PembelianBahanController::class, 'index'])->name('pembelian-bahan.index');
Route::post('pembelian-bahan', [PembelianBahanController::class, 'store'])
    ->middleware(['throttle:unggah', IdempotencyKey::class])->name('pembelian-bahan.store');
Route::get('pembelian-bahan/{pembelian_bahan}', [PembelianBahanController::class, 'show'])->whereNumber('pembelian_bahan')->name('pembelian-bahan.show');
// throttle:unggah juga pada ubah: tiap ubah menaikkan versi dan memicu ikat nota (sama seperti pengeluaran.update).
Route::match(['put', 'patch'], 'pembelian-bahan/{pembelian_bahan}', [PembelianBahanController::class, 'update'])
    ->middleware('throttle:unggah')->whereNumber('pembelian_bahan')->name('pembelian-bahan.update');
Route::post('pembelian-bahan/{pembelian_bahan}/verifikasi', [PembelianBahanController::class, 'verifikasi'])
    ->whereNumber('pembelian_bahan')->name('pembelian-bahan.verifikasi');
