<?php

use App\Http\Controllers\Api\V1\BahanBakuController;
use App\Http\Middleware\IdempotencyKey;
use Illuminate\Support\Facades\Route;

// Rute statis harus lebih dulu dari {bahan_baku}.
Route::get('bahan-baku/stok-menipis', [BahanBakuController::class, 'stokMenipis'])->name('bahan-baku.stok-menipis');
Route::get('bahan-baku/kedaluwarsa-dekat', [BahanBakuController::class, 'kedaluwarsaDekat'])->name('bahan-baku.kedaluwarsa-dekat');

Route::apiResource('bahan-baku', BahanBakuController::class)->parameters(['bahan-baku' => 'bahan_baku'])->except('store');
// NFR-07: kirim ulang (jaringan lambat Mini App) tidak menggandakan bahan baku maupun mutasi stok.
Route::post('bahan-baku', [BahanBakuController::class, 'store'])->middleware(IdempotencyKey::class)->name('bahan-baku.store');

Route::get('bahan-baku/{bahan_baku}/mutasi', [BahanBakuController::class, 'mutasi'])->whereNumber('bahan_baku')->name('bahan-baku.mutasi.index');
Route::post('bahan-baku/{bahan_baku}/mutasi', [BahanBakuController::class, 'catatMutasi'])
    ->middleware(IdempotencyKey::class)->whereNumber('bahan_baku')->name('bahan-baku.mutasi.store');
Route::get('bahan-baku/{bahan_baku}/batch', [BahanBakuController::class, 'batch'])->whereNumber('bahan_baku')->name('bahan-baku.batch');
