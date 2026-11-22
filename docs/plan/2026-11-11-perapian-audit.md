# Rencana perapian dan issue audit (12-22 Nov 2026)

Tujuan: menutup temuan audit kecil sebelum Sprint 2 (23 Nov). `backend-dev` harus bebas pada 23 Nov untuk R-04.
Di luar cakupan: rencana R-07, PR #63, cabang `docs/r07-rencana`.

## Issue
| Kode | Issue | Isi |
|---|---|---|
| a | #64 | StandarGiziController tidak memanggil AiDraftConfirmer |
| b | #65 | Draf hapus (hapus_*) tidak ditandai terpakai |
| c | #66 | Perapian Dockerfile / phpunit.xml / .gitignore / .env.example |

## Tugas
| PR | Tier | Agen (model/effort) | Cabang | Isi | Issue |
|---|---|---|---|---|---|
| 1 | T0 | github-ops (haiku/low) | `chore/perapian-dokumen-env` | CLAUDE.md (phpunit.xml ke wilayah backend-dev), .gitignore (`.tmp-nginx/`), .env.example (komentar `AI_STT_DETIK_PER_JAM`), CHANGELOG | Refs #66 |
| 2 | T1 | backend-dev (sonnet/medium) | `chore/dockerfile-chmod` | `COPY --chmod=u=rwX,go=rX` di dua tahap Dockerfile | Closes #66 |
| 3 | T2 | backend-dev (sonnet/high) + frontend-dev (sonnet/medium) | `fix/draf-standar-gizi-hapus` | StandarGiziController memanggil AiDraftConfirmer; draf hapus ditandai terpakai lewat `ai_draft_id` pada DELETE; layar Mini App mengirimkannya; tes; openapi + ai-tools.md | Closes #64, #65 |

## Urutan dan ketergantungan
Langkah 0 (issue) -> PR 1 -> PR 2 -> PR 3. PR 2 dan PR 3 sama-sama `backend-dev`, dijalankan berurutan.
Frontend (PR 3) menunggu kontrak DELETE dari backend.

## Risiko
- PR 2 mengubah build image: risiko izin berkas salah; rollback = revert PR dan build ulang image sebelumnya (pengguna deploy ke staging).
- PR 3: DELETE dengan body/query `ai_draft_id` harus kompatibel mundur (opsional) agar klien lama tidak rusak.
- `.env.example` hanya boleh menambah baris komentar (CI menjalankan `cp .env.example .env`).
- README.md dan `docs/referensi/` tidak ikut commit.

## Persetujuan manusia
Merge tiap PR (satu per satu setelah CI hijau) dan deploy staging PR 2.
