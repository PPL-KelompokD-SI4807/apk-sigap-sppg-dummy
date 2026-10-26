<?php

use App\Http\Controllers\Api\V1\AuthController;
use Illuminate\Support\Facades\Route;

// Publik, throttle:auth-telegram (10/menit per IP) dari loader.
Route::post('auth/telegram', [AuthController::class, 'telegram'])->name('auth.telegram');
