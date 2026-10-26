<?php

use App\Http\Controllers\Api\V1\TitikLayananController;
use Illuminate\Support\Facades\Route;

Route::post('titik-layanan/{titikLayanan}/undangan-pic', [TitikLayananController::class, 'undanganPic'])
    ->whereNumber('titikLayanan')->name('titik-layanan.undangan-pic');

Route::apiResource('titik-layanan', TitikLayananController::class)
    ->parameters(['titik-layanan' => 'titikLayanan'])->whereNumber('titikLayanan');
