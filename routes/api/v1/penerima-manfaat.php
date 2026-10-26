<?php

use App\Http\Controllers\Api\V1\PenerimaManfaatController;
use Illuminate\Support\Facades\Route;

// R-06a: agregat tanpa data pribadi (investor + peran ber-hak R), SPPG aktif.
Route::get('penerima-manfaat/agregat', [PenerimaManfaatController::class, 'agregat'])->name('penerima-manfaat.agregat');

Route::apiResource('penerima-manfaat', PenerimaManfaatController::class)
    ->parameters(['penerima-manfaat' => 'penerimaManfaat'])->whereNumber('penerimaManfaat');
