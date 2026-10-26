<?php

use App\Http\Controllers\Api\V1\LogProduksiController;
use Illuminate\Support\Facades\Route;

Route::apiResource('log-produksi', LogProduksiController::class)->parameters(['log-produksi' => 'log_produksi']);
