<?php

use App\Http\Controllers\Api\V1\DashboardController;
use Illuminate\Support\Facades\Route;

// R-07: dashboard laporan per peran. Dilayani walau investor belum memilih SPPG aktif (default gabungan).
Route::get('dashboard', DashboardController::class)->name('dashboard');
