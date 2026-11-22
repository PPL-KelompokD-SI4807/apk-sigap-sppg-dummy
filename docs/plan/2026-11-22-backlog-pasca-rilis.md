# Backlog pasca-rilis 22 Nov 2026

Rilis 22 Nov sudah di `main` (fcf334d). Target: semua PR ke `develop` siap tinjau 22 Nov 2026 pukul 23.00 WIB.
Tanpa merge, tanpa deploy. Maksimal 2 agen aktif, maksimal 1 opus.

## Tugas

| No | Isu | Fitur | Tier | Agen | Branch | Bergantung pada |
|---|---|---|---|---|---|---|
| 1 | #175 penyamaran Agen AI menyingkat kata kapital | AI lintas modul (15) | T3 | backend-dev opus/high | `fix/issue-175-penyamaran-ai` | - |
| 2 | #176 filter tanggal dan tab Keluhan | 15 | T1 | frontend-dev sonnet/medium | `fix/issue-176-filter-keluhan` | - |
| 3a | #177 penyatuan status distribusi + notifikasi PIC (backend) | 2, 9, 13 | T2 | backend-dev sonnet/high | `feat/issue-177-status-notifikasi-pic` | slot kosong |
| 3b | #177 status kirim ke PIC di detail jadwal (Mini App + web) | 2, 9 | T1 | frontend-dev sonnet/medium | sama dengan 3a | kontrak API 3a |
| 4a | #172 GET /api/v1/audit-log khusus Admin | 1 | T2 | backend-dev sonnet/high | `feat/issue-172-log-audit` | slot kosong |
| 4b | #172 layar Log Audit (Mini App + web) | 1 | T1 | frontend-dev sonnet/medium | sama dengan 4a | kontrak API 4a |
| 5 | k6 uji beban NFR-01 + docs/ops/uji-beban.md | NFR-01 | T1 | backend-dev sonnet/medium | `chore/uji-beban-k6` | boleh menyusul |
| 6 | artisan sigap:ai-evaluasi NFR-11 | NFR-11 | T2 | backend-dev sonnet/high | `feat/ai-evaluasi` | boleh menyusul |
| 7 | Commit, PR, CI per butir | - | T0 | github-ops haiku/low | - | tiap butir selesai |
| 8 | Tinjau PR 1-4, tulis docs/qa/2026-11-22-uji-ulang.md | - | T1 | qa-engineer sonnet/medium | `docs/qa-uji-ulang-22nov` | PR 1-4 |

## Kriteria selesai per butir
- #175: masking nama hanya pada nilai kolom data orang (nama, PIC, penerima, pengemudi); HP/NIK tetap tersamar; tes regresi "Kemasan Uji Bocor, hubungi 081234567890"; semua tes AI lama lulus.
- #176: placeholder pada dua filter tanggal, tidak melebar keluar layar 360 px, tab "Selesai" utuh; pola sama dengan P12 (PR #169); modul lain dengan filter tanggal sama diperiksa.
- #177: (a) PATCH ke "berangkat" lewat TripNotificationService, idempoten; (b) notifikasi perjalanan dari "siap" mengubah status ke "berangkat"; (c) "diterima" kirim konfirmasi ke PIC; fallback Kepala SPPG (FR-12); lewat antrean; aturan petugas F-02 dan gerbang mutu tetap; tes fitur a/b/c; status kirim tampil di detail jadwal.
- #172: Policy Admin saja, tiap peran lain 403 (dites); filter modul/pengguna/aksi/tanggal; paginasi; nilai lama/baru tanpa kolom auditExclude; OpenAPI diperbarui; layar Mini App + web.
- Semua: `php artisan test` lulus, `vendor/bin/pint --test` lulus, CI hijau.

## Status (22 Nov 2026, 19.45 WIB)
| Butir | PR | CI | Catatan |
|---|---|---|---|
| #176 | #179 | hijau | QA: `.seg` global perlu uji visual |
| #175 | #180 | hijau | 1 revisi saat review opus (kata data uji staging) |
| #177 | #181 | hijau | keputusan manusia: pesan ganda via bukti distribusi |
| #172 | #182 | hijau | QA Sedang: saringan tampilan audit hanya membuang kunci sensitif |
| 5 k6 | #183 | hijau | keputusan manusia: throttle 60/menit |
| 6 ai-evaluasi | #184 | berjalan | belum diuji terhadap LLM sungguhan |
| QA | PR dokumen | - | 0 temuan Kritis/Tinggi (tinjauan statis) |

## Risiko
- Waktu sempit (mulai 18.45 WIB): butir 5 dan 6 boleh menyusul.
- #175 bisa memecah tes AI lama; mitigasi: jalankan seluruh suite AI.
- #177 menyentuh alur notifikasi (antrean, idempotensi): tes dengan Queue::fake dan Http::fake, tidak memanggil Telegram asli.
- Agen paralel di checkout yang sama: tiap agen kerja di worktree terpisah.
