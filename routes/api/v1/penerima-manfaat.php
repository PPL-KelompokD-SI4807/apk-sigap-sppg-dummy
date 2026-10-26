<?php

use App\Http\Controllers\Api\V1\PenerimaManfaatController;
use Illuminate\Support\Facades\Route;

Route::apiResource('penerima-manfaat', PenerimaManfaatController::class)
    ->parameters(['penerima-manfaat' => 'penerimaManfaat'])->whereNumber('penerimaManfaat');
