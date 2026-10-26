<?php

use App\Http\Controllers\Api\V1\ArmadaController;
use App\Http\Controllers\Api\V1\PengemudiController;
use Illuminate\Support\Facades\Route;

Route::apiResource('armada', ArmadaController::class)->parameters(['armada' => 'armada'])->whereNumber('armada');
Route::apiResource('pengemudi', PengemudiController::class)->parameters(['pengemudi' => 'pengemudi'])->whereNumber('pengemudi');
