<?php

use App\Http\Controllers\Api\V1\PemasokController;
use Illuminate\Support\Facades\Route;

Route::apiResource('pemasok', PemasokController::class)->parameters(['pemasok' => 'pemasok'])->whereNumber('pemasok');

// R-06b-1: riwayat harga bahan per pemasok (append-only: hanya baca dan catat, tanpa ubah/hapus).
Route::get('pemasok/{pemasok}/harga', [PemasokController::class, 'harga'])->whereNumber('pemasok')->name('pemasok.harga.index');
Route::post('pemasok/{pemasok}/harga', [PemasokController::class, 'catatHarga'])->whereNumber('pemasok')->name('pemasok.harga.store');
