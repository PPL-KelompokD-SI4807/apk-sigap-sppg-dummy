<?php

use Illuminate\Support\Facades\Route;

Route::get('/', function () {
    return view('welcome');
});

/*
| Mini App (hasil `npm run build` di public/miniapp). Berkas statis (assets/*) disajikan langsung
| oleh nginx / php artisan serve; rute ini menangani "/miniapp" (dengan/tanpa garis miring) dan jalur SPA.
| Hanya index.html tetap yang dikirim, tidak pernah berkas dari path permintaan.
*/
Route::get('/miniapp/{path?}', function () {
    $index = public_path('miniapp/index.html');

    abort_unless(is_file($index), 503, 'Mini App belum dibangun. Jalankan `npm run build`.');

    return response(file_get_contents($index), 200, [
        'Content-Type' => 'text/html; charset=UTF-8',
        'Cache-Control' => 'no-cache',
        'X-Content-Type-Options' => 'nosniff',
    ]);
})->where('path', '(?!assets/).*')->name('miniapp');
