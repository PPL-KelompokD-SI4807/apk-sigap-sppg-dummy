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

    // '' (nonaktif) | 'anthropic' | 'openai_compat' (Groq/DeepSeek/dll.) | 'fake' (hanya tes; ditolak di production).
    // Variabel LLM_* dipakai bersama; hanya blok milik provider aktif yang dibaca.
    'provider' => env('LLM_PROVIDER', ''),

    /*
    | Chat Completions kompatibel OpenAI (Tahap 5a). base_url WAJIB diisi lewat env (tanpa default
    | penyedia), wajib https, tanpa userinfo/query; redirect tidak diikuti (mitigasi SSRF). Contoh:
    | Groq https://api.groq.com/openai/v1, DeepSeek https://api.deepseek.com.
    | vision_model kosong = foto tidak didukung (bot membalas ramah sebelum mengunduh foto). Nama model vision
    | TIDAK di-hardcode (LLM_VISION_MODEL; model Preview dapat dihentikan penyedia): 404 / model_not_found /
    | model_decommissioned -> balasan ramah + tombol Mini App (LlmException::VISION_TIDAK_TERSEDIA).
    */
    'openai_compat' => [
        'base_url' => env('LLM_BASE_URL'),
        'api_key' => env('LLM_API_KEY'),
        'model' => env('LLM_MODEL'),
        'vision_model' => env('LLM_VISION_MODEL'),
        'max_tokens' => (int) env('LLM_MAX_TOKENS', 1024),
        'vision_max_tokens' => 800,
        // Batas base64 gambar per permintaan. BELUM TERVERIFIKASI untuk model vision terpilih (dokumentasi
        // Groq menyebut 4 MB untuk base64 dan 20 MB untuk URL; model Preview dapat berbeda). Foto chat
        // dikompres ulang (PraprosesFotoChat) sampai di bawah batas ini; bila tidak bisa -> "file terlalu besar".
        'vision_max_base64_bytes' => (int) env('LLM_VISION_MAX_BASE64_BYTES', 4 * 1024 * 1024),
        // NFR-11: per panggilan; total per pesan dibatasi agent.time_budget_seconds.
        'timeout' => (int) env('LLM_TIMEOUT', 20),
        'connect_timeout' => 5,
        // Percobaan ulang maks 1 (dipaksa <= 1) untuk koneksi / 429 / 5xx pada pesan TEKS/SUARA.
        // Jalur foto (model vision + model teks untuk pesan foto) TIDAK pernah diulang (opsi tanpa_ulang):
        // gambar ~2100 token, mengulang 429 hanya menghabiskan kuota TPM.
        'retries' => 1,
        'retry_sleep_ms' => 500,
        // Retry-After lebih lama dari ini -> langsung fallback (tidak menunggu).
        'retry_after_max_seconds' => 3,
    ],

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
        // Anggaran token TOTAL satu pesan ke model teks (estimasi semua iterasi; usage nyata bila ada). Dicek SEBELUM
        // tiap panggilan: melampaui -> draf yang sudah ada ditawarkan, atau balasan "permintaan terlalu besar" tanpa
        // memanggil penyedia. Bawaan 7000 untuk openai_compat (di bawah Groq gratis 8000 TPM), 0 = nonaktif.
        'token_per_pesan' => (int) env('AI_TOKEN_PER_PESAN', env('LLM_PROVIDER') === 'openai_compat' ? 7000 : 0),
    ],

    /*
    | Pesan foto (R-05 hemat token). Tool yang ditawarkan = subset forUser(): tool tulis dokumen di bawah (atau tool
    | tulis yang cocok dengan KETERANGAN foto) + tool cari_* yang dirujuk tool tulis itu; tool baca lain hanya bila
    | keterangan memintanya. Setelah draf terbentuk, loop BERHENTI (balasan disusun server, tanpa iterasi ringkasan).
    */
    'foto' => [
        'max_iterations' => (int) env('AI_MAX_ITERATIONS_FOTO', 3),
        'max_tools' => 5,
        'max_tool_tokens' => 1500,
        'max_tool_result_chars' => 1500,
        'tool_tulis' => ['buat_pembelian_bahan', 'buat_pengeluaran', 'catat_mutasi_stok', 'buat_bukti_distribusi'],
    ],

    /*
    | 429 penyedia bertipe token per MENIT (Groq: error.type "tokens" / "(TPM)"): job ProcessAiMessage di-release
    | (bukan diulang di dalam eksekusi yang sama). Jeda = Retry-After (header, atau "try again in Xs" pada pesan
    | penyedia) + 1 dtk, atau jeda_bawaan bila tidak ada. Retry-After > retry_after_maks -> TIDAK diulang (pesan ramah).
    | 429 harian (TPD/RPD), requests (RPM), dan galat lain tidak diulang. maks dipaksa 0..2.
    | Hasil baca foto antarpercobaan disimpan terenkripsi di Cache store di bawah (null = store bawaan); store
    | berdriver 'file' ditolak (tidak menulis berkas) -> foto tidak ditunda.
    */
    'retry_429' => [
        'maks' => (int) env('AI_RETRY_429_MAKS', 2),
        'jeda_bawaan' => 60,
        'retry_after_maks' => (int) env('AI_RETRY_429_RETRY_AFTER_MAKS', 90),
        'cache_store' => env('AI_RETRY_429_CACHE_STORE'),
    ],

    // NFR-12: 30 permintaan AI/jam per pengguna + pelindung lonjakan per menit.
    'rate_limit' => [
        'per_minute' => (int) env('AI_RATE_PER_MINUTE', 20),
        'per_hour' => (int) env('AI_RATE_PER_HOUR', 30),
    ],

    /*
    | Pelindung token GLOBAL per model (D8), selain rate limit per pengguna di atas. Penghitung dari
    | usage respons (angka saja); bila estimasi permintaan berikutnya melewati ambang, bot membalas
    | "sibuk"/"kuota habis" TANPA memanggil penyedia. 0 = nonaktif. Groq gratis: 8K/menit, 200K/hari.
    */
    'token_budget' => [
        'per_minute' => (int) env('AI_TOKEN_PER_MINUTE', 0),
        'per_day' => (int) env('AI_TOKEN_PER_DAY', 0),
        // Estimasi biaya token SATU gambar untuk model vision (TokenBudget::imageTokens). qwen/qwen3.8-27b di
        // Groq: ~2048 token/gambar (+ margin). Dipakai pelindung token sebelum foto diunduh dan sebelum vision.
        'image_tokens' => (int) env('AI_IMAGE_TOKENS', 2100),
    ],

    /*
    | Pemilihan tool dinamis berdasar niat (D7): subset tool milik peran (ToolRegistry::forUser) yang
    | relevan dengan kata kunci pesan, agar definisi tool muat dalam batas token penyedia.
    | mode: auto (aktif hanya untuk provider openai_compat) | on | off.
    | Aktif bila estimasi token definisi tool + prompt sistem peran > threshold_tokens.
    */
    'tool_selection' => [
        'mode' => env('AI_TOOL_SELECTION', 'auto'),
        'threshold_tokens' => (int) env('AI_TOOL_TOKEN_THRESHOLD', 2500),
        'max_tools' => (int) env('AI_TOOL_MAX', 10),
        // Estimasi token maksimum untuk definisi tool terpilih (deskripsi diringkas).
        'max_tool_tokens' => (int) env('AI_TOOL_MAX_TOKENS', 2500),
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
        'disusun_oleh', 'dipicu_oleh', 'pemeriksa_id', 'penangan_id', 'uploaded_by', 'ditangani_oleh', 'telegram_id', 'pic_telegram_chat_id',
        'is_admin', 'status_kirim', 'ai_draft_id', 'id',
    ],

    'tools' => [
        // Direktori yang dipindai otomatis untuk kelas AiTool (satu folder per modul).
        'paths' => [app_path('Services/Ai/Tools')],
        // Kelas tambahan di luar direktori di atas.
        'extra' => [],
        // Tool generik 16 modul dari Catalog\CatalogDefinitions (cari_/buat_/ubah_/hapus_).
        'catalog' => true,
        // Nama tool yang dimatikan (mis. ['hapus_pengeluaran']).
        'disabled' => [],
    ],

    'input' => [
        // Foto chat (vision): batas unduh (byte) dan MIME aktual yang diterima (NFR-06).
        'photo_max_bytes' => 5 * 1024 * 1024,
        'photo_mimes' => ['image/jpeg', 'image/png', 'image/webp'],
        // Pra-pemrosesan foto chat (PraprosesFotoChat, GD): sisi terpanjang, kualitas JPEG awal, lalu
        // turun bertahap bila base64 masih > vision_max_base64_bytes. EXIF/GPS terbuang (encode ulang).
        'photo_max_side_px' => 1280,
        'photo_jpeg_quality' => 82,
        // Langkah penurunan bila hasil masih terlalu besar: [sisi px, kualitas].
        'photo_fallback_steps' => [[1280, 70], [1024, 65], [800, 60]],
        // Batas piksel sebelum di-decode (anti decompression bomb; foto Telegram maks ~2560 px).
        'photo_max_pixels' => 16_000_000,
        // Album (media_group_id): hanya foto pertama yang diproses; penanda grup di Cache (detik).
        'album_ttl_seconds' => 120,
        // Pemberitahuan privasi pihak ketiga (sekali per pengguna; penanda di Cache, hari).
        'privasi_ttl_days' => 365,
        // Pesan suara: batas durasi (detik) dan ukuran (byte; Bot API getFile maks 20 MB).
        'voice_max_seconds' => 120,
        'voice_max_bytes' => 10 * 1024 * 1024,
    ],

    /*
    | Speech-to-text (KT-11/KT-12). Driver '' = belum dikonfigurasi (bot membalas
    | "pesan suara belum didukung"). 'http' = endpoint generik bergaya OpenAI
    | /audio/transcriptions (multipart: file, model, language) yang membalas {"text": "..."}.
    | Groq: STT_URL=https://api.groq.com/openai/v1/audio/transcriptions, STT_MODEL=whisper-large-v3-turbo,
    | STT_API_KEY diisi eksplisit (tidak ada fallback otomatis ke LLM_API_KEY).
    */
    'stt' => [
        'driver' => env('STT_DRIVER', ''),
        'url' => env('STT_URL'),
        'api_key' => env('STT_API_KEY'),
        'model' => env('STT_MODEL', 'whisper-1'),
        'language' => 'id',
        'timeout' => (int) env('STT_TIMEOUT', 20),
        // Biaya STT: kuota detik audio per pengguna per jam (dicek sebelum transkripsi, selain AI_RATE_*).
        'max_detik_per_jam' => (int) env('AI_STT_DETIK_PER_JAM', 600),
    ],

];
