<?php

use App\Http\Controllers\Api\V1\SppgController;
use Illuminate\Support\Facades\Route;

Route::apiResource('sppg', SppgController::class)->parameters(['sppg' => 'sppg'])->whereNumber('sppg');
