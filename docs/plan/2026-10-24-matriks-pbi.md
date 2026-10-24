# Matriks PBI SiGAP SPPG

Sumber: `docs/sumber/proposal.md` (Tabel 14, Tabel 15, Tabel 5). Urutan fitur mengikuti `CLAUDE.md`.

Catatan: seluruh fitur dikerjakan sekarang oleh tim AI tanpa menunggu sprint. Kolom **Sprint rencana**
menunjukkan sprint tempat fitur seharusnya dikerjakan menurut proposal, dan **PIC** adalah anggota
manusia yang bertanggung jawab atas fitur tersebut.

| PBI | Fitur | PIC (manusia) | Sprint rencana | Kebutuhan | Use case | Mockup | Tier utama |
|---|---|---|---|---|---|---|---|
| 1 | Data Pengguna (Login Akun) | Seluruh kelompok (auth: Dhimas) | Sprint 1 | FR-01, FR-02 | UC1, UC2 | Gambar 18 | T3 |
| 2 | Notifikasi Perjalanan Mobil ke Tujuan | Muhammad Dhimas Pranaya | Sprint 2 | FR-12 | UC3 | Gambar 21 | T2 |
| 3 | Data SPPG | Muhammad Dhimas Pranaya | Sprint 1 | FR-03 | UC4 | Gambar 19 | T1 |
| 4 | Data Titik Layanan | Muhammad Dhimas Pranaya | Sprint 1 | FR-03 | UC5 | Gambar 20 | T1 |
| 5 | Data Penerima Manfaat | Daven Bagus Putra Santoso | Sprint 1 | FR-03 | UC6 | Gambar 22 | T1 |
| 6 | Data Bahan Baku (+ mutasi stok) | Hilmy Herdiansyah Shiddiq | Sprint 1 (scheduler stok: Sprint 2) | FR-04 | UC7 | Gambar 11 | T2 |
| 7 | Data Pemasok | Hilmy Herdiansyah Shiddiq | Sprint 2 | FR-04 | UC8 | Gambar 24 | T1 |
| 8 | Data Menu dan Gizi | Hilmy Herdiansyah Shiddiq | Sprint 1 | FR-05 | UC9 | Gambar 25 | T1 |
| 9 | Data Jadwal Produksi dan Distribusi | Prabowo Kuntjoro Analta Ruswanto | Sprint 1 (jadwal distribusi: Sprint 2) | FR-06, FR-08 | UC10 | Gambar 26 | T2 |
| 10 | Data Log Produksi Harian | Prabowo Kuntjoro Analta Ruswanto | Sprint 2 | FR-06 | UC11 | Gambar 13 | T1 |
| 11 | Data Pemeriksaan Mutu dan Keamanan Pangan | Prabowo Kuntjoro Analta Ruswanto | Sprint 2 | FR-07 | UC12 | Gambar 14 | T2 |
| 12 | Data Armada dan Pengemudi | Daven Bagus Putra Santoso | Sprint 1 | FR-08 | UC13 | Gambar 23 | T1 |
| 13 | Data Bukti Distribusi | Daven Bagus Putra Santoso | Sprint 2 | FR-08 | UC14 | Gambar 15 | T2 |
| 14 | Data Keuangan (pencairan + pengeluaran) | Mutiara Qolbu Thohirah | Sprint 1 (pencairan), Sprint 2 (pengeluaran) | FR-09 | UC15 | Gambar 12, 16 | T2 |
| 15 | Data Feedback dan Keluhan | Mutiara Qolbu Thohirah | Sprint 2 | FR-07 | UC16 | Gambar 17 | T2 |
| 16 | Laporan Rekapitulasi Harian Operasional | Mutiara Qolbu Thohirah | Sprint 2 | FR-13 | UC17 | Gambar 10, 27 | T2 |
| – | Agen AI (lintas fitur) | Seluruh anggota (kerangka: Daven) | Kerangka Sprint 1, tool per fitur Sprint 2 | FR-11 | UC18, UC19 | Gambar 9, 12 | T3 |

## Pekerjaan lintas fitur

| Pekerjaan | PIC (manusia) | Sprint rencana | Tier |
|---|---|---|---|
| Autentikasi initData + Sanctum, kerangka RBAC/Policy | Muhammad Dhimas Pranaya | Sprint 1 | T3 |
| Skema bersama, seeder data fiktif, kerangka OpenAPI, tabel audit | Mutiara Qolbu Thohirah | Sprint 1 | T1 |
| Cangkang Mini App (themeParams, router, klien API, komponen) | Hilmy Herdiansyah Shiddiq | Sprint 1 | T1 |
| Docker Compose, CI GitHub Actions, staging | Prabowo Kuntjoro Analta Ruswanto | Sprint 1 | T1 |
| Bot Telegram, webhook + secret_token, onboarding /start, kerangka Agen AI | Daven Bagus Putra Santoso | Sprint 1 | T3 |
| NotificationService terpusat, dasbor Kepala SPPG, STT dan pipeline foto | Daven Bagus Putra Santoso | Sprint 2 | T2 |
| Log audit, uji policy lintas SPPG | Muhammad Dhimas Pranaya | Sprint 2 | T2 |

## Pembagian gelombang eksekusi tim AI

1. Kerangka: Laravel 13, fondasi auth/RBAC (T3), skema bersama (T1), cangkang Mini App, infra.
2. Fitur grup A (1, 3, 4, 5, 7, 12), grup B (6, 8, 9, 10, 11), grup C (2, 13, 14, 15, 16).
3. Bot, webhook, Agen AI dan tool per fitur (T3 untuk kerangka).
4. Tinjauan keamanan (opus), `php artisan test`, `pint`, dokumentasi, branch dan PR per PBI.
