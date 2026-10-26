<?php

use App\Http\Controllers\Api\V1\PersetujuanMenuController;
use Illuminate\Support\Facades\Route;

// R-06b-1: hanya baca + keputusan investor. Sengaja tanpa rute ubah/hapus (pengajuan imutabel).
Route::get('persetujuan-menu', [PersetujuanMenuController::class, 'index'])->name('persetujuan-menu.index');
Route::get('persetujuan-menu/{pengajuan}', [PersetujuanMenuController::class, 'show'])->whereNumber('pengajuan')->name('persetujuan-menu.show');
Route::post('persetujuan-menu/{pengajuan}/keputusan', [PersetujuanMenuController::class, 'keputusan'])->whereNumber('pengajuan')->name('persetujuan-menu.keputusan');
