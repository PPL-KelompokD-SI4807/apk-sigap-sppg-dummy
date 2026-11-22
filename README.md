# SiGAP SPPG

Sistem informasi dapur MBG (Makan Bergizi Gratis) berbasis **Telegram Mini App, Bot, dan Agen AI**.
Proyek PPL Kelompok D SI4807 (repo dummy; seluruh data contoh fiktif).

## Stack
- Backend: PHP 8.4, Laravel 13 (MVC + Service + Policy + Form Request + Job), Sanctum 4, MySQL 8.4
- Frontend: Telegram Mini App (HTML/CSS/JS vanilla), Vite, Chart.js
- Infra: Docker Compose (nginx, app, queue, scheduler, mysql), GitHub Actions
- Agen AI: LlmClient, ToolRegistry per peran, AiDraft + konfirmasi manusia

## Struktur folder
```
app/ routes/ database/ config/ tests/   backend Laravel (backend-dev)
resources/miniapp/ resources/js|css/    Mini App (frontend-dev)
docker/                                 konfigurasi nginx dan PHP
.github/                                CI, template PR/issue, label
scripts/                                skrip label dan issue
docs/                                   rencana, keputusan, API, sumber
```
Dokumentasi: [CLAUDE.md](CLAUDE.md), [matriks PBI](docs/plan/2026-10-24-matriks-pbi.md),
[proposal](docs/sumber/proposal.md), [keputusan](docs/decisions/), [API](docs/api/).

## Menjalankan lokal

### Docker
```bash
cp .env.example .env        # isi DB_PASSWORD dan DB_ROOT_PASSWORD, jangan commit .env
docker compose up -d --build
docker compose exec app php artisan key:generate
docker compose exec app php artisan migrate --seed
```
Aplikasi di http://localhost:8080 (ubah dengan `APP_PORT`).

### Tanpa Docker
Butuh PHP 8.4 (ekstensi pdo_mysql, bcmath, intl, gd, zip), Composer, Node 22, MySQL 8.4.
```bash
composer install && npm ci
cp .env.example .env && php artisan key:generate
php artisan migrate --seed
php artisan serve        # terminal 1
npm run dev              # terminal 2
php artisan queue:work   # terminal 3 (opsional)
```

## Tes, gaya kode, build
```bash
php artisan test
vendor/bin/pint --test   # tanpa --test untuk memperbaiki otomatis
npm run build
```

## Alur branch
Basis `develop`; `main` hanya untuk rilis. Branch fitur: `feat/pbi-<no>-<slug>`, perbaikan: `fix/<slug>`, rutin: `chore/<slug>`.
Commit memakai Conventional Commits berbahasa Indonesia. PR berjudul `[PBI-<no>] <Nama fitur>`, memakai template PR, dan baru di-merge setelah persetujuan manusia dan CI hijau.

## 16 fitur (PBI 1-16)
Setiap fitur adalah satu PBI. Nomor PBI dipakai di nama branch (`feat/pbi-<no>-<slug>`) dan judul PR (`[PBI-<no>] <Nama fitur>`).

| PBI | Fitur | Keterangan | Penanggung jawab | Sprint rencana |
|---|---|---|---|---|
| 1 | Data Pengguna (Login Akun) | Akun pengguna, peran, login password + token dari bot, sesi, reset password | Seluruh kelompok (auth: Dhimas) | 1 |
| 2 | Notifikasi Perjalanan Mobil ke Tujuan | Notifikasi saat mobil distribusi berangkat dan perkiraan tiba di tujuan | Dhimas | 2 |
| 3 | Data SPPG | Data satuan pelayanan (dapur) beserta pengaturannya | Dhimas | 1 |
| 4 | Data Titik Layanan | Lokasi tujuan distribusi, misalnya sekolah | Dhimas | 1 |
| 5 | Data Penerima Manfaat | Data penerima manfaat per titik layanan; investor hanya melihat agregat (ambang k=5) | Daven | 1 |
| 6 | Data Bahan Baku | Stok bahan, mutasi stok, peringatan stok minimum dan kedaluwarsa | Hilmy | 1 (scheduler stok: 2) |
| 7 | Data Pemasok | Data pemasok dan harga bahan | Hilmy | 2 |
| 8 | Data Menu dan Gizi | Menu, kandungan gizi, dan persetujuan menu oleh investor | Hilmy | 1 |
| 9 | Data Jadwal Produksi dan Distribusi | Jadwal masak dan pengiriman, termasuk validasi kapasitas | Prabowo | 1 (distribusi: 2) |
| 10 | Data Log Produksi Harian | Catatan produksi harian dapur | Prabowo | 2 |
| 11 | Data Pemeriksaan Mutu dan Keamanan Pangan | Pemeriksaan mutu makanan dan pencatatan insiden | Prabowo | 2 |
| 12 | Data Armada dan Pengemudi | Data kendaraan dan pengemudi | Daven | 1 |
| 13 | Data Bukti Distribusi | Bukti makanan sampai di tujuan, termasuk foto | Daven | 2 |
| 14 | Data Keuangan (pencairan + pengeluaran) | Pencairan dana, pengeluaran, pembelian bahan, ambang anggaran 80% dan 100% | Mutiara | 1 (pencairan), 2 (pengeluaran) |
| 15 | Data Feedback dan Keluhan | Masukan dan keluhan dengan prioritas | Mutiara | 2 |
| 16 | Laporan Rekapitulasi Harian Operasional | Rekap harian operasional otomatis pukul 17.00 | Mutiara | 2 |

Kolom penanggung jawab menunjukkan pembagian tanggung jawab fitur dalam kelompok. Kode dikembangkan dengan bantuan Claude Code.

Sprint 1 = 26 Okt - 22 Nov 2026; Sprint 2 = 23 Nov - 20 Des 2026.

## Tim AI
| Agen | Wilayah |
|---|---|
| `project-manager` | `docs/plan/**`, `docs/decisions/**` |
| `backend-dev` | `app/**`, `routes/**`, `database/**`, `config/**`, `tests/**`, `docs/api/openapi.yaml`; berkas deploy: `Dockerfile`, `docker/**`, `docker-compose*.yml`, `scripts/**` (kecuali `scripts/create-labels.sh` dan `scripts/create-issues.sh`), `docs/ops/**` |
| `frontend-dev` | `resources/miniapp/**`, `resources/css/**`, `resources/js/**`, `vite.config.js` |
| `github-ops` | `.github/**`, `CHANGELOG.md`, `scripts/create-labels.sh`, `scripts/create-issues.sh`, branch, PR, issue |
