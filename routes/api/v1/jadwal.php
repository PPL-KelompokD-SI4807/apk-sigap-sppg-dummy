<?php

use App\Http\Controllers\Api\V1\JadwalDistribusiController;
use App\Http\Controllers\Api\V1\JadwalProduksiController;
use Illuminate\Support\Facades\Route;

// /jadwal = jadwal produksi; /jadwal/distribusi = jadwal distribusi. Rute distribusi harus lebih dulu
// dari /jadwal/{jadwal} agar segmen "distribusi" tidak terikat sebagai id jadwal produksi.
Route::apiResource('jadwal/distribusi', JadwalDistribusiController::class)->parameters(['distribusi' => 'jadwal_distribusi']);

Route::get('jadwal/{jadwal}/kebutuhan-bahan', [JadwalProduksiController::class, 'kebutuhanBahan'])->whereNumber('jadwal')->name('jadwal.kebutuhan-bahan');
Route::apiResource('jadwal', JadwalProduksiController::class)->parameters(['jadwal' => 'jadwal']);
