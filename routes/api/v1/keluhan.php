<?php

use App\Http\Controllers\Api\V1\KeluhanController;
use Illuminate\Support\Facades\Route;

Route::prefix('keluhan')->name('keluhan.')->group(function () {
    Route::get('/', [KeluhanController::class, 'index'])->name('index');
    Route::post('/', [KeluhanController::class, 'store'])->name('store');
    Route::get('ringkasan', [KeluhanController::class, 'ringkasan'])->name('ringkasan');
    Route::get('{keluhan}', [KeluhanController::class, 'show'])->whereNumber('keluhan')->name('show');
    Route::match(['put', 'patch'], '{keluhan}', [KeluhanController::class, 'update'])->whereNumber('keluhan')->name('update');
    Route::delete('{keluhan}', [KeluhanController::class, 'destroy'])->whereNumber('keluhan')->name('destroy');
});
