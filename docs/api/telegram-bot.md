# Setup Bot Telegram SiGAP SPPG

## 1. Buat bot (@BotFather)

1. Di Telegram buka @BotFather -> `/newbot` -> nama "SiGAP SPPG" -> username, mis. `sigap_sppg_bot`.
2. Simpan token **hanya** di `.env` server (`TELEGRAM_BOT_TOKEN`). Jangan di-commit, jangan ditempel di chat/issue. Bila bocor: @BotFather -> `/revoke`.
3. Opsional: `/setdescription`, `/setuserpic`, `/setcommands` (`start - Mulai`, `bantuan - Cara memakai`).
4. Mini App: `/newapp` atau cukup tombol menu (diatur otomatis oleh command di bawah).

## 2. Variabel lingkungan

| Env | Contoh | Keterangan |
|---|---|---|
| `TELEGRAM_BOT_TOKEN` | (dari BotFather) | rahasia |
| `TELEGRAM_BOT_USERNAME` | `sigap_sppg_bot` | tanpa @; untuk tautan undangan `t.me/<bot>?start=inv_<token>` |
| `TELEGRAM_WEBHOOK_SECRET` | hasil `openssl rand -hex 32` | rahasia; 16-256 karakter `A-Za-z0-9_-`; kosong = webhook menolak semua |
| `TELEGRAM_MINIAPP_URL` | `https://sigap.example.id/miniapp/` | wajib HTTPS (tombol web_app); default `APP_URL/miniapp/` |
| `APP_URL` | `https://sigap.example.id` | dasar URL webhook |
| `TELEGRAM_INIT_DATA_MAX_AGE` | `3600` | NFR-04 |
| `LLM_PROVIDER`, `LLM_API_KEY`, `LLM_MODEL` | `anthropic` atau `openai_compat`, (rahasia), `claude-sonnet-4-5` / `openai/gpt-oss-120b` | Agen AI; kosong = nonaktif. `openai_compat` wajib `LLM_BASE_URL` (lihat `ai-tools.md`) |
| `LLM_VISION_MODEL` | kosong | `openai_compat`: kosong = foto dibalas "belum didukung" + tombol formulir, foto tidak diunduh |
| `STT_DRIVER`, `STT_URL`, `STT_API_KEY`, `STT_MODEL` | `http`, `https://api.groq.com/openai/v1/audio/transcriptions`, (rahasia), `whisper-large-v3-turbo` | opsional; kosong = "pesan suara belum didukung" |

Variabel baru yang belum ada di `.env.example`: `TELEGRAM_MINIAPP_URL`, `LLM_MODEL`, `LLM_BASE_URL`, `LLM_VISION_MODEL`, `LLM_TIMEOUT`, `AI_*` (termasuk `AI_TOKEN_PER_MINUTE`, `AI_TOKEN_PER_DAY`, `AI_TOOL_SELECTION`), `STT_*` (lihat `config/ai.php`, `config/telegram.php`). Uji koneksi: `php artisan sigap:llm-uji`.

## 3. Daftarkan webhook

HTTPS publik wajib (lokal: Cloudflare Tunnel / ngrok, lalu set `APP_URL`).

```bash
php artisan sigap:telegram-set-webhook                 # APP_URL/api/v1/telegram/webhook + tombol menu Mini App
php artisan sigap:telegram-set-webhook --url=https://x.trycloudflare.com/api/v1/telegram/webhook --drop-pending
php artisan sigap:telegram-set-webhook --info          # status & galat terakhir
php artisan sigap:telegram-set-webhook --delete
```

Command mengirim `secret_token`, `allowed_updates=["message","callback_query"]`, dan `setChatMenuButton` bertipe web_app. Token/secret tidak pernah dicetak. Pastikan worker antrean berjalan (`queue` di Docker Compose).

## 4. Perilaku bot

- Endpoint `POST /api/v1/telegram/webhook`: verifikasi header `X-Telegram-Bot-Api-Secret-Token` (hash_equals), idempoten `update_id`, balas 200 cepat, proses di antrean. Limiter `telegram-webhook` 600/menit per IP (throttle login 10/menit tidak berlaku).
- Hanya chat pribadi. Grup/kanal/pengirim bot diabaikan.
- `/start inv_<token>`: undangan pengguna -> akun ditautkan, tanpa sesi login penuh: salam netral tanpa nama/peran/SPPG + satu tombol "Masuk lewat Mini App"; dengan sesi: sapaan peran + SPPG + menu; undangan PIC -> chat ditautkan ke titik layanan. Undangan tidak valid/kedaluwarsa/dicabut/terpakai -> pesan ramah sesuai alasan.
- `/start` tanpa token: terdaftar aktif -> tombol Mini App; selain itu -> diminta menghubungi Admin SPPG. Data pengirim tak terdaftar tidak disimpan; payload `telegram_updates` diringkas setelah diproses.
- Teks/foto/suara dari pengguna aktif -> Agen AI (`docs/api/ai-tools.md`). Balasan AI selalu teks polos.
- Notifikasi berbasis peran: `docs/api/notifikasi.md`.

## 5. Uji lokal tanpa Telegram

```bash
curl -X POST "$APP_URL/api/v1/telegram/webhook" \
  -H 'Content-Type: application/json' -H "X-Telegram-Bot-Api-Secret-Token: $TELEGRAM_WEBHOOK_SECRET" \
  -d '{"update_id":1,"message":{"message_id":1,"date":0,"chat":{"id":7000000001,"type":"private"},"from":{"id":7000000001,"is_bot":false,"first_name":"Uji"},"text":"/start"}}'
```

Tes otomatis memakai `Http::fake()`; saat unit test semua HTTP keluar tanpa stub diblokir (`Http::preventStrayRequests()` di `SigapIntegrationServiceProvider`).
