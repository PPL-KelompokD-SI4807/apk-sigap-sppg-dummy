# ADR: Input foto (dan pesan suara) langsung di chat bot (R-05)

- Tanggal: 2026-11-08
- Status: Diusulkan (menunggu tinjauan manusia T3 dan uji nyata di server, `docs/ops/uji-staging.md` bagian 6b)
- Tier: T3 (Agen AI, data tak tepercaya dari gambar/suara, unggahan, penyedia pihak ketiga)
- Branch: `feat/r05-foto-chat`

## Konteks

Pengguna ingin memotret nota atau surat jalan di chat bot (tanpa membuka Mini App) dan langsung mendapat draf, misalnya draf mutasi stok. Pipeline dua tahap sudah ada: model vision tanpa tool membaca gambar, lalu model teks memegang tool per peran. Yang belum ada: pra-pemrosesan gambar di server, penanganan album, perilaku 429 yang hemat kuota, penanganan model Preview yang dihentikan, pemberitahuan privasi, dan uji keamanan untuk teks hasil baca.

## Keputusan

1. **Model vision tetap dari env.** `LLM_VISION_MODEL`, tidak di-hardcode. Kandidat: `qwen/qwen3.8-27b` (Groq). Alasan: mendukung gambar dan tool use, sekitar 2048 token per gambar, URL sampai 20 MB, dan masuk kuota gratis (~8000 TPM). Risiko: model berstatus **Preview** dan dapat dihentikan tanpa masa transisi. Penanganannya:
   - `LLM_VISION_MODEL` kosong: foto dibalas ramah dengan tombol web_app ke Mini App, **sebelum** foto diunduh.
   - Penyedia membalas HTTP 404 atau `error.code` `model_not_found` / `model_decommissioned` / `model_not_available` / `model_not_active`: kategori baru `LlmException::VISION_TIDAK_TERSEDIA`, balasan "Membaca foto sedang tidak tersedia..." + tombol Mini App. Isi galat penyedia tidak pernah ditampilkan.
2. **Biaya gambar dapat diatur.** Bila `AI_TOKEN_PER_MINUTE`/`AI_TOKEN_PER_DAY` (> 0) lebih kecil dari biaya satu gambar + instruksi, itu salah konfigurasi: log `ai.konfigurasi_token_gambar` (angka saja) dan balasan netral "Membaca foto sedang tidak tersedia..." + tombol Mini App, bukan "Sedang ramai". `TokenBudget::IMAGE_TOKENS` = 2100 (bawaan), aktif dari `AI_IMAGE_TOKENS`. Pelindung token global dicek **sebelum foto diunduh** (`AiAgentService::cekAnggaranGambar`) dan sekali lagi sebelum tahap vision.
3. **(Diubah 2026-11-09, disetujui manusia; lihat "Anggaran token per pesan".)** Tidak ada pengulangan 429 *di dalam* satu eksekusi: `readImages()` dan model teks untuk pesan foto tetap memakai opsi `tanpa_ulang`. Satu-satunya pengulangan in-process yang tersisa adalah `tool_use_failed` (PR #58). Pesan teks/suara tetap memakai `retries=1` lama. Yang baru: 429 bertipe tokens per menit pada pesan foto dipulihkan lewat **retry tingkat job** yang terbatas (job ditunda, bukan diulang beruntun). Pesan 429 lain (kategori `sibuk`): "Sedang ramai, coba lagi sekitar 1 menit...". STT 429 memakai kalimat yang sama.
4. **Satu gambar per pesan.** Untuk album (`media_group_id`), `Cache::add('ai:album:{user}:{sha256(grup)}', TTL 120 dtk)` dipakai secara atomik. Foto pertama yang **diterima** server diproses. Foto berikutnya tidak diunduh, tidak dikirim ke LLM, dan tidak dibalas. Satu kalimat pemberitahuan per grup ditempel di balasan foto pertama. Catatan: urutan "pertama" mengikuti urutan job antrean, tidak selalu urutan di album. Risiko: bila worker antrean lebih dari satu, foto lain dapat diproses lebih dulu daripada foto ber-caption, sehingga caption album bisa hilang (pengguna perlu mengirim ulang satu foto dengan caption).
5. **Pra-pemrosesan di server** (`App\Services\Ai\Foto\PraprosesFotoChat`, hanya GD yang sudah ada di Dockerfile, tanpa dependensi baru):
   - batas 5 MB, dicek dari metadata Telegram sebelum unduh lalu dari isi;
   - MIME ditentukan dari **isi** (finfo), bukan ekstensi atau Content-Type; hanya JPEG/PNG/WebP;
   - header gambar wajib sah dan sama dengan MIME isi, jumlah piksel dibatasi (16 MP) sebelum decode;
   - encode ulang ke JPEG: EXIF/GPS/XMP/komentar/payload polyglot terbuang; orientasi EXIF diterapkan; transparansi diratakan ke putih; sisi terpanjang 1280 px, kualitas 82;
   - bila base64 masih melebihi `LLM_VISION_MAX_BASE64_BYTES`, kualitas lalu dimensi diturunkan bertahap (1280/70, 1024/65, 800/60). Bila tetap terlalu besar, balasan "File foto terlalu besar...".
   Berkas bertopeng (`.php` dinamai `.jpg`) ditolak sebagai format tidak didukung. Header JPEG palsu + skrip ditolak sebagai foto rusak. Foto asli **tidak disimpan**: hanya ada di memori job. Lampiran permanen tetap lewat `SecureUploadService` (Mini App). `sigap:llm-uji --vision` memakai pra-pemrosesan yang sama.
6. **Privasi pihak ketiga.** Kalimat "Foto dan pesan suara diproses penyedia AI pihak ketiga; jangan kirim data pribadi." ditempel SEKALI di awal balasan, pada foto/suara pertama yang benar-benar dikirim ke penyedia (foto: setelah pra-pemrosesan lolos DAN agen benar-benar memanggil penyedia, `AiReply::mediaTerkirim`; suara: tepat sebelum STT). Foto yang ditolak, gagal unduh, atau kena batas laju/pelindung token tidak memasang penanda. Penanda disimpan di Cache `ai:privasi:{user}`, TTL 365 hari, tanpa tabel baru. Kalimat yang sama juga ada di `/bantuan`. Bila cache dikosongkan, pengguna melihatnya sekali lagi (dapat diterima).
7. **Galat ramah, tanpa isi mentah penyedia.**
   - Foto buram atau tidak terbaca: model vision diminta menjawab `[TIDAK_TERBACA]`.
   - Bukan nota dan tanpa caption: `[BUKAN_DOKUMEN]`. Bila ada caption, uraiannya diteruskan sebagai data.
   - Lainnya: file terlalu besar, format tidak didukung, foto rusak, suara tidak jelas (transkrip kosong), suara > 120 dtk (dari metadata Telegram, ditolak sebelum unduh), dan kuota/rate limit.
   Penanda dapat dipalsukan oleh teks di gambar. Dampaknya hanya balasan galat, tidak pernah membuka aksi.
8. **Keamanan (T3).**
   - Teks hasil baca foto dan transkrip suara diberi label "DATA TIDAK TEPERCAYA" dan dibungkus nonce. Penanda penutup palsu dibuang. Model vision tidak menerima tool.
   - Aksi tulis tetap hanya `AiDraft` yang dikonfirmasi manusia. `ToolRegistry` tetap disaring per peran. `sppg_id` dari model dibuang, dan scope SPPG berlaku pada tool baca maupun target tulis.
   - Log hanya berisi ukuran, MIME, dimensi, kualitas, durasi, status, kategori/kode, dan model (`ai.foto_chat`, `ai.foto_ditolak`, `ai.foto_album_dilewati`, `ai.suara_chat`, `ai.suara_ditolak`, `ai.llm_galat`). Isi gambar, base64, transkrip, dan token tidak pernah masuk log.
   - Batas laju AI per pengguna dicek sebelum unduh. Foto dihitung lebih mahal lewat `AI_IMAGE_TOKENS` di pelindung token global.

## Anggaran token per pesan (2026-11-09, branch `fix/r05-hemat-token-foto`)

**Temuan staging** (log `ai.llm_galat`, tanpa isi): satu foto nota memicu loop agen 3 iterasi pada `openai/gpt-oss-120b`. Setiap iterasi ~3200 token (9 skema tool + riwayat), total ~9700, melampaui batas Groq gratis 8000 TPM. Akibatnya 429 `rate_limit_exceeded` (tipe tokens) muncul di iterasi 3 dan pengguna menerima "Sedang ramai". Pelindung token lokal bukan penyebab, dan pra-pemrosesan foto berjalan normal.

**Alasan pembalikan Keputusan 3.** Penolakan retry sebelumnya didasarkan pada biaya: mengulang gambar ~2100 token. Masalah itu hilang bila hasil baca vision dipakai ulang, karena yang diulang hanya tahap model teks yang kini lebih kecil. Di sisi lain, tanpa retry pengguna mengirim ulang foto secara manual, dan itu justru lebih boros (vision ikut terulang) serta berisiko menghasilkan draf ganda.

**Keputusan:**
1. *Hemat per iterasi.* Pesan foto hanya ditawari subset tool yang relevan (pencarian referensi + tool tulis yang sesuai), tetap disaring per peran dan scope SPPG. Tool di luar subset tidak bisa dipanggil walaupun diminta model. Riwayat yang dikirim ulang diringkas, dan instruksi sistem dipersingkat tanpa mengurangi aturan nonce data tak tepercaya, scope SPPG, RBAC, dan aksi tulis = draf.
2. *Batas iterasi dan estimasi total.* Jumlah iterasi maksimum dapat dikonfigurasi. Estimasi token total per pesan dihitung **sebelum** penyedia dipanggil. Bila melampaui anggaran per pesan, penyedia tidak dipanggil, pengguna menerima pesan "permintaan terlalu besar", dan draf yang sudah ada tetap ditawarkan atau pengguna dialihkan ke Mini App.
3. *Retry tingkat job.* Batasan wajibnya:
   - Hanya 429 bertipe tokens **per menit** (TPM). Batas harian (TPD/RPD) dan galat lain tidak di-retry; pengguna langsung menerima pesan ramah.
   - Jeda mengikuti `retry-after` dari penyedia, bawaan 60 detik. Bila `retry-after` > 90 detik, tidak di-retry dan pengguna langsung menerima pesan ramah.
   - Maksimum 2 percobaan ulang per pesan, tanpa retry di dalam retry dan tanpa loop beruntun.
   - Idempoten: tidak ada draf atau balasan ganda. Pengguna menerima tepat satu "Foto diterima, sedang diproses" dan satu hasil akhir. Foto yang sama yang dikirim ulang saat retry berjalan diabaikan atau digabungkan, tidak diproses dua kali.
   - Hasil baca foto dipakai ulang antar percobaan. Hasil itu hanya disimpan selama pemrosesan (cache ber-TTL pendek atau payload job), tidak ditulis ke disk, tidak masuk log, dan dihapus setelah selesai.
   - Retry tetap melewati pelindung token (`TokenBudget`) dan batas laju pengguna, tidak pernah menembusnya.
   - Bila percobaan habis, pengguna menerima pesan ramah + tombol web_app "Buka formulir SiGAP".
4. *Pesan galat dibedakan:* "permintaan terlalu besar" (estimasi melampaui anggaran / konteks terlalu panjang), "sedang ramai" (429 setelah retry habis atau tidak memenuhi syarat retry), dan peringatan konfigurasi (401/403/404 model) yang tetap seperti sebelumnya.

**Hasil implementasi.**

| | Sebelum | Sesudah |
|---|---|---|
| Tool ditawarkan (foto) | 8 | 4 (`catat_mutasi_stok`, `buat_pembelian_bahan`, `cari_bahan_baku`, `cari_pemasok`) |
| Iterasi | 3 (cari -> draf -> teks penutup) | 2 (cari -> draf; balasan akhir disusun server) |
| Token model teks per iterasi (estimasi karakter/4) | 2645 / 2954 / 3101 | 1636 / 1940 |
| Total model teks | ~8700 | ~3576 |
| Vision | ~2293 | ~2293 (dipakai ulang saat retry) |

Hanya angka staging yang terukur (~3200/iterasi, ~9700 total); angka lain estimasi offline dari `HematTokenFotoTest`. Estimasi `chars/4` perlu dikalibrasi dengan `usage` penyedia di staging.

**Konfigurasi** (`config/ai.php`): `AI_TOKEN_PER_PESAN` (7000 bila `openai_compat`, selain itu 0 = nonaktif), `AI_MAX_ITERATIONS_FOTO` (3), `AI_RETRY_429_MAKS` (2), `AI_RETRY_429_RETRY_AFTER_MAKS` (90), `AI_RETRY_429_CACHE_STORE` (kosong = store bawaan). Tanpa env: foto maks 5 tool, 1500 token skema tool, hasil tool 1500 karakter, jeda bawaan 60 detik.

**Penyimpanan hasil vision.** Di Cache (bukan payload job), `Crypt::encryptString`, kunci `ai:tunda:{update_id}`, isi hanya id interaksi + teks vision. TTL = jeda + 600 detik. Dihapus saat dibaca retry, saat selesai (sukses/gagal akhir), dan di `failed()`. Store berdriver `file` ditolak (foto tidak ditunda). Dengan store `database`, teks terenkripsi berada di tabel cache sampai dihapus atau kedaluwarsa.

**Penegakan di server.** Tool di luar subset ditolak di `runTool`. Penundaan hanya untuk foto; jalur teks/suara tidak berubah (retry klien lama). Retry job tidak bertumpuk dengan retry klien. `ProcessAiMessage`: `$failOnTimeout = true` dan `retry_after` antrean (150) di atas timeout job (90); percobaan lanjutan tanpa keadaan tunda di cache dilewati (`ai_diulang_dilewati`). 429 dianggap TPM hanya bila batasnya terbukti per menit; `type=tokens` saja tidak cukup (Groq memakainya juga untuk TPD).

**Risiko sisa.**
- Setiap retry memakai satu slot batas laju pengguna (satu pesan bisa sampai 3 slot).
- Worker mati setelah percobaan ulang membaca keadaan tunda: percobaan berikutnya dilewati, pengguna yang sudah menerima "Foto diterima" tidak mendapat hasil akhir. Disengaja (lebih baik daripada draf ganda); pengguna dapat mengirim ulang setelah penanda dilepas (maks ~5 menit).
- Kepala/Admin/Relawan tidak mendapat tool untuk foto nota dan diarahkan ke Mini App.

## Belum terverifikasi (perlakukan sebagai asumsi)

- Batas base64 per gambar untuk `qwen/qwen3.8-27b` (dokumentasi Groq umum: 4 MB base64; model Preview bisa berbeda). Dikelola lewat `LLM_VISION_MAX_BASE64_BYTES`. Foto 1280 px JPEG q82 biasanya 150–500 KB.
- "Maks 3 gambar per permintaan": kami selalu mengirim 1.
- Biaya token per gambar (2048) dan apakah resolusi 1280 px cukup untuk nota kecil/tulisan tangan.
- Kode galat penyedia saat model dihentikan (diasumsikan 404 `model_not_found` atau 400 `model_decommissioned`).
- Kualitas pembacaan nota berbahasa Indonesia dan kepatuhan model pada penanda `[TIDAK_TERBACA]` / `[BUKAN_DOKUMEN]`.

## Alternatif yang ditolak

- **Mengulang 429 in-process (beruntun) pada foto:** tetap ditolak. Satu gambar ~2100 token, dan satu ulangan langsung sudah memakan ~25% kuota TPM gratis. *(Diubah 2026-11-09: retry tingkat job yang ditunda dan memakai ulang hasil vision kini **diterima**; lihat "Anggaran token per pesan".)*
- **Memproses semua foto album:** biaya token berlipat dan draf ganda. Pengguna diminta mengirim satu foto per pesan.
- **Imagick / Intervention Image:** dependensi baru tanpa manfaat berarti. GD sudah terpasang dan dipakai `GdPemrosesGambar`.
- **Tabel baru untuk penanda privasi:** Cache sudah cukup untuk pemberitahuan sekali.
- **Menyimpan foto chat sebagai lampiran otomatis:** menambah data pribadi tersimpan (UU PDP). Lampiran tetap lewat alur aman Mini App.

## Konsekuensi

- (Diubah 2026-11-09) Pesan foto pulih otomatis dari 429 TPM lewat maksimal 2 retry tingkat job. Hasil akhir bisa tertunda sekitar 1–3 menit, dan selama itu pengguna melihat "Foto diterima, sedang diproses". Untuk 429 harian, `retry-after` > 90 dtk, atau percobaan yang sudah habis, pengguna tetap diminta mencoba lagi nanti.
- Kalimat privasi menambah satu baris pada balasan foto/suara pertama.
- Variabel env baru: `AI_IMAGE_TOKENS` (2100), `LLM_VISION_MAX_BASE64_BYTES` (4194304). Tidak ada perubahan kontrak REST (`openapi.yaml` tidak berubah).
