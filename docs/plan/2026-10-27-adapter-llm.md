# Rencana Tahap 5a: Adapter LLM kompatibel OpenAI (Groq)

Tanggal: 27 Okt 2026 | Branch: `feat/adapter-llm-openai-compat` (dari `develop`) | Tier keseluruhan: **T3** (Agen AI)
Fitur terkait: lintas fitur 1–16 (Agen AI, FR-11, KT-11/KT-12, NFR-11, NFR-12). Tidak ada perubahan skema DB atau menu bot.

## Tujuan
1. `OpenAiCompatibleLlmClient` (implementasi `LlmClient`, antarmuka TIDAK diubah) dipilih lewat `LLM_PROVIDER=openai_compat`; target utama Groq, tetap jalan di DeepSeek/penyedia OpenAI-compatible lain.
2. Penerjemahan format ternormalisasi `LlmClient` (user/assistant/tool, images, tools) ke dan dari Chat Completions, tanpa melemahkan pengaman yang sudah ada.
3. STT Groq (`whisper-large-v3-turbo`) lewat `HttpSpeechToText` yang sudah ada, hanya dengan konfigurasi `STT_*`.
4. Muat dalam batas gratis Groq (8K token/menit, 200K token/hari per model teks): ukur token per peran, pangkas bila perlu.
5. Perintah `php artisan sigap:llm-uji` untuk uji koneksi tanpa membocorkan kunci.

## Keadaan saat ini (hasil baca ringan)
- `config/ai.php`: `provider` = `''|anthropic|fake`; blok `anthropic` memakai `LLM_API_KEY`, `LLM_MODEL`, `LLM_BASE_URL` (default Anthropic), `LLM_MAX_TOKENS`, `LLM_TIMEOUT`; `retries` 1, `retry_sleep_ms` 500. Blok `stt`: `STT_DRIVER`, `STT_URL`, `STT_API_KEY`, `STT_MODEL` (default `whisper-1`), `STT_TIMEOUT`.
- Binding ada di `app/Providers/SigapIntegrationServiceProvider.php` (`match` pada `ai.provider` dan `ai.stt.driver`).
- `AiAgentService`: nonce per pesan, hasil tool dibungkus `IntentParser::wrapToolResult(..., $nonce)`, rate limit per pengguna (20/menit, 30/jam), `LlmException` jadi balasan fallback generik, dan pesan exception disimpan di `ai_interactions`.
- `HttpSpeechToText` sudah bergaya OpenAI (multipart `file`/`model`/`language`, Bearer), wajib `https://`, galat dibungkus pesan aman.
- `.env.example` tidak bisa dibaca PM (izin ditolak). `backend-dev` memastikan nama variabel di sana cocok dengan daftar di bawah. Kunci tetap tanpa nilai.

## Keputusan desain
| # | Keputusan |
|---|---|
| D1 | Blok config baru `ai.openai_compat`: `base_url` = `env('LLM_BASE_URL')` **tanpa default**, `api_key` = `LLM_API_KEY`, `model` = `LLM_MODEL` (tanpa default penyedia), `vision_model` = `LLM_VISION_MODEL` (kosong = gambar tidak didukung), `max_tokens`, `timeout` (20 dtk, NFR-11), `connect_timeout` 5, `retries` maks 1, `retry_after_max_seconds` (mis. 3). Variabel `LLM_*` dipakai bersama blok `anthropic`, dan hanya blok milik `provider` aktif yang dibaca. |
| D2 | SSRF: URL hanya dari config, tidak pernah dari input pengguna/model. Wajib `https://`, tanpa userinfo/query, redirect dimatikan (`withoutRedirecting`). Path tetap: `{base_url}/chat/completions`. Bila config tidak valid: `LlmException::notConfigured()`. |
| D3 | Pemetaan: `system` jadi pesan `role:system`. Assistant `tool_calls` jadi `{id,type:function,function:{name,arguments:json_string}}`. Pesan `tool` jadi `{role:tool,tool_call_id,content}`, dan `is_error` ditandai di dalam konten yang SUDAH dibungkus nonce (OpenAI tidak punya `is_error`). Tool jadi `{type:function,function:{name,description,parameters:input_schema}}`. Balasan: `choices[0].message` ke `LlmResponse`, `arguments` di-`json_decode` (JSON rusak = tool call ditolak sebagai galat, tidak dieksekusi). Field `reasoning`/`reasoning_content` (gpt-oss/DeepSeek) dibuang: tidak diteruskan, tidak di-log. Parameter khusus vendor tidak dikirim secara default. |
| D4 | Pengaman tetap berada di lapisan agen dan tidak dipindah ke adapter. Yang tidak berubah: nonce wrapper, pembuangan `protected_arguments` (sppg_id/user_id/role, dst.), nama anak berupa inisial+ID, tool disaring per peran (`ToolRegistry::forUser`), dan tool tulis hanya membuat `AiDraft`. Tes adapter wajib membuktikan bahwa konten tool yang terkirim ke penyedia masih terbungkus nonce. |
| D5 | Galat: 401/403 = "konfigurasi", 402 = "kuota habis", 413 = "pesan/gambar terlalu besar", 429 = "sedang sibuk, coba lagi sebentar lagi", 5xx/timeout = "tidak tersedia". `LlmException` membawa kode kategori dan pesan ramah, tanpa isi respons, header, atau kunci. Hanya kategori dan status HTTP yang disimpan ke `ai_interactions`. Retry maks 1 untuk koneksi/429/5xx. `Retry-After` dihormati hanya jika ≤ `retry_after_max_seconds` dan masih dalam `time_budget_seconds`; selain itu langsung fallback. Tidak ada log prompt, isi pesan, atau kunci. |
| D6 | Gambar (opsional), dua tahap demi keamanan: (a) bila `vision_model` kosong, `AiChatHandler` langsung membalas ramah "foto belum didukung, silakan isi formulir di Mini App" sebelum mengunduh foto; (b) bila terisi, model vision dipanggil **tanpa tool** dengan `image_url` `data:<mime>;base64,...` untuk mengekstrak teks/uraian (dipotong ke `max_input_chars`), lalu hasilnya diperlakukan sebagai data tak tepercaya (dibungkus nonce seperti teks pengguna) dan diproses model teks yang memegang tool. Kapabilitas dicek lewat interface kecil terpisah (mis. `SupportsVision`) sehingga `LlmClient` tidak berubah. Gagal pada tahap mana pun berujung balasan ramah dan arahan ke formulir manual. |
| D7 | Anggaran token: setiap respons mencatat `usage.prompt_tokens/completion_tokens` (angka saja) ke log interaksi. `sigap:llm-uji --ukur` menghitung secara offline, per peran (7 peran di `App\Enums\Role`), jumlah tool, karakter definisi tool + system prompt, dan estimasi token (~chars/4). Bila ada peran > ~5K token: (1) pemilihan tool dinamis berdasar niat (kata kunci modul dari teks pengguna) yang selalu berupa **subset** `forUser()` dan dibatasi N tool; (2) ringkas deskripsi tool/skema. Pemilihan tidak boleh menambah tool di luar hak peran. |
| D8 | Pembatas laju: rate limit per pengguna yang ada dipertahankan. Ditambah pelindung token global per menit/hari (Cache counter dari `usage`, ambang dari env) agar 429 Groq tidak beruntun; bila terlampaui, balas "sibuk" tanpa memanggil penyedia. |
| D9 | STT tanpa ubah antarmuka/menu: `STT_DRIVER=http`, `STT_URL=https://api.groq.com/openai/v1/audio/transcriptions`, `STT_MODEL=whisper-large-v3-turbo`, dan `STT_API_KEY` diisi pengguna dengan kunci Groq yang sama (tidak ada fallback otomatis ke `LLM_API_KEY`, agar eksplisit). Perbaikan internal yang boleh: pesan ramah per status (401/413/429), redirect dimatikan. |
| D10 | `sigap:llm-uji`: mengirim "balas dengan kata: siap" tanpa tool dan mencetak HANYA balasan (dipotong) atau pesan galat ramah. Tidak mencetak URL lengkap, header, kunci, atau isi respons mentah. Opsi `--stt=<file audio>`, `--vision=<file gambar>` (cek MIME/ukuran sesuai `ai.input`), `--ukur` (offline, tanpa jaringan). |

## Tugas
| No | Tugas | Agen | Tier (model/effort) | File | Kriteria selesai | Bergantung |
|---|---|---|---|---|---|---|
| A1 | Adapter + config + binding + galat/retry + token usage + pelindung token global | `backend-dev` | **T3** opus/high | `app/Services/Ai/Llm/OpenAiCompatibleLlmClient.php`, `LlmException.php`, interface vision baru, `config/ai.php`, `app/Providers/SigapIntegrationServiceProvider.php`, `.env.example` (tanpa nilai kunci) | D1–D5, D8 terpenuhi; tes unit Http::fake: pemetaan pesan/tool/hasil (nonce utuh), JSON argumen rusak, 401/402/413/429(+Retry-After)/5xx/timeout jadi pesan ramah tanpa isi respons, base_url non-https/kosong ditolak | — |
| A2 | Vision dua tahap + balasan ramah di `AiChatHandler` | `backend-dev` | **T3** opus/high | `app/Services/Telegram/AiChatHandler.php`, `app/Services/Ai/AiAgentService.php`, `IntentParser.php` | D6; tes: vision kosong = balasan ramah tanpa unduh; teks foto berisi instruksi injeksi tetap terbungkus nonce dan tidak memanggil tool di luar peran | A1 |
| A3 | Ukur token + pemilihan tool dinamis (bila > ~5K) | `backend-dev` | **T3** opus/high | `app/Services/Ai/Tools/ToolRegistry.php` atau selector baru, `CatalogDefinitions.php` (ringkas deskripsi) | D7; hasil `--ukur` per peran dilaporkan (angka saja); tes: selector selalu subset `forUser()` | A1 |
| A4 | STT Groq + `sigap:llm-uji` | `backend-dev` | **T3** opus/high | `app/Services/Ai/Speech/HttpSpeechToText.php` (internal saja), `app/Console/Commands/UjiLlm.php` | D9, D10; tes Http::fake STT format Groq; tes perintah: output tidak memuat kunci/URL | A1 |
| A5 | Dokumen kontrak + gate kualitas | `backend-dev` | T3 (bagian A1–A4) | `docs/api/ai-tools.md` (bagian penyedia), `tests/**` | `php artisan test` lulus, `vendor/bin/pint --test` lulus, tanpa kunci nyata di tes | A1–A4 |
| R1 | Tinjauan keamanan **read-only** | `reviewer` | **T3** opus/high | seluruh diff branch | Fokus: kebocoran kunci/prompt di log/galat/`ai_interactions`/output artisan; prompt injection via hasil tool & teks foto; SSRF via base_url (hanya config, https, tanpa redirect); batas ukuran gambar/audio/token/biaya; subset tool per peran. Temuan blocker dikembalikan ke `backend-dev` | A5 |
| G1 | Commit bertahap, PR ke `develop` dengan checklist DoD, pantau CI | `github-ops` | **T0** haiku/low | branch, commit, PR, `CHANGELOG.md` | PR terbuka, CI hijau; TIDAK merge | R1 lulus |

Paralelisasi: A1 berjalan dulu. A2/A3/A4 dikerjakan berurutan oleh satu `backend-dev` (berbagi `config/ai.php` dan `AiAgentService`) agar tidak ada dua agen di file yang sama. Tidak ada pekerjaan frontend.

## Risiko
| Risiko | Mitigasi |
|---|---|
| Definisi tool 16 modul (cari_/buat_/ubah_/hapus_) melebihi 8K TPM Groq dalam satu permintaan | D7: ukur dulu, lalu pemilihan tool dinamis + ringkas deskripsi; pelindung token global (D8) |
| Model `qwen/qwen3.8-27b` masih preview dan kemampuan gambarnya belum terverifikasi | Vision opsional; kosongkan `LLM_VISION_MODEL` bila `--vision` gagal; fallback formulir manual |
| Teks dalam foto berisi instruksi (prompt injection) | Dua tahap tanpa tool di model vision; hasil dibungkus nonce sebagai data; aksi tulis tetap hanya `AiDraft` + konfirmasi |
| gpt-oss mengembalikan `reasoning` atau argumen tool non-JSON | Buang `reasoning`; JSON rusak = galat tool, tidak dieksekusi |
| Perbedaan kecil antar penyedia (`max_tokens` vs `max_completion_tokens`, `tool_choice`) | Kirim hanya parameter umum; tes Http::fake untuk bentuk Groq dan DeepSeek |
| Kunci bocor lewat `LlmException`/`report()`/output artisan | D5/D10; tinjauan R1; tes memastikan string kunci palsu tidak muncul di pesan/log |
| Batas harian 200K token habis saat demo | Pelindung token harian (D8) dan pesan "kuota habis" yang ramah |
| `.env.example` tidak dapat dibaca PM | `backend-dev` memverifikasi dan menambah variabel tanpa nilai kunci |

## Variabel `.env` yang harus diisi pengguna (nama selaras `config/ai.php`)
| Variabel | Contoh nilai | Catatan |
|---|---|---|
| `LLM_PROVIDER` | `openai_compat` | baru sebagai nilai; variabel sudah ada |
| `LLM_BASE_URL` | `https://api.groq.com/openai/v1` (DeepSeek: `https://api.deepseek.com`) | sudah ada; untuk `openai_compat` WAJIB diisi, tanpa default |
| `LLM_API_KEY` | (kunci Groq, diisi pengguna sendiri) | sudah ada; jangan di-commit |
| `LLM_MODEL` | `openai/gpt-oss-120b` | sudah ada |
| `LLM_VISION_MODEL` | `qwen/qwen3.8-27b` atau kosong | BARU; kosong = foto tidak didukung |
| `LLM_MAX_TOKENS` | `1024` | sudah ada (opsional) |
| `LLM_TIMEOUT` | `20` | sudah ada (opsional) |
| `STT_DRIVER` | `http` | sudah ada |
| `STT_URL` | `https://api.groq.com/openai/v1/audio/transcriptions` | sudah ada |
| `STT_API_KEY` | (kunci Groq yang sama) | sudah ada; jangan di-commit |
| `STT_MODEL` | `whisper-large-v3-turbo` | sudah ada (default lama `whisper-1`) |
| `STT_TIMEOUT` | `20` | sudah ada (opsional) |
| `AI_TOKEN_PER_MINUTE` / `AI_TOKEN_PER_DAY` | `7000` / `190000` | BARU (usulan D8); nama final ditetapkan `backend-dev` |

Keputusan yang butuh persetujuan manusia: merge PR ke `develop`/`main` dan pengisian kunci di `.env` server staging.
