<?php

use App\Http\Controllers\Api\V1\PemeriksaanMutuController;
use Illuminate\Support\Facades\Route;

Route::apiResource('mutu', PemeriksaanMutuController::class)->parameters(['mutu' => 'pemeriksaan_mutu']);
