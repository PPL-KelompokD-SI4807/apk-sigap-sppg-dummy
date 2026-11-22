<?php

/*
|--------------------------------------------------------------------------
| CORS (R-04, ADR 2026-11-09 Keputusan 3 + A3)
|--------------------------------------------------------------------------
|
| Web (akar domain) dan Mini App (/miniapp/) satu origin dengan /api, sehingga TIDAK ADA origin lain
| yang diizinkan membaca respons API. Menimpa default framework (allowed_origins '*'): preflight dari
| origin asing tidak mendapat Access-Control-Allow-Origin maupun Allow-Credentials. Sengaja tidak dari
| env. Pemeriksaan Origin untuk jalur cookie ada di App\Http\Middleware\SesiWebCookie (dari APP_URL).
*/

return [

    'paths' => ['api/*'],

    'allowed_methods' => [],

    'allowed_origins' => [],

    'allowed_origins_patterns' => [],

    'allowed_headers' => [],

    'exposed_headers' => [],

    'max_age' => 0,

    'supports_credentials' => false,

];
