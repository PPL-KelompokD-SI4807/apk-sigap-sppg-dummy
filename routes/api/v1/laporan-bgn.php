<?php

use App\Http\Controllers\Api\V1\LaporanBgnController;
use Illuminate\Support\Facades\Route;

// R07-2: data laporan BGN Lampiran 30a-30d (JSON). `jenis` = default rute, dibaca LaporanBgnRequest.
Route::prefix('laporan-bgn')->name('laporan-bgn.')->group(function () {
    Route::get('porsi', [LaporanBgnController::class, 'porsi'])->defaults('jenis', 'porsi')->name('porsi');
    Route::get('harian', [LaporanBgnController::class, 'harian'])->defaults('jenis', 'harian')->name('harian');
    Route::get('dua-mingguan', [LaporanBgnController::class, 'duaMingguan'])->defaults('jenis', 'dua_mingguan')->name('dua-mingguan');
    Route::get('bulanan/ringkasan', [LaporanBgnController::class, 'bulananRingkasan'])->defaults('jenis', 'bulanan_ringkasan')->name('bulanan-ringkasan');
    Route::get('bulanan/anggaran', [LaporanBgnController::class, 'bulananAnggaran'])->defaults('jenis', 'bulanan_anggaran')->name('bulanan-anggaran');
    Route::get('bulanan/penerima-manfaat', [LaporanBgnController::class, 'bulananPenerima'])->defaults('jenis', 'bulanan_penerima')->name('bulanan-penerima');

    // POST nomor LPA (idempoten per periode). 30a dan 30d-C tidak bernomor: rute tidak ada (404).
    // 30d A/B satu laporan bulanan, satu nomor: satu rute `bulanan/nomor`.
    Route::post('harian/nomor', [LaporanBgnController::class, 'terbitkanNomor'])->defaults('jenis', 'harian')->name('nomor-harian');
    Route::post('dua-mingguan/nomor', [LaporanBgnController::class, 'terbitkanNomor'])->defaults('jenis', 'dua_mingguan')->name('nomor-dua-mingguan');
    Route::post('bulanan/nomor', [LaporanBgnController::class, 'terbitkanNomor'])->defaults('jenis', 'bulanan_ringkasan')->name('nomor-bulanan');
});
