<?php

return [

    // Token bot dari @BotFather. Hanya dari variabel lingkungan; jangan pernah di-commit.
    'bot_token' => env('TELEGRAM_BOT_TOKEN'),

    // Username bot (tanpa @) untuk tautan undangan t.me/<bot>?start=<token>.
    'bot_username' => env('TELEGRAM_BOT_USERNAME'),

    // Nilai header X-Telegram-Bot-Api-Secret-Token untuk webhook (setWebhook secret_token).
    'webhook_secret' => env('TELEGRAM_WEBHOOK_SECRET'),

    // Umur maksimum initData (detik) sejak auth_date (NFR-04: 1 jam). initData tidak sekali pakai,
    // jadi nilai ini adalah jendela replay bila initData bocor; jangan dinaikkan di production.
    'init_data_max_age' => (int) env('TELEGRAM_INIT_DATA_MAX_AGE', 3600),

    // Toleransi jam: auth_date di masa depan lebih dari ini ditolak (detik).
    'init_data_future_skew' => 60,

    /*
    | Bypass pengembangan: init_data "dev_role=<role>[&dev_unit=<unit>]" memberi token
    | pengguna demo. Hanya aktif bila APP_ENV local/testing DAN TELEGRAM_DEV_BYPASS=true.
    | Di production/staging selalu mati (dicek ulang di kode, tidak hanya di konfigurasi).
    */
    'dev_bypass' => (bool) env('TELEGRAM_DEV_BYPASS', false),

    // URL HTTPS Mini App (tombol web_app & menu bot). Default: APP_URL/miniapp/.
    'miniapp_url' => env('TELEGRAM_MINIAPP_URL', rtrim((string) env('APP_URL', 'http://localhost'), '/').'/miniapp/'),

    // Bot API (tanpa SDK pihak ketiga, KT-04). Token tidak pernah di-log.
    'api_base_url' => env('TELEGRAM_API_BASE_URL', 'https://api.telegram.org'),
    'http_timeout' => (int) env('TELEGRAM_HTTP_TIMEOUT', 10),
    'http_retries' => 2,
    'http_retry_sleep_ms' => 300,

    // Ukuran body webhook maksimum (byte). Update Telegram umumnya < 10 KB.
    'webhook_max_body' => 256 * 1024,

    // Pembaruan yang diminta dari Telegram (setWebhook allowed_updates).
    'allowed_updates' => ['message', 'callback_query'],

];
