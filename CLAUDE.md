# SiGAP SPPG — Pedoman Tim AI (dibaca otomatis oleh semua agen)

SiGAP SPPG adalah sistem informasi dapur MBG berbasis **Telegram Mini App + Bot + Agen AI**.
Acuan lengkap: `docs/Revisi_Laporan_Tubes_PPL_v2.docx` (Subbab 5.2 = sumber kebenaran ruang lingkup).

## Stack
- Backend: PHP 8.4, Laravel 13 (MVC + Service + Policy + Form Request + Job), Sanctum 4, MySQL 8.4
- Frontend: Telegram Mini App — HTML/CSS/JS vanilla (ES2020+), `telegram-web-app.js`, Vite, Chart.js
- Infra: Docker Compose (nginx, app, queue, scheduler, mysql), GitHub Actions, VPS Ubuntu 24.04
- Agen AI: LlmClient (antarmuka), ToolRegistry disaring per peran, AiDraft + konfirmasi manusia

## 16 fitur CRUD (urutan resmi, jangan diubah)
| No | Fitur | Penanggung jawab |
|---|---|---|
| 1 | Data Pengguna (Login Akun) | Kelompok |
| 2 | Notifikasi Perjalanan Mobil ke Tujuan | Dhimas |
| 3 | Data SPPG | Dhimas |
| 4 | Data Titik Layanan | Dhimas |
| 5 | Data Penerima Manfaat | Daven |
| 6 | Data Bahan Baku | Hilmy |
| 7 | Data Pemasok | Hilmy |
| 8 | Data Menu dan Gizi | Hilmy |
| 9 | Data Jadwal Produksi dan Distribusi | Prabowo |
| 10 | Data Log Produksi Harian | Prabowo |
| 11 | Data Pemeriksaan Mutu dan Keamanan Pangan | Prabowo |
| 12 | Data Armada dan Pengemudi | Daven |
| 13 | Data Bukti Distribusi | Daven |
| 14 | Data Keuangan (pencairan + pengeluaran) | Mutiara |
| 15 | Data Feedback dan Keluhan | Mutiara |
| 16 | Laporan Rekapitulasi Harian Operasional | Mutiara |

Jadwal: Sprint 1 = minggu 7–10 (26 Okt – 22 Nov 2026, fondasi + data master),
Sprint 2 = minggu 11–14 (23 Nov – 20 Des 2026, operasional + notifikasi + rekap).

## Tim AI dan pembagian wilayah file
| Agen | Wilayah file (hanya agen ini yang mengedit) |
|---|---|
| `project-manager` | `docs/plan/**`, `docs/decisions/**` (tidak menulis kode aplikasi) |
| `backend-dev` | `app/**`, `routes/**`, `database/**`, `config/**`, `tests/**`, `docs/api/openapi.yaml`; berkas deploy: `Dockerfile`, `docker/**`, `docker-compose*.yml`, `scripts/**` (kecuali `scripts/create-labels.sh` dan `scripts/create-issues.sh`), `docs/ops/**`, `.dockerignore`, `.gitattributes` |
| `frontend-dev` | `resources/miniapp/**`, `resources/css/**`, `resources/js/**`, `vite.config.js` |
| `github-ops` | `.github/**`, `CHANGELOG.md`, `scripts/create-labels.sh`, `scripts/create-issues.sh`, branch, commit, PR, issue, label |

Dua agen tidak boleh mengedit file yang sama. Bila perlu perubahan di wilayah agen lain, kirim pesan (SendMessage) ke agen pemiliknya.

## Tingkat tugas → model & effort (WAJIB dipakai PM saat mendelegasikan)
PM menilai setiap tugas lalu mengirim parameter `model` dan `effort` saat memanggil agen.

| Tier | Ciri tugas | Model | Effort |
|---|---|---|---|
| **T0 Rutin** | git/commit/PR, rename, typo, format, menjalankan tes, update CHANGELOG, membaca log CI | `haiku` | `low` |
| **T1 Standar** | CRUD satu fitur, satu layar Mini App, seeder, tes untuk kode yang sudah jelas | `sonnet` | `medium` |
| **T2 Penting** | logika bisnis (validasi kapasitas, stok min/kedaluwarsa, ambang anggaran 80/100%, prioritas keluhan kritis, estimasi tiba, rekap 17.00), integrasi lintas fitur, bug multi-file | `sonnet` | `high` |
| **T3 Kritis** | autentikasi initData HMAC/Sanctum, RBAC & scope SPPG, Agen AI (ToolRegistry, prompt injection, AiDraft), migrasi yang mengubah/menghapus data, keamanan unggahan, keputusan arsitektur, deploy produksi | `opus` | `high` (pakai `xhigh` bila tersedia) |

Aturan hemat token:
1. Mulai dari tier terendah yang masuk akal; naikkan satu tier hanya bila hasil gagal review/tes dua kali.
2. Jangan pakai `opus` untuk pekerjaan T0–T1. Jangan pakai `haiku` untuk menulis logika bisnis.
3. Beri agen konteks spesifik (fitur, file, kontrak API) agar tidak menjelajah repo tanpa arah.
4. Agen melapor ringkas: apa yang diubah, file, hasil tes. Tanpa menempel isi file panjang.

## Alur kolaborasi per PBI
1. PM memecah PBI menjadi tugas (TaskCreate) dengan label tier dan ketergantungan.
2. `backend-dev` membuat migrasi → model → Form Request → Policy → Service → Controller → tes, lalu memperbarui `docs/api/openapi.yaml` dan mengirim pesan ke `frontend-dev` berisi endpoint + contoh JSON.
3. `frontend-dev` membuat layar Mini App sesuai mockup (Gambar 9–27) memakai kontrak API tersebut.
4. `github-ops` membuat branch `feat/pbi-<no>-<slug>`, commit bertahap (Conventional Commits), membuka PR dengan checklist DoD, memantau CI.
5. PM meninjau PR. Perubahan T3 wajib ditinjau dengan `opus`. PM menandai tugas selesai hanya jika DoD terpenuhi.

## Definition of Done
- Endpoint + layar Mini App selesai, lolos validasi dan Policy RBAC (akses tanpa hak → 403)
- Tool Agen AI fitur tersedia dan aksi tulis hanya menjadi draf yang dikonfirmasi pengguna
- Feature test lulus (`php artisan test`), kode lolos `vendor/bin/pint --test`
- PR ditinjau, CI hijau, berjalan di Docker staging

## Larangan
- Jangan membaca/menulis `.env`, token bot, atau kunci API. Gunakan `.env.example`.
- Jangan `git push --force` ke `main`, jangan merge PR tanpa persetujuan manusia.
- Jangan menyimpan NIK atau data pribadi berlebih (UU PDP). Data contoh selalu fiktif.
