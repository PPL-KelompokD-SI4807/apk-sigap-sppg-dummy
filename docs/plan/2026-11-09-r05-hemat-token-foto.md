# Rencana R-05 lanjutan: hemat token pesan foto di chat bot

- Tanggal: 2026-11-09
- Branch: `fix/r05-hemat-token-foto` (dari `develop` aaaeff8, sudah memuat PR #59)
- Fitur terkait: lintas fitur Agen AI (input foto nota; uji staging pada Fitur 6/14 pembelian bahan/pengeluaran)
- ADR: `docs/decisions/2026-11-08-input-foto-chat.md` (bagian baru "Anggaran token per pesan")

## Temuan staging (log `ai.llm_galat`, tanpa isi)
Foto nota -> loop agen 3 iterasi pada `openai/gpt-oss-120b`, tiap iterasi ~3200 token (9 skema tool + riwayat),
total ~9700 > batas Groq gratis 8000 TPM -> 429 `rate_limit_exceeded` (tipe tokens) pada iterasi 3 ->
bot membalas "Sedang ramai". Pelindung token lokal bukan penyebab; pra-pemrosesan foto OK.

## Tujuan
1. Satu pesan foto selesai di bawah anggaran TPM penyedia (target total <= ~5000 token, tanpa mengurangi aturan keamanan).
2. 429 bertipe tokens dipulihkan otomatis (tunda job sekali-dua kali), pengguna diberi tahu satu kali.
3. Pesan galat dibedakan: terlalu besar / sedang ramai / konfigurasi salah.

## Tugas
| No | Tugas | Agen | Tier | Model/effort | Bergantung |
|---|---|---|---|---|---|
| 1 | Buat branch dari develop terbaru | github-ops | T0 | haiku/low | - |
| 2 | Telusuri urutan iterasi; subset tool untuk foto; ringkas riwayat; perpendek instruksi sistem (nonce, scope SPPG, RBAC tetap); batas iterasi + estimasi token total sebelum panggil; retry 429 tokens via release job (retry-after/60 dtk, maks 1-2, satu pesan "Foto diterima, sedang diproses"); pemisahan pesan galat; feature test; pint | backend-dev | T3 | opus/high | 1 |
| 3 | Perbarui ADR (anggaran token per pesan) | project-manager | - | - | 2 |
| 4 | Commit bertahap, PR ke develop dengan checklist DoD, pantau CI | github-ops | T0 | haiku/low | 2, 3 |

## Kriteria selesai
- Tes: 429 tokens -> retry sekali lalu sukses; retry habis -> pesan ramah; subset tool pada foto; batas iterasi;
  jalur teks (menu, stok, keluhan) dan alih ke Mini App tanpa regresi; tool yang tidak ditawarkan tidak bisa dipanggil.
- `php artisan test` dan `vendor/bin/pint --test` lulus; CI hijau. Tidak di-merge tanpa persetujuan manusia.

## Risiko
- Subset tool terlalu sempit -> foto non-nota (mis. foto menu) gagal dipetakan. Mitigasi: subset berbasis peran + jenis
  foto, fallback ke alih Mini App, bukan error.
- Retry job berulang menggandakan balasan/draf. Mitigasi: idempoten per update_id, notifikasi "sedang diproses" sekali.
- Memperpendek prompt melemahkan pertahanan injeksi. Mitigasi: aturan nonce/scope/RBAC dipertahankan verbatim; tes injeksi.
- README.md termodifikasi dan `.tmp-nginx/` di working tree milik manusia: tidak di-commit.
