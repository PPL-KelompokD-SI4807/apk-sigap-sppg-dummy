# Serah-terima SiGAP SPPG ke Hermes Agent (2026-10-29)

Dokumen ini merangkum seluruh keadaan proyek dari percakapan Claude sebelumnya. Bacalah sebelum mengerjakan apa pun. Aturan kerja ada di `AGENTS.md`. Rincian revisi yang belum dikerjakan ada di `docs/handoff/revisi/`. Tinjauan keamanan terperinci ada di `docs/decisions/`, rencana di `docs/plan/`, operasi server di `docs/ops/`.

## 1. Posisi proyek: sekitar 70%
Revisi pengguna diberi kode R-01..R-07. Kode aplikasi dan tesnya sudah matang; sisanya terutama deploy nyata, dua fitur, dan uji menyeluruh.

| Tahap | Isi | Status |
|---|---|---|
| 0 | Baseline: 22 PR lama ditutup, develop dipromosikan ke main (PR #26, #21, #27) | Selesai. Tag `baseline-v0.1` opsional, belum dibuat |
| 1 | R-03 login password + token sekali pakai dari bot (PR #28) | Selesai |
| 2a | R-06a peran investor + multi-SPPG (PR #29) | Selesai |
| 2b-1 | R-06b-1 harga pemasok + persetujuan menu oleh investor (PR #30) | Selesai |
| 5a | Adapter LLM Groq + STT Whisper (PR #31), `.env.example` (PR #33) | Selesai, teruji nyata |
| 2b-2 | Pembelian bahan, verifikasi akuntan, persetujuan pengeluaran (PR #32, rapi di #34) | Selesai |
| 3 | R-02 menu tombol bot per peran (PR #35, #36, #37, #38) | Selesai |
| 6 (berkas) | R-01 berkas deploy: Dockerfile, compose produksi, nginx HTTPS, deploy.sh, backup, restore (PR #39); wilayah file (PR #40) | Selesai (di repo) |
| 6 (kecil) | Pengaman skrip label/issue (PR #41) | Dibuka, CI hijau, **menunggu merge manusia** (cek statusnya) |
| 6 (server) | Deploy nyata ke AWS | **Belum.** Server siap, domain menunggu |
| 4 | R-07 dashboard per peran + ekspor PDF | Belum |
| 5b | R-05 foto/suara langsung di bot | Belum (adapter siap) |
| 6b | R-04 akses lewat website | Belum (butuh domain + HTTPS) |
| 7 | Staging, MySQL 8.4 nyata, Telegram nyata, tinjauan keamanan akhir | Belum |

Tes lokal terakhir: 1900 dari 1901 lulus; satu gagal hanya di Windows (`UnggahanAmanTest::berkas php bertopeng jpg ditolak` memberi 500, bukan 422; lulus di CI Linux). CI hijau di PHP 8.3 dan 8.4.

## 2. Keputusan yang sudah diambil (jangan diulang tanpa alasan)
**Auth (R-03).** Dua lapis: initData Telegram divalidasi setiap Mini App dibuka, lalu login username/no telepon + password + token 6 digit dari bot (5 menit, sekali pakai, ter-hash). Sesi tidak kedaluwarsa kecuali logout, password/peran/SPPG/status berubah, atau dicabut admin. `SesiService::punyaSesiPenuh` wajib dipakai di setiap jalur data, termasuk bot. Reset password: diminta pengguna, **disetujui admin SPPG**, baru token dikirim; akun terkunci tidak bisa memulai reset; admin tidak pernah melihat password. Aturan password: min 8, huruf besar-kecil, angka, bukan username, tolak daftar sandi umum lokal.

**Investor (R-06).** Satu investor banyak SPPG (pivot `investor_sppg`), hanya baca kecuali setuju/tolak. Data penerima manfaat agregat dengan ambang k=5 (`config/rbac.php`). Persetujuan menu per pengajuan, biaya dibekukan (snapshot) saat diajukan. Pengeluaran non-menu langsung wajib disetujui investor (`SIGAP_AMBANG_PERSETUJUAN_PENGELUARAN`, bawaan 0 = semua). **Pembelian bahan terverifikasi akuntan tidak lewat investor** dan otomatis menjadi pengeluaran disetujui (keputusan pengguna "untuk saat ini"); pembelian **tidak membuat mutasi stok** (komentar di `app/Services/Pembelian/PembelianBahanService.php`; tes pengunci belum ada). Keputusan investor hanya setuju/tolak.

**Bot (R-02).** `callback_data` n1 (navigasi baca, bertanda tangan, terikat telegram_id, TTL 30 menit) dan b1 (aksi tulis sekali pakai di tabel `bot_aksi`). Hak dicek ulang tiap klik. Tanpa sesi penuh hanya tombol "Masuk lewat Mini App". Admin: mode baca-saja (`SppgAktif::untukBacaAdmin`). Aksi tulis dua langkah: kirim ulang rekap, mulai/picu perjalanan, proses keluhan. `protect_content`, `setMyCommands`. Temuan 9 dibiarkan; R02-D (rute formulir mutasi stok) ditunda.

**LLM/STT.** Groq via endpoint kompatibel OpenAI. Teks `openai/gpt-oss-120b`, vision `qwen/qwen3.8-27b` (preview), STT `whisper-large-v3-turbo`. Uji nyata lulus (`php artisan sigap:llm-uji --vision=berkas --stt=berkas`). Paket gratis ketat (8K token/menit, 200K/hari): isi `AI_TOKEN_PER_MINUTE=7000` dan `AI_TOKEN_PER_DAY=190000`. Kunci hanya di `.env`. Data nyata ke pihak ketiga menunggu keputusan privasi (UU PDP); demo pakai data fiktif.

**Hosting.** AWS Free Tier, ap-southeast-1. EC2 `sigap-staging`: Ubuntu 24.04, t3.small (2 vCPU, 2 GiB), 40 GiB gp3, swap 2 GiB, IMDSv2, termination protection, Elastic IP `52.74.42.218`, security group: SSH hanya IP pengguna, 80/443 terbuka, MySQL tertutup. AWS Budgets $30/bulan. Kredit sisa sekitar $120, paket gratis berakhir **2027-03-16**; cadangan keluar server wajib sebelum itu. Kunci `.pem` hanya di PC pengguna.

**Deploy (R-01, PR #39).** Backup: rclone ke S3 ap-southeast-1 + enkripsi age (bucket dan role dibuat pengguna sebelum go-live; kunci privat tidak di server). Domain staging sementara `<ip>.sslip.io`; domain tetap dari GitHub Student Pack (Name.com, gratis satu tahun, TLD yang memenuhi syarat belum dipastikan; pengguna ingin `sigapsppg.app`). Mode image `local` (job GHCR menyusul setelah staging). Urutan runbook: deploy pertama (HTTP) → sertifikat → webhook (`php artisan sigap:telegram-set-webhook`). Health check `/up`. VM tidak dimatikan selama uji; backup harian. Detail lengkap: `docs/ops/runbook-deploy.md`, daftar uji `docs/ops/uji-staging.md`, tinjauan `docs/decisions/2026-10-28-tinjauan-keamanan-r01.md`.

**Lain-lain.** Format rekap keuangan resmi BGN **belum ada**; ekspor PDF ditunda sampai pengguna mendapat contoh dari SPPG (lihat `revisi/r07-format-rekap-keuangan.md`). Wilayah berkas deploy dimiliki "backend" (PR #40).

## 3. Jebakan teknis yang sudah ditemui
- CI menyalin `.env.example` ke `.env`: nilai penyedia (LLM_PROVIDER, URL, model) harus **komentar** di `.env.example`; hanya nilai kosong yang aktif. `phpunit.xml` menetralkan `LLM_`/`STT_`/`AI_` dengan `force="true"`.
- `--vision`/`--stt` pada `sigap:llm-uji` perlu `=nama-berkas`; STT butuh `STT_DRIVER=http` di `.env`.
- `docker build` belum pernah dijalankan di CI; berkas Docker/nginx belum teruji (bug `public/build` vs `public/miniapp` sudah diperbaiki di Dockerfile).
- Telegram Web: CSP `frame-ancestors` Telegram untuk `/miniapp/` sudah dipasang; CSP penuh Mini App masih Report-Only (wajib diuji di staging).
- Batas unggah konsisten: nginx 6m, PHP upload 5M / post 6M, `config/unggahan.php` 5 MiB.
- `scripts/create-labels.sh` pernah ikut berjalan tanpa sengaja dan menimpa label GitHub; PR #41 menambahkan guard (`--dry-run`, `--apply`, konfirmasi "ketik YA").
- Pemeriksaan GitHub bawaan "uneditable / draft" kadang macet; itu bukan job workflow kita.
- Windows: perintah `!`-prefix membalik status keluar saat uji skrip; jangan salah baca.
- Deploy lewat SSH harus di tmux/screen/systemd-run (SIGHUP diabaikan saat migrasi). Rotasi `DB_PASSWORD` lewat `ALTER USER`.
- `sanctum.expiration=null` (token tidak kedaluwarsa): catatan PDP, sengaja sesuai keputusan R-03.

## 4. Pekerjaan tersisa, urutan disarankan
1. Merge PR #41 bila belum (setelah persetujuan pengguna). `git checkout develop && git pull`.
2. **Deploy percobaan pertama** ke AWS mengikuti `docs/ops/runbook-deploy.md` memakai `<ip>.sslip.io`. Pengguna yang mengakses server (SSH); agen memandu dan memperbaiki berkas. Lanjut `docs/ops/uji-staging.md`.
3. Domain tetap: pengguna klaim di Name.com (benefit GitHub aktif sekitar 30 Okt 2026), catatan A ke `52.74.42.218`, isi `DOMAIN`, terbitkan sertifikat, pasang webhook.
4. R-07 dashboard per peran (`GET /api/v1/dashboard`, ringkasan di bot + layar Mini App Chart.js); ekspor PDF keuangan menunggu format BGN.
5. R-05 foto/suara di bot dengan konfirmasi di chat ("Simpan / Ubah / Batal").
6. R-04 akses website (cookie Sanctum SPA, token dari bot sebagai faktor kedua, HTTPS wajib).
7. Tahap 7 (backlog di bawah).
8. PR kecil: job GHCR di `.github`; samakan jalur penolakan berkas bertopeng lintas OS; sembunyikan panel "Lampiran lain" pada pengeluaran disetujui (server menjawab 409); tes pengunci "verifikasi pembelian tidak membuat mutasi stok".

## 5. Backlog tahap 7
- Tinjauan ulang keamanan atas R-06a (S-01..S-11): celah interval agregat (butuh jumlah sel tersamar ≥ 2k-1), redaksi nama LLM bisa dilewati karakter lebar nol (buang `\p{Cf}`), temuan Info lain.
- Info R-02: secret webhook kuat; `punyaSesiPenuh` perlu cek `perangkat_tepercaya.dicabut_at` bila pencabutan dipisah.
- Adapter LLM: butir Rendah/Info; R-03 T-1/T-2 sudah diperbaiki, R-3/R-5/R-6 dicatat (replay initData 1 jam, audit pencabutan otomatis, render label perangkat sebagai teks).
- Uji nyata: MySQL 8.4 (migrasi `000102`, balapan), Telegram sungguhan (deep link, tombol), foto nota end-to-end, Docker staging, beban, sertifikat, restore/rollback.
- Dari R-01: TODO pin digest certbot, `cap_drop` mysql, `disable_functions`/`open_basedir`, `read_only` filesystem kontainer; Object Lock/tanda tangan untuk keaslian backup; IAM hanya-tulis mungkin butuh hak baca (HEAD).
- Opsional: enkripsi volume EBS, kegiatan kredit AWS tambahan.

## 6. Keputusan yang masih menunggu pengguna
1. Ambang rupiah pengeluaran non-menu (sementara semua perlu persetujuan).
2. Konfirmasi daftar notifikasi investor: pengeluaran/menu diajukan, anggaran 80% dan 100%, stok kritis, insiden mutu, keluhan kritis, distribusi gagal.
3. Format rekap keuangan dan penandatangan (Kepala BGN atau wakil, investor) dari SPPG.
4. Apakah foto nota wajib untuk setiap pengeluaran atau hanya peringatan.
5. Peninjauan widget dashboard per peran bersama pengguna lapangan.
6. R-04: setuju token bot sebagai faktor kedua di web; fitur apa saja di web; domain final.

## 7. Cara bekerja dengan pengguna
Pembagian peran: Claude Code menulis kode; Hermes memandu, memverifikasi PR, dan mencatat keputusan (lihat `AGENTS.md`).
Pengguna menyelesaikan aplikasi dulu dan mendelegasikan keputusan teknis ke agen. Jawab ringkas dalam bahasa Indonesia. Setiap PR dilaporkan nomor, CI, dan temuan; **merge hanya setelah pengguna mengetik persetujuan** (mis. "ya, merge #N ke develop"). Verifikasi PR lewat REST (`gh api repos/.../pulls/N`), jangan percaya laporan agen lain begitu saja (pernah salah soal mutasi stok). Catat keputusan di `docs/decisions/`.

Atribusi commit: riwayat lama memuat trailer Claude (Sonnet/Haiku). Itu dibiarkan; tidak ada force push.
