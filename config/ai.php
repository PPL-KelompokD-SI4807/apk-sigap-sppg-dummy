<?php

/*
|--------------------------------------------------------------------------
| Agen AI SiGAP SPPG (FR-11, NFR-11, NFR-12)
|--------------------------------------------------------------------------
|
| Kunci API hanya dari variabel lingkungan (LLM_API_KEY); tidak pernah di-log atau dikirim
| ke klien. Provider kosong = Agen AI nonaktif (bot membalas fallback formulir manual).
| Pola tool: docs/api/ai-tools.md.
*/

return [

    // '' (nonaktif) | 'anthropic' | 'fake' (hanya untuk tes; ditolak di production).
    'provider' => env('LLM_PROVIDER', ''),

    'anthropic' => [
        'api_key' => env('LLM_API_KEY'),
        'model' => env('LLM_MODEL', 'claude-sonnet-4-5'),
        'base_url' => env('LLM_BASE_URL', 'https://api.anthropic.com'),
        'version' => '2023-06-01',
        'max_tokens' => (int) env('LLM_MAX_TOKENS', 1024),
        // NFR-11: lewat 20 detik pengguna menerima fallback formulir manual.
        'timeout' => (int) env('LLM_TIMEOUT', 20),
        'connect_timeout' => 5,
        // Percobaan ulang hanya untuk galat koneksi / 429 / 5xx.
        'retries' => 1,
        'retry_sleep_ms' => 500,
    ],

    'agent' => [
        // Iterasi tool-calling maksimum per pesan (loop tak berujung dipotong).
        'max_iterations' => (int) env('AI_MAX_ITERATIONS', 4),
        // Tool call maksimum per respons model (sisanya ditolak).
        'max_tool_calls_per_turn' => 5,
        // Batas panjang masukan pengguna (karakter) setelah transkripsi/caption.
        'max_input_chars' => 2000,
        // Batas panjang jawaban model yang diteruskan ke Telegram (maks pesan Telegram 4096).
        'max_output_chars' => 3500,
        // Batas hasil tool baca yang dikirim balik ke model (karakter JSON).
        'max_tool_result_chars' => 6000,
        // Batas total argumen tool (karakter JSON).
        'max_argument_chars' => 4000,
        // Anggaran waktu total satu pesan (detik); lewat ini -> fallback.
        'time_budget_seconds' => 25,
    ],

    // NFR-12: 30 permintaan AI/jam per pengguna + pelindung lonjakan per menit.
    'rate_limit' => [
        'per_minute' => (int) env('AI_RATE_PER_MINUTE', 20),
        'per_hour' => (int) env('AI_RATE_PER_HOUR', 30),
    ],

    'drafts' => [
        // FR-11: draf berlaku 30 menit (proposal). Dapat diubah lewat env.
        'ttl_minutes' => (int) env('AI_DRAFT_TTL_MINUTES', 30),
        // Draf pending maksimum per pengguna; lebih dari ini tool tulis ditolak.
        'max_pending_per_user' => (int) env('AI_DRAFT_MAX_PENDING', 10),
        // Keyakinan default bila model tidak memberi nilai; kolom < 0.7 = "Perlu dicek".
        'default_confidence' => 0.8,
        'low_confidence' => 0.7,
    ],

    /*
    | Argumen yang TIDAK PERNAH dipercaya dari model: selalu dibuang sebelum validasi
    | dan diisi ulang dari pengguna login oleh controller/model (BelongsToSppg, created_by).
    */
    'protected_arguments' => [
        'sppg_id', 'user_id', 'role', 'unit', 'created_by', 'updated_by', 'dicatat_oleh', 'dibuat_oleh',
        'disusun_oleh', 'pemeriksa_id', 'uploaded_by', 'ditangani_oleh', 'telegram_id', 'pic_telegram_chat_id',
        'is_admin', 'status_kirim', 'ai_draft_id', 'id',
    ],

    'tools' => [
        // Direktori yang dipindai otomatis untuk kelas AiTool (satu folder per modul).
        'paths' => [app_path('Services/Ai/Tools')],
        // Kelas tambahan di luar direktori di atas.
        'extra' => [],
        // Nama tool yang dimatikan (mis. ['hapus_pengeluaran']).
        'disabled' => [],
    ],

    'input' => [
        // Foto chat (vision): batas unduh (byte) dan MIME aktual yang diterima (NFR-06).
        'photo_max_bytes' => 5 * 1024 * 1024,
        'photo_mimes' => ['image/jpeg', 'image/png', 'image/webp'],
        // Pesan suara: batas durasi (detik) dan ukuran (byte; Bot API getFile maks 20 MB).
        'voice_max_seconds' => 120,
        'voice_max_bytes' => 10 * 1024 * 1024,
    ],

    /*
    | Speech-to-text (KT-11/KT-12). Driver '' = belum dikonfigurasi (bot membalas
    | "pesan suara belum didukung"). 'http' = endpoint generik bergaya OpenAI
    | /audio/transcriptions (multipart: file, model, language) yang membalas {"text": "..."}.
    */
    'stt' => [
        'driver' => env('STT_DRIVER', ''),
        'url' => env('STT_URL'),
        'api_key' => env('STT_API_KEY'),
        'model' => env('STT_MODEL', 'whisper-1'),
        'language' => 'id',
        'timeout' => (int) env('STT_TIMEOUT', 20),
    ],

];
