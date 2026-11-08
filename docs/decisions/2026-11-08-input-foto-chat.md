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
2. **Biaya gambar dapat diatur.** `TokenBudget::IMAGE_TOKENS` = 2100 (bawaan), aktif dari `AI_IMAGE_TOKENS`. Pelindung token global dicek **sebelum foto diunduh** (`AiAgentService::cekAnggaranGambar`) dan sekali lagi sebelum tahap vision.
3. **429 tidak diulang di jalur foto.** `readImages()` selalu memakai opsi `tanpa_ulang`. Model teks untuk pesan foto juga memakai opsi ini (berlaku juga di klien Anthropic). Satu-satunya pengulangan terbatas yang tersisa adalah `tool_use_failed` (PR #58). Pesan teks/suara tetap memakai `retries=1` lama. Pesan 429 (kategori `sibuk`, termasuk pelindung token per menit): "Sedang ramai, coba lagi sekitar 1 menit...". STT 429 memakai kalimat yang sama.
4. **Satu gambar per pesan.** Untuk album (`media_group_id`), `Cache::add('ai:album:{user}:{sha256(grup)}', TTL 120 dtk)` dipakai secara atomik. Foto pertama yang **diterima** server diproses. Foto berikutnya tidak diunduh, tidak dikirim ke LLM, dan tidak dibalas. Satu kalimat pemberitahuan per grup ditempel di balasan foto pertama. Catatan: urutan "pertama" mengikuti urutan job antrean, tidak selalu urutan di album.
5. **Pra-pemrosesan di server** (`App\Services\Ai\Foto\PraprosesFotoChat`, hanya GD yang sudah ada di Dockerfile, tanpa dependensi baru):
   - batas 5 MB, dicek dari metadata Telegram sebelum unduh lalu dari isi;
   - MIME ditentukan dari **isi** (finfo), bukan ekstensi atau Content-Type; hanya JPEG/PNG/WebP;
   - header gambar wajib sah dan sama dengan MIME isi, jumlah piksel dibatasi (16 MP) sebelum decode;
   - encode ulang ke JPEG: EXIF/GPS/XMP/komentar/payload polyglot terbuang; orientasi EXIF diterapkan; transparansi diratakan ke putih; sisi terpanjang 1280 px, kualitas 82;
   - bila base64 masih melebihi `LLM_VISION_MAX_BASE64_BYTES`, kualitas lalu dimensi diturunkan bertahap (1280/70, 1024/65, 800/60). Bila tetap terlalu besar, balasan "File foto terlalu besar...".
   Berkas bertopeng (`.php` dinamai `.jpg`) ditolak sebagai format tidak didukung. Header JPEG palsu + skrip ditolak sebagai foto rusak. Foto asli **tidak disimpan**: hanya ada di memori job. Lampiran permanen tetap lewat `SecureUploadService` (Mini App). `sigap:llm-uji --vision` memakai pra-pemrosesan yang sama.
6. **Privasi pihak ketiga.** Kalimat "Foto dan pesan suara diproses penyedia AI pihak ketiga; jangan kirim data pribadi." ditempel SEKALI di awal balasan, pada foto/suara pertama yang benar-benar dikirim ke penyedia. Penanda disimpan di Cache `ai:privasi:{user}`, TTL 365 hari, tanpa tabel baru. Kalimat yang sama juga ada di `/bantuan`. Bila cache dikosongkan, pengguna melihatnya sekali lagi (dapat diterima).
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

## Belum terverifikasi (perlakukan sebagai asumsi)

- Batas base64 per gambar untuk `qwen/qwen3.8-27b` (dokumentasi Groq umum: 4 MB base64; model Preview bisa berbeda). Dikelola lewat `LLM_VISION_MAX_BASE64_BYTES`. Foto 1280 px JPEG q82 biasanya 150–500 KB.
- "Maks 3 gambar per permintaan": kami selalu mengirim 1.
- Biaya token per gambar (2048) dan apakah resolusi 1280 px cukup untuk nota kecil/tulisan tangan.
- Kode galat penyedia saat model dihentikan (diasumsikan 404 `model_not_found` atau 400 `model_decommissioned`).
- Kualitas pembacaan nota berbahasa Indonesia dan kepatuhan model pada penanda `[TIDAK_TERBACA]` / `[BUKAN_DOKUMEN]`.

## Alternatif yang ditolak

- **Mengulang 429 dengan Retry-After pendek pada foto:** satu gambar ~2100 token; satu ulang sudah memakan ~25% kuota TPM gratis.
- **Memproses semua foto album:** biaya token berlipat dan draf ganda. Pengguna diminta mengirim satu foto per pesan.
- **Imagick / Intervention Image:** dependensi baru tanpa manfaat berarti. GD sudah terpasang dan dipakai `GdPemrosesGambar`.
- **Tabel baru untuk penanda privasi:** Cache sudah cukup untuk pemberitahuan sekali.
- **Menyimpan foto chat sebagai lampiran otomatis:** menambah data pribadi tersimpan (UU PDP). Lampiran tetap lewat alur aman Mini App.

## Konsekuensi

- Pesan foto tidak lagi otomatis pulih dari 429 sesaat; pengguna diminta mencoba lagi sekitar 1 menit kemudian.
- Kalimat privasi menambah satu baris pada balasan foto/suara pertama.
- Variabel env baru: `AI_IMAGE_TOKENS` (2100), `LLM_VISION_MAX_BASE64_BYTES` (4194304). Tidak ada perubahan kontrak REST (`openapi.yaml` tidak berubah).
