# Tinjauan keamanan R-01 (berkas deploy VPS) dan tindak lanjut

Tanggal: 2026-10-28. Cabang `feat/r01-deploy-vps`. Tinjauan read-only (opus/high) atas Dockerfile, `docker/**`, `docker-compose.prod.yml`, `scripts/**`, `docs/ops/**`.
Hasil: 0 Kritis, 2 Tinggi, 5 Sedang, 16 Rendah, 3 Info. Perbaikan dikerjakan backend-dev; semua tanpa akses server/Docker (validasi statis saja).

## Ringkasan

- **Tinggi 1-2 (restore.sh)**: diperbaiki. Produksi tidak lagi di-DROP sebelum sumber terbukti valid. Isi dump yang belum tepercaya tidak pernah dijalankan sebagai root MySQL produksi: verifikasi di sandbox MySQL sesaat tanpa jaringan, lalu pemuatan ke DB staging di instans produksi dengan pengguna MySQL sementara berhak sempit, lalu pertukaran atomik `RENAME TABLE`. Dump dipindai (tolak `USE`, `CREATE/DROP DATABASE`, `GRANT`, `LOAD DATA`, `INTO OUTFILE`, perintah klien, termasuk di komentar bersyarat) dan wajib berpenanda akhir.
- **Sedang**: deploy tidak lagi mengganti kontainer mysql sebelum dump; deploy wajib lepas terminal; saringan CIDR webhook berlaku untuk semua varian jalur; CSP Report-Only dipertahankan dan menjadi butir Wajib uji staging; rclone memakai flag hanya-tulis.
- **Rendah/Info**: sebagian besar diperbaiki; sisanya TODO tercatat (digest image, cap_drop mysql, disable_functions).

## Area yang sudah bersih (tanpa temuan)

Hanya 80/443 IPv4 dipublikasikan; MySQL di jaringan internal tanpa `ports`; tanpa soket Docker di kontainer; image tanpa `.env`/cache bootstrap; kode image milik root, PHP non-root; `APP_ENV`/`APP_DEBUG`/`TELEGRAM_DEV_BYPASS` dipaksa di compose dan diperiksa preflight; hanya `public/index.php` dieksekusi lewat FastCGI; dotfiles/`vendor`/`storage` 404; Host/SNI tak dikenal ditolak; HSTS hanya di HTTPS; header API tidak ganda; healthcheck MySQL tanpa sandi di argumen; log nginx tanpa query string; kunci privat age tidak pernah di server.

## Tabel temuan

| No | Tingkat | Lokasi | Skenario | Status |
|---|---|---|---|---|
| 1 | Tinggi | `scripts/restore.sh` (DROP produksi) | DROP DB produksi sebelum sumber valid; dump pengaman pre-restore ikut rotasi pre-deploy sehingga dump yang dipulihkan dapat terhapus; pola `*.age` menerima arsip berkas | Diperbaiki: sumber disalin ke direktori kerja privat; hanya `*.sql.gz.age`, `*.sql.age`, `*.sql.gz`, `*.sql`; dekripsi+gzip+pindai penuh, sandbox, DB staging, verifikasi, baru `RENAME TABLE` atomik (tabel lama disimpan di `<DB>_pre_<waktu>`); rotasi `pre-restore` terpisah dari `pre-deploy` |
| 2 | Tinggi | `scripts/restore.sh`, `restore-test.sh` | Isi dump dijalankan sebagai root MySQL produksi (termasuk `--verify`/`--from`): dump berbahaya dapat membuat pengguna, membaca berkas, mengubah DB lain | Diperbaiki: verifikasi di sandbox `mysql` sesaat (`--network none`, memori dibatasi, datadir anonim); muat ke produksi hanya lewat pengguna sementara `sigap_restore` dengan GRANT pada DB staging saja; `--commands=OFF` bila klien mendukung; `scan_dump` menolak pernyataan berbahaya; catatan autentisitas age + Versioning/Object Lock di runbook |
| 3 | Sedang | `scripts/deploy.sh` (`up mysql` sebelum dump) | `up` dapat memperbarui/membuat ulang mysql (versi/konfigurasi baru) sebelum dump pra-migrasi | Diperbaiki: `config --hash mysql` dibanding label kontainer; berubah -> berhenti kecuali `--allow-db-change` (dump dari MySQL lama dulu, lalu maintenance, lalu buat ulang); jalur normal `up --no-recreate mysql`; `up --no-deps app queue scheduler nginx` |
| 4 | Sedang | `scripts/deploy.sh` | SSH putus saat migrasi membunuh proses, skema setengah jadi | Diperbaiki: wajib tmux/screen/systemd-run (atau `--allow-attached`); `trap '' HUP` sepanjang deploy; INT/TERM diabaikan selama migrasi; runbook diperbarui |
| 5 | Sedang | `docker/nginx/snippets/sigap-app.conf` | `location = /api/v1/telegram/webhook` terlewati oleh `/webhook/` (Laravel menormalkan ke rute sama) sehingga saringan CIDR dan zona tak berlaku | Diperbaiki: `location ^~ /api/v1/telegram/` dengan saringan CIDR + zona; uji SEC-19 |
| 6 | Sedang | `headers-miniapp.conf` | CSP lengkap hanya Report-Only | Didokumentasikan (sengaja tidak diubah): butir Wajib TG-13 + ringkasan go-live (tegakkan setelah uji di Telegram Web/Android/iOS/Desktop; opsional `report-to`) |
| 7 | Sedang | `scripts/backup.sh` (rclone) | Kebijakan IAM hanya `PutObject` membuat rclone gagal karena HEAD/cek bucket | Diperbaiki: `BACKUP_RCLONE_FLAGS` bawaan `--no-check-dest --s3-no-head --s3-no-check-bucket`; uji dengan kebijakan final di BK-01 |
| 8 | Rendah | `docker-compose.prod.yml` (certbot) | certbot di jaringan `edge` dapat menjangkau php-fpm; image tidak dipin digest | Diperbaiki: jaringan `acme` sendiri. TODO: pin digest (tidak dapat ditentukan offline) |
| 9 | Rendah | runbook (IMDS) | Kontainer yang diretas mengambil kredensial instance profile | Diperbaiki: runbook `HttpTokens=required`, hop limit 1; unit `scripts/systemd/sigap-imds-block.service` (DOCKER-USER DROP 169.254.169.254); Versioning/Object Lock bucket; uji SEC-20 |
| 10 | Rendah | compose/php-fpm | `DB_ROOT_PASSWORD` di `.env` terlihat oleh kontainer aplikasi; `clear_env=no` | Diperbaiki: `MYSQL_ROOT_PASSWORD` hanya di `.env.mysql` (env_file mysql); `clear_env=${PHP_FPM_CLEAR_ENV}` (`yes` di prod, `no` di dev); uji SEC-18/SEC-21 |
| 11 | Rendah | `env.production.example` | Perintah compose manual memakai berkas dev | Diperbaiki: `COMPOSE_FILE=docker-compose.prod.yml`, `COMPOSE_PROJECT_NAME=sigap` |
| 12 | Rendah | `40-sigap-config.sh` | `DOMAIN` kosong di prod diam-diam menyajikan HTTP | Diperbaiki: mode dev hanya dengan `SIGAP_NGINX_MODE=dev` (ditambahkan di `docker-compose.yml` dev); selain itu exit 1 |
| 13 | Rendah | nginx :443 default, FastCGI | Server default 443 tanpa `return`; Host mentah diteruskan | Diperbaiki: `return 444;` dan `fastcgi_param HTTP_HOST $host;` |
| 14 | Rendah | skrip | Bergantung bit eksekusi | Diperbaiki: pemanggilan antarskrip `bash scripts/...`; unit systemd `/bin/bash`; runbook memakai `bash scripts/...` (github-ops tetap `git add --chmod=+x`) |
| 15 | Rendah | unit systemd, backup | Timer gagal bila kunci dipegang/MySQL belum sehat; tanpa percobaan ulang | Diperbaiki: `SIGAP_LOCK_WAIT=1800` (`flock -w`), `Restart=on-failure`, `RestartSec=10min`, `StartLimitBurst=3`/3h; `wait_mysql_healthy` |
| 16 | Rendah | `restore-test.sh` | DB/berkas sementara tertinggal saat gagal | Diperbaiki: `trap on_exit EXIT` dengan guard (hapus sandbox dan dump sementara) |
| 17 | Rendah | `backup.sh` (dump polos) | Dump polos data pribadi menumpuk di disk | Diperbaiki: `BACKUP_PREDEPLOY_MAX_DAYS=14` + KEEP 3 per jenis; runbook: EBS terenkripsi wajib |
| 18 | Rendah | `deploy.sh` (ref, image) | Cabang bernama seperti tag menang atas tag; image registry tak terverifikasi | Diperbaiki: ref `v*` diutamakan sebagai tag; label `org.opencontainers.image.revision` (ARG `VCS_REF`) diperiksa, beda -> tolak. TODO: digest base image |
| 19 | Rendah | nginx zona memori | Zona 10m x 3 + cache TLS 10m di kontainer 48m | Diperbaiki: zona 1-2m, cache TLS 2m |
| 20 | Rendah | compose mysql | MySQL korban OOM pertama; tanpa cap_drop | Diperbaiki: `oom_score_adj: -500`. TODO: `cap_drop ALL` + cap minimal (entrypoint mysql butuh CHOWN/SETUID/SETGID/DAC_OVERRIDE; belum dapat diuji) |
| 21 | Rendah | preflight `deploy.sh` | Placeholder kunci API, `LOG_LEVEL=debug`, saringan webhook mati, cadangan kosong lolos | Diperbaiki: die untuk placeholder `LLM_API_KEY`/`STT_API_KEY` (bila fitur aktif) dan `LOG_LEVEL=debug`; warn `TELEGRAM_WEBHOOK_IP_FILTER=off`; warn cadangan kosong, die bila `DEPLOY_GO_LIVE=true` |
| 22 | Rendah | `scripts/lib/common.sh` | Sandi di `[client]` tanpa kutip rusak bila memuat `#`/`;` | Diperbaiki: `password="..."` dengan `\` dan `"` di-escape (diuji lokal dengan nilai tiruan) |
| 23 | Rendah | `.gitignore`, `.dockerignore` | Artefak deploy/cadangan dapat ter-commit atau masuk konteks build | Diperbaiki: `.gitignore` + `/.deploy/`, `*.age`, `*.sql`, `*.sql.gz`; `.dockerignore` + `auth.json`, `*.tar.gz`, `rclone.conf` |
| 24 | Info | `docker/php/php.ini` | `disable_functions`/`open_basedir`/`allow_url_fopen` belum dibatasi | TODO (komentar di php.ini): butuh uji regresi (artisan memakai proc_open) |
| 25 | Info | uji staging | Belum ada uji root MySQL hanya lokal | Didokumentasikan: SEC-18 `SELECT host FROM mysql.user WHERE user='root'` |
| 26 | Info | `docs/plan/2026-10-27-r01-deploy-vps.md` | Nama rencana berbeda dari implementasi; `sanctum.expiration=null` | Didokumentasikan: bagian (k) pemetaan nama; catatan PDP token Sanctum tak kedaluwarsa untuk backend (tidak diubah di PR ini) |

## Belum teruji tanpa server

Sandbox MySQL (`docker run --network none`, kesiapan TCP, memori 512m pada VM 2 GiB); dukungan `--commands` di klien mysql 8.4; `RENAME TABLE` atomik lintas DB dengan FK; pengguna sementara `sigap_restore`; deteksi `config --hash mysql` vs label kontainer; `clear_env=yes` pada aplikasi nyata; `.env.mysql` sebagai env_file; aturan `DOCKER-USER` IMDS dan rclone `--network host`; flag rclone hanya-tulis terhadap kebijakan IAM final; `oom_score_adj`; nginx (`nginx -t`, caps, `^~ /api/v1/telegram/`, `HTTP_HOST $host`); timer systemd dengan `Restart=on-failure`; seluruh alur deploy/rollback/restore end-to-end. Semua tercantum di `docs/ops/uji-staging.md` (SEC-18..22, BK-01/06/08/10, RB-10..12).
