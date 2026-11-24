<?php

use App\Http\Controllers\Api\V1\TelegramWebhookController;
use App\Http\Middleware\BatasiHost;
use Illuminate\Support\Facades\Route;

// Webhook bot (publik; diverifikasi header secret). Throttle login (10/menit) diganti limiter
// khusus webhook karena seluruh update datang dari sedikit IP server Telegram.
Route::post('telegram/webhook', TelegramWebhookController::class)
    ->withoutMiddleware('throttle:auth-telegram')
    ->middleware([BatasiHost::class.':app.url', 'throttle:telegram-webhook'])
    ->name('telegram.webhook');
