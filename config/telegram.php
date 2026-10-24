<?php

return [

    // Token bot dari @BotFather. Hanya dari variabel lingkungan; jangan pernah di-commit.
    'bot_token' => env('TELEGRAM_BOT_TOKEN'),

    // Username bot (tanpa @) untuk tautan undangan t.me/<bot>?start=<token>.
    'bot_username' => env('TELEGRAM_BOT_USERNAME'),

    // Nilai header X-Telegram-Bot-Api-Secret-Token untuk webhook (setWebhook secret_token).
    'webhook_secret' => env('TELEGRAM_WEBHOOK_SECRET'),

    // Umur maksimum initData (detik) sejak auth_date. NFR-04 menyarankan 3600.
    'init_data_max_age' => (int) env('TELEGRAM_INIT_DATA_MAX_AGE', 86400),

    // Toleransi jam: auth_date di masa depan lebih dari ini ditolak (detik).
    'init_data_future_skew' => 60,

    /*
    | Bypass pengembangan: init_data "dev_role=<role>[&dev_unit=<unit>]" memberi token
    | pengguna demo. Hanya aktif bila APP_ENV local/testing DAN TELEGRAM_DEV_BYPASS=true.
    | Di production/staging selalu mati (dicek ulang di kode, tidak hanya di konfigurasi).
    */
    'dev_bypass' => (bool) env('TELEGRAM_DEV_BYPASS', false),

];
