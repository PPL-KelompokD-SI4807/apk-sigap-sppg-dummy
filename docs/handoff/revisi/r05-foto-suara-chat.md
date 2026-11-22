# R-05 — Input foto dan pesan suara langsung di chat bot (tanpa membuka Mini App)

Status: dicatat (2026-10-25). Sebagian ada di kode (pipeline foto dan suara). Adapter LLM Groq dan STT sudah selesai dan teruji (PR #31). Belum dikerjakan: konfirmasi draf di chat dan uji nyata dari bot. Prasyarat R-02 dan R-03 sudah selesai. Tingkat kritis (Agen AI, data pribadi, pengiriman data ke pihak ketiga).

## Kondisi kode
- `app/Services/Telegram/AiChatHandler.php`: teks, foto, dan pesan suara di chat bot diproses oleh job.
  - Foto: unduh ukuran terbesar (maks 5 MB, JPEG/PNG/WebP, MIME diverifikasi dari isi), dikirim ke LLM sebagai lampiran gambar; keterangan foto (caption) dipakai sebagai teks.
  - Suara: `SpeechToTextService` mengubah suara menjadi teks (maks 120 detik / 10 MB), lalu diproses Agen AI.
  - Hasil: Agen AI menyusun draf data (AiDraft); bot membalas teks polos dan tombol "Periksa & Simpan" yang membuka Mini App.
- Aktif bila `.env` memuat `LLM_PROVIDER`/`LLM_*` dan `STT_DRIVER=http`/`STT_*` (lihat `.env.example`). Kosong = klien Null; bot kembali ke formulir manual.

## Pekerjaan
1. Konfirmasi draf langsung di chat dengan tombol "Simpan / Ubah / Batal", tanpa wajib membuka Mini App (tetap draf yang dikonfirmasi manusia; "Ubah" membuka Mini App ke formulir terisi). Pakai pola callback `b1` (aksi tulis sekali pakai) dari R-02.
2. Ringkasan hasil di chat yang jelas (data terbaca, kolom kurang), dan pesan galat ramah bila foto buram atau suara tidak jelas.
3. Uji nyata: foto nota, surat jalan, stok; pesan suara Bahasa Indonesia untuk log produksi, keluhan, mutasi stok. Model vision `qwen/qwen3.8-27b` berstatus preview; siapkan cadangan.
4. Tinjauan keamanan: prompt injection lewat teks di foto, data pribadi pada foto/suara yang dikirim ke penyedia, batas laju, dan biaya (pelindung `AI_TOKEN_PER_MINUTE/DAY`).

## Catatan keamanan dan privasi
- Foto dan suara dikirim ke pihak ketiga (Groq). Beri tahu pengguna; data nyata menunggu keputusan privasi. Demo pakai data fiktif.
- Teks hasil pembacaan foto dan transkrip suara adalah masukan tidak tepercaya (nonce dan pembatasan tool tetap berlaku). Aksi tulis hanya berupa draf yang dikonfirmasi pengguna.
- Foto asli tidak otomatis disimpan; bila perlu sebagai bukti, lewat alur lampiran aman yang ada.
