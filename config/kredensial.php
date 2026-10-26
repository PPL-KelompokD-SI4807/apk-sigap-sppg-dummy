<?php

/*
|--------------------------------------------------------------------------
| Login kredensial (R-03)
|--------------------------------------------------------------------------
|
| Lapis 1: initData Telegram -> token pra-login (ability auth:pra-login, umur pendek).
| Lapis 2: username/no telepon + password (+ token 6 digit dari bot) -> token login penuh
| (ability auth:penuh, tanpa kedaluwarsa; berakhir hanya karena logout/pencabutan).
| Nilai di sini sengaja tidak dibaca dari env agar tidak dilonggarkan per server.
*/

return [
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
