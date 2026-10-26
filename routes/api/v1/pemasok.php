<?php

use App\Http\Controllers\Api\V1\PemasokController;
use Illuminate\Support\Facades\Route;

Route::apiResource('pemasok', PemasokController::class)->parameters(['pemasok' => 'pemasok'])->whereNumber('pemasok');
