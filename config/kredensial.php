<?php

/*
|--------------------------------------------------------------------------
| Login kredensial (R-03)
|--------------------------------------------------------------------------
|
| Lapis 1: initData Telegram -> token pra-login (ability auth:pra-login, umur pendek).
| Lapis 2: username/no telepon + password (+ token 6 digit dari bot) -> token login penuh
| (ability auth:penuh, masa sesi bergeser + batas absolut, lihat 'sesi'; dicabut seketika saat
| logout/pencabutan). Nilai di sini sengaja tidak dibaca dari env agar tidak dilonggarkan per
| server, KECUALI 'sesi': env SIGAP_SESI_* hanya dapat MENGETATKAN. SesiService menjepit ke batas
| keras di kode (absolut <= 180 hari, masa <= 90 hari, masa Admin <= 30 hari dan <= masa peran
| biasa, masa <= absolut, maks 5 sesi Mini App, maks 3 sesi web, idle web <= 12 jam); nilai lebih
| besar diabaikan.
*/

return [
    // Sesi login penuh (token Sanctum ability auth:penuh).
    'sesi' => [
        // Masa sesi bergeser (hari sejak terakhir dipakai). Maks keras 90; Admin maks keras 30.
        'masa_hari' => (int) env('SIGAP_SESI_MASA_HARI', 90),
        'masa_hari_admin' => (int) env('SIGAP_SESI_MASA_HARI_ADMIN', 30),
        // Batas absolut (hari sejak login, = personal_access_tokens.created_at). Maks keras 180.
        'batas_absolut_hari' => (int) env('SIGAP_SESI_BATAS_ABSOLUT_HARI', 180),
        // Maks token login penuh (perangkat) per pengguna, maks keras 5; login baru melebihi batas
        // mencabut sesi yang paling lama tidak dipakai.
        'maks_per_pengguna' => (int) env('SIGAP_SESI_MAKS_PER_PENGGUNA', 5),
        // Jeda minimum antar-penulisan last_used_at/expires_at per token (menit). Tidak dari env.
        'perpanjang_tiap_menit' => 60,
        // Akses web R-04 (ADR 2026-11-09, Q2/Q3). Kuota sesi web TERPISAH dari Mini App: maks keras 3;
        // login web baru di atas batas hanya mencabut sesi web yang paling lama tidak dipakai.
        'maks_web_per_pengguna' => (int) env('SIGAP_SESI_MAKS_WEB_PER_PENGGUNA', 3),
        // Sesi web biasa berakhir setelah sekian jam tidak dipakai. Maks keras 12.
        'idle_web_jam' => (int) env('SIGAP_SESI_IDLE_WEB_JAM', 12),
        // Jeda tulis last_used_at/expires_at token web (menit), maks keras 15 agar idle akurat. Tidak dari env.
        'jeda_tulis_web_menit' => 15,
    ],

    /*
     * Jalur cookie web (R-04). Token web hanya dikirim lewat cookie `__Host-sigap_sesi` (HttpOnly, Secure,
     * SameSite=Strict, Path=/, tanpa Domain). Origin yang diizinkan untuk metode pengubah = skema+host+port
     * dari config('app.url') (bukan header Host).
     */
    'web' => [
        // Pengembangan lokal TANPA HTTPS: nama cookie tanpa prefiks `__Host-` dan tanpa Secure. Hanya
        // berlaku bila APP_ENV=local; di lingkungan lain diabaikan (tetap `__Host-` + Secure).
        'cookie_lokal_http' => (bool) env('SIGAP_WEB_COOKIE_LOKAL_HTTP', false),

        // Login web R04-2 (ADR Keputusan 5). Tidak dari env. Kode memakai 'token' di bawah (6 digit, 5 menit,
        // 5 percobaan, 3 kirim ulang/60 detik) dengan jatah tantangan web TERPISAH dari Mini App.
        'penguncian' => [
            // 5 gagal (sandi atau kode) -> kanal web terkunci 15 menit; terkunci lagi dalam 24 jam -> 60 menit.
            // Mini App tidak ikut terkunci (kolom users.web_*).
            'maks_gagal' => 5,
            'kunci_menit' => 15,
            'kunci_berulang_menit' => 60,
            'jendela_berulang_jam' => 24,
        ],
        // Batas laju rute web/auth/* (throttle). Penguncian per akun di atas tetap berlaku.
        'throttle' => [
            // Semua rute web/auth/*, per IP, tanpa pemutus arus global.
            'per_ip_per_menit' => 30,
            'masuk_per_ip_per_menit' => 10,
            'masuk_per_ip_per_jam' => 100,
            // Per identitas ter-normalisasi (di-HMAC): 5 per 15 menit.
            'masuk_per_identitas' => 5,
            'masuk_per_identitas_menit' => 15,
            'kode_per_ip_per_menit' => 10,
            // R04-4 (#81): grup login penuh jalur cookie, SEBELUM CSRF/autentikasi (BatasLajuSesiWeb). Hanya per
            // IP (IP dari REMOTE_ADDR atau X-Forwarded-For proxy tepercaya, lihat config/trustedproxy.php); tanpa
            // pemutus arus global (itu tuas DoS). Bearer Mini App tidak dihitung.
            'sesi_per_ip_per_menit' => 300,
            // Kegagalan jalur cookie (401, 403 csrf_ditolak) per IP per menit.
            'sesi_gagal_per_ip_per_menit' => 30,
        ],
        // R04-4 (#88): tombol "Bukan saya" pada notifikasi login web sukses (dan pesan kode yang sudah dipakai)
        // berlaku sekian jam; ditekan -> semua sesi web + browser tepercaya akun dicabut (sekali pakai).
        'bukan_saya_jam' => 24,
    ],

    // Umur token pra-login (menit). Hanya untuk rute login/aktivasi/reset/token.
    'pra_login_menit' => 15,

    // Token sekali pakai 6 digit yang dikirim bot ke chat Telegram pengguna.
    'token' => [
        'digit' => 6,
        'berlaku_menit' => 5,
        // Salah sebanyak ini -> tantangan hangus (wajib minta tantangan baru).
        'maks_percobaan' => 5,
        'maks_kirim_ulang' => 3,
        'jeda_kirim_ulang_detik' => 60,
        // Batas tantangan baru per pengguna per jendela (mencegah spam chat & penebakan beruntun).
        'maks_tantangan' => 5,
        'jendela_tantangan_menit' => 30,
    ],

    // Penguncian sementara setelah password salah berulang (per akun).
    'penguncian' => [
        'maks_gagal' => 5,
        'kunci_menit' => 15,
    ],

    // Throttle rute pra-login (per menit).
    'throttle' => [
        'per_pengguna' => 10,
        'per_ip' => 30,
    ],

    // Daftar sandi umum lokal (satu per baris, '#' = komentar). Tidak memakai layanan eksternal.
    'daftar_sandi_umum' => database_path('data/sandi-umum.txt'),
];
