# Evaluasi akurasi Agen AI (NFR-11)

Perintah `php artisan sigap:ai-evaluasi` mengukur seberapa tepat Agen AI memilih tool dan mengisi argumen untuk 100 kasus
teks fiktif berbahasa Indonesia (`tests/ai-eval/kasus.json`, 16 modul, minimal 5 kasus per modul).

**Memanggil LLM sungguhan (kuota penyedia terpakai).** Hanya dijalankan manual di mesin pengembang atau staging; tidak
pernah dijalankan oleh CI maupun scheduler (perintah menolak berjalan bila `CI`/`GITHUB_ACTIONS` terdeteksi di luar tes).

## Prasyarat
- `LLM_PROVIDER` (`openai_compat` atau `anthropic`) dan kunci/URL/model terisi di `.env` server atau mesin pengembang.
  Bila belum, perintah berhenti dengan pesan jelas dan tidak memanggil apa pun. `fake` tidak bermakna untuk evaluasi.
- Basis data dev/staging yang sudah dimigrasi. Basis data produksi tidak disarankan (lihat "Keamanan data").

## Cara menjalankan
```bash
# semua 100 kasus (jeda bawaan AI_EVAL_JEDA_MS = 3000 ms antar-kasus)
php artisan sigap:ai-evaluasi

# dicicil agar kuota gratis (mis. Groq TPM/TPD) cukup: kasus 1-25, lalu 26-50, dst.
php artisan sigap:ai-evaluasi --mulai=1  --batas=25
php artisan sigap:ai-evaluasi --mulai=26 --batas=25 --jeda=5000

# berkas kasus lain
php artisan sigap:ai-evaluasi --berkas=tests/ai-eval/kasus.json
```

| Opsi | Arti |
|---|---|
| `--mulai=N` | nomor urut kasus pertama (mulai dari 1) |
| `--batas=N` | jumlah kasus maksimum; kosong = sampai akhir |
| `--berkas=` | berkas kasus JSON (relatif terhadap root proyek atau path absolut) |
| `--jeda=ms` | jeda antar-kasus; bawaan `config('ai.evaluasi.jeda_ms')` (env `AI_EVAL_JEDA_MS`, 3000) |

Galat penyedia (429 kuota/TPM, timeout, dll.) pada satu kasus dicatat sebagai `galat` dan run berlanjut. Kasus `galat`
tidak ikut penyebut akurasi (dilaporkan terpisah); ulangi dengan `--mulai`/`--batas` setelah kuota pulih.

## Keluaran
- Tabel ringkas di konsol: per modul dan TOTAL (kasus, galat, tool benar, akurasi tool, kolom cocok/total, akurasi kolom).
- Berkas `storage/app/ai-eval/<Y-m-d_His>.json`: `ringkasan`, `kasus` (yang dijalankan), `hasil` (per kasus: status,
  tool dipilih/diharapkan, argumen aktual/diharapkan, kolom cocok, token, durasi).
- Akurasi tool = kasus dengan tool dipilih sama dengan yang diharapkan / kasus dinilai. Akurasi kolom = jumlah kunci
  argumen yang diharapkan yang nilainya cocok / jumlah kunci yang diharapkan (tool salah = 0 kolom cocok). Normalisasi:
  teks trim + huruf kecil, angka numerik, boolean, tanggal `Y-m-d`, jam `H:i`.
- Status kasus: `ok`, `ditolak_penyedia` (penyedia menolak tool call; argumen dari failed_generation dinilai),
  `ditolak_server` (HakModulAi menolak tanpa LLM), `tanpa_tool` (model hanya menjawab teks), `galat`.

## Cara kerja (tanpa menyimpan data)
Tiap kasus: transaksi DB baru -> SPPG + pengguna fiktif dengan peran kasus -> `AiAgentService::handle()` (pipeline yang sama
dengan bot: sanitasi, rate limit, HakModulAi, ToolRegistry per peran, ToolSelector, prompt sistem) -> rollback. LlmClient
dibungkus `PerekamLlmClient`: pilihan tool + argumen model direkam, iterasi sesudahnya dijawab teks tetap tanpa ke penyedia.
Tool tulis hanya membuat `AiDraft` (tidak pernah mengeksekusi aksi) dan ikut di-rollback; tool baca hanya membaca.
Jumlah baris `ai_drafts`, `ai_interactions`, `users`, `sppgs` dibandingkan sebelum/sesudah run (`data_tidak_berubah`).

## Keamanan data
- Kasus hanya berisi data fiktif (tanpa NIK, tanpa nama atau HP sungguhan). Pesan kasus dikirim ke penyedia LLM.
- Kunci API tidak pernah dicetak atau disimpan di berkas hasil. Auto-increment MySQL dapat naik walau baris di-rollback.
- Jangan menambah kasus berisi data sungguhan atau NIK.
