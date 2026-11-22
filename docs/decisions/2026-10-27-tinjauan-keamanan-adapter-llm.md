# Tinjauan keamanan: adapter LLM kompatibel OpenAI (Tahap 5a)

Tanggal: 2026-10-27. Branch: `feat/adapter-llm-openai-compat`. Peninjau: opus, read-only (kode dibaca, tes tidak dijalankan, `.env` tidak dibuka).

**Ringkasan:** 0 Kritis, 0 Tinggi, 1 Sedang, 4 Rendah, 5 Info. Tidak ada kode yang diubah sesudah tinjauan.

## Area bersih

- Kebocoran kunci/prompt: kunci hanya lewat `withToken`; body galat penyedia tidak dibaca; pesan exception asli dibuang; `ai_interactions.error` hanya berisi `llm:<kategori> http=<status> (<kode>)`; pengguna Telegram hanya menerima pesan ramah; artisan tidak pernah mencetak kunci; field `reasoning` dan `<think>` dibuang.
- Nonce dan peran: hasil tool tetap dibungkus `hasil_tool_<nonce>` berperan `tool`; peran tak dikenal (termasuk `system` palsu) dipaksa menjadi `user`; hasil vision disanitasi, dibungkus `data_pengguna_<nonce>`, dan model vision dipanggil tanpa tool.
- Argumen tool: JSON rusak, bukan objek, kedalaman > 32, atau > 64 KB ditolak; argumen terlindungi (`sppg_id`, `user_id`, `role`) dibuang; tool di luar peran atau di luar subset yang ditawarkan ditolak; aksi tulis hanya menjadi AiDraft.
- ToolSelector hanya mengiterasi tool yang diizinkan untuk peran, sehingga tidak ada jalur eskalasi hak; kata kunci memakai `preg_quote`.
- Retry maksimal 1, `Retry-After` hanya diikuti bila <= 3 detik dalam batas waktu 25 detik; retry hanya di tingkat HTTP sebelum tool dieksekusi, jadi tidak ada efek tulis ganda.
- Loop dibatasi `max_iterations` dan tenggat bersama 25 detik.
- SSRF: URL hanya dari config, wajib https, tanpa userinfo/query/fragmen, redirect tidak diikuti (LLM dan STT).

## Temuan

| No | Tingkat | Lokasi | Skenario | Status |
|---|---|---|---|---|
| 1 | Sedang | `config/ai.php` (`token_budget`) | Pelindung token global mati secara bawaan (0). Satu pesan bisa memicu ~10 permintaan HTTP; pembatas per pengguna tidak membatasi total lintas pengguna, sehingga 429 berantai atau kuota harian habis. | Terbuka: wajib isi `AI_TOKEN_PER_MINUTE=7000` dan `AI_TOKEN_PER_DAY=190000` pada deployment Groq (dicatat di laporan dan docs). |
| 2 | Rendah | `TokenBudget.php:57-82` | `check()` dan `record()` terpisah oleh panggilan jaringan; worker paralel bisa melampaui ambang kira-kira N x estimasi. | Diterima (estimasi kasar; penghitung itu sendiri atomik). |
| 3 | Rendah | `OpenAiCompatibleLlmClient.php:336-367` | Percobaan gagal (timeout/5xx/429) tidak dicatat ke anggaran; hitungan lebih rendah dari pemakaian nyata. | Diterima. |
| 4 | Rendah | `config/ai.php` | `LLM_API_KEY` dipakai bersama blok `anthropic` dan `openai_compat`; salah ganti `LLM_PROVIDER` mengirim kunci ke vendor yang salah. | Diterima; didokumentasikan: ganti provider = ganti kunci. |
| 5 | Rendah | `AiAgentService.php:116-117` | `report($e)` pada galat non-LlmException dapat memuat argumen fungsi (isi pesan) di stack trace bila `zend.exception_ignore_args=Off`. Kunci aman (properti objek). | Terbuka: pastikan `zend.exception_ignore_args=On` di produksi. |
| 6 | Info | `HttpsEndpoint.php` | Host IP internal/port bebas tidak ditolak; tidak ada perlindungan DNS rebinding. Dapat diterima karena URL hanya dari config. | Diterima. |
| 7 | Info | `OpenAiCompatibleLlmClient.php:157-163` | Regex base64 dijalankan sebelum cek ukuran; foto mentah > ~3 MB ditolak sebagai `terlalu_besar` (UX). | Catatan. |
| 8 | Info | `LlmException::fromStatus` | 400 `tool_use_failed` dari Groq menjadi `TIDAK_TERSEDIA` tanpa retry. | Catatan; verifikasi di staging. |
| 9 | Info | `AiAgentService.php:142` | Teks hasil vision ikut memengaruhi pemilihan tool; tidak menambah hak (subset peran, hanya AiDraft). | Diterima. |
| 10 | Info | `UjiLlm.php` | `--stt`/`--vision` membaca path yang bisa dibaca operator CLI; ukuran dibatasi dan `finfo` menolak berkas non-audio/gambar sebelum dikirim. | Diterima. |

## Belum teruji

- Belum ada panggilan ke Groq/DeepSeek sungguhan; format Groq dan kemampuan gambar `qwen/qwen3.8-27b` belum terverifikasi. Jalankan `php artisan sigap:llm-uji` (juga `--vision`, `--stt`) di staging.
- Estimasi token karakter/4 kasar; token asli bisa 15-30% lebih tinggi.
