# Runbook Deploy SiGAP SPPG ke VM AWS (staging dan produksi)

Untuk pemilik sistem yang bukan ahli server. Ikuti langkah berurutan. Setiap langkah berisi **Perintah**, **Hasil yang diharapkan**, dan **Bila gagal**.

Dokumen ini ditulis dari berkas di repo (`docker-compose.prod.yml`, `Dockerfile`, `docker/**`, `scripts/**`, `docs/ops/env.production.example`) dan **belum dijalankan pada server nyata**. Lihat bagian "Hal yang belum teruji tanpa server" sebelum percaya pada angka atau perilaku tertentu. Daftar uji di VM ada di `docs/ops/uji-staging.md`.

Aturan emas:
- Jangan pernah meng-commit atau menempel `.env`, token bot, kunci API, kunci privat age, atau kunci `.pem` ke GitHub, chat, atau tangkapan layar.
- Di dokumen ini semua nilai rahasia berupa placeholder. Nilai nyata hanya ada di `/opt/sigap/app/.env` di server (mode 600) dan di pengelola kata sandi Anda.
- Data contoh selalu fiktif. Jangan menyalin data pribadi nyata ke staging.

---

## 0. Gambaran singkat dan istilah

| Hal | Nilai persis |
|---|---|
| Layanan Compose (`docker-compose.prod.yml`, nama proyek `sigap`) | `nginx`, `app` (php-fpm), `queue`, `scheduler`, `mysql`, `certbot` (sesaat, profil `tools`) |
| Volume | `mysql_data`, `app_storage`, `certbot_etc`, `certbot_www` (di Docker tampil dengan awalan proyek: `sigap_mysql_data`, dst.) |
| Port yang dibuka ke internet | hanya 80 dan 443 (nginx). MySQL tidak punya `ports` dan berada di jaringan internal `db` |
| Folder kode di server | `/opt/sigap/app` |
| Pengguna server untuk deploy | `deploy` (anggota grup `docker`; setara akses root, pegang satu orang saja) |
| Status/keadaan deploy | `/opt/sigap/app/.deploy/` (`image.env`, `previous`, `history`, `last-predeploy`, `last-pre-restore`, `ops.lock`; diabaikan git) |
| Rahasia di server | `/opt/sigap/app/.env` (aplikasi) dan `/opt/sigap/app/.env.mysql` (HANYA `MYSQL_ROOT_PASSWORD`, khusus kontainer `mysql`), keduanya mode 600 |
| Dump pengaman polos | `/var/backups/sigap/pre-deploy/` dan `/var/backups/sigap/pre-restore/` (rotasi terpisah, maks 3 berkas dan 14 hari) |
| Health check aplikasi | `GET /up` |
| Perintah webhook | `php artisan sigap:telegram-set-webhook` (opsi `--info`, `--delete`, `--drop-pending`, `--url`, `--no-menu`) |
| Admin pertama | `php artisan sigap:buat-admin <telegram_id> "<nama>"` |

Alur total: siapkan VM -> instal Docker -> ambil kode -> isi `.env` -> **deploy pertama (HTTP saja)** -> terbitkan sertifikat (staging dulu, lalu produksi) -> daftarkan webhook -> uji -> pasang timer backup/sertifikat -> pilih dan uji tujuan backup.

> Urutan di sini sengaja deploy **sebelum** sertifikat. `scripts/bootstrap-cert.sh` menjalankan nginx dengan `--pull never`, sehingga image harus sudah ada (dibuat oleh `scripts/deploy.sh`). Saat sertifikat belum ada, nginx hanya melayani HTTP :80 (tantangan ACME, `/up`, dan pengalihan ke HTTPS), dan `deploy.sh` memeriksa kesehatan lewat HTTP lokal.

### Pembantu perintah `dcp` (dipakai di seluruh dokumen)

Compose perlu dua berkas `--env-file`. Setelah login sebagai `deploy` (langkah 9), tambahkan fungsi ini sekali:

```bash
cat >> ~/.bashrc <<'EOF'
dcp() {
  local a=(-f /opt/sigap/app/docker-compose.prod.yml --project-directory /opt/sigap/app --env-file /opt/sigap/app/.env)
  [ -f /opt/sigap/app/.deploy/image.env ] && a+=(--env-file /opt/sigap/app/.deploy/image.env)
  docker compose "${a[@]}" "$@"
}
EOF
source ~/.bashrc
```

Contoh: `dcp ps`, `dcp logs --tail=100 app`, `dcp exec app php artisan about`.

`.env` juga memuat `COMPOSE_FILE=docker-compose.prod.yml` dan `COMPOSE_PROJECT_NAME=sigap`, sehingga `docker compose ...` biasa dari `/opt/sigap/app` tetap memakai berkas produksi; `dcp` tetap disarankan karena menyertakan label image rilis (`.deploy/image.env`).

---

## BAGIAN A. Persiapan AWS dan domain

### Langkah 1. Buat VM

Spesifikasi (sesuai rencana `docs/plan/2026-10-27-r01-deploy-vps.md`):
- Wilayah **ap-southeast-1** (Singapura).
- Tipe **t3.small** (2 vCPU, 2 GiB RAM).
- OS **Ubuntu Server 24.04 LTS** (x86_64).
- Disk **gp3 sekitar 38 GB**, **WAJIB terenkripsi** (centang "Encrypted"): dump pengaman polos (`pre-deploy`/`pre-restore`) dan datadir MySQL berisi data pribadi.
- Pasangan kunci SSH: buat baru di konsol (RSA/ED25519), simpan berkas `.pem` **hanya di laptop**, `chmod 400`. Jangan masukkan ke repo.

Perintah (konsol AWS): EC2 -> Launch instance -> isi nilai di atas -> Launch.

Hasil yang diharapkan: instance berstatus `running` dan `2/2 checks passed`.

Bila gagal: kuota/Free plan menolak tipe -> pilih t3.micro hanya untuk gladi (RAM 1 GiB tidak cukup untuk produksi), atau minta kenaikan kuota.

### Langkah 2. Security Group (firewall AWS)

Security Group adalah kontrol utama. Firewall `ufw` tidak cukup karena Docker menulis aturan iptables sendiri.

Inbound **hanya**:

| Tipe | Port | Sumber |
|---|---|---|
| HTTP | 80 | 0.0.0.0/0 (IPv4 saja) |
| HTTPS | 443 | 0.0.0.0/0 (IPv4 saja) |
| SSH | 22 | `<IP-publik-Anda>/32` saja |

Jangan membuka 3306 (MySQL), 9000 (php-fpm), atau rentang lain. Jangan menambahkan aturan IPv6 (`::/0`) dan jangan membuat catatan DNS AAAA: compose hanya memublikasikan IPv4. Outbound biarkan bawaan (semua), karena server perlu menjangkau Telegram, Groq, Let's Encrypt, apt, dan registry Docker.

Cek IP Anda: buka `https://checkip.amazonaws.com`. IP rumah/kampus bisa berubah; bila tidak bisa SSH, perbarui aturan 22 di konsol.

Hasil yang diharapkan: tiga aturan inbound di atas, tidak ada yang lain.

Bila gagal: tidak bisa SSH -> periksa IP berubah, atau gunakan EC2 Instance Connect/SSM dari konsol sementara.

### Langkah 3. IP statis (Elastic IP)

Konsol: EC2 -> Elastic IPs -> Allocate -> Associate ke instance.

Alasan: domain, sertifikat, dan webhook menunjuk ke IP. Tanpa IP statis, IP berubah setiap VM di-stop/start dan semuanya harus diulang. Catatan biaya: IPv4 publik berbayar (lihat bagian Hemat kredit); IP statis yang **tidak terpasang** pada instance berjalan tetap ditagih.

Hasil yang diharapkan: konsol menampilkan Elastic IP (contoh dokumentasi: `203.0.113.10`) terkait instance Anda.

### Langkah 4. Wajibkan IMDSv2

Konsol: EC2 -> pilih instance -> Actions -> Instance settings -> Modify instance metadata options -> **IMDSv2: Required**, hop limit **1**.

Hop limit 1 cukup: `scripts/backup.sh` menjalankan rclone dengan `--network host`, sehingga kredensial instance profile dapat dijangkau tanpa hop tambahan.

Setara CLI (dari laptop dengan kredensial admin): `aws ec2 modify-instance-metadata-options --instance-id <ID> --http-tokens required --http-put-response-hop-limit 1 --http-endpoint enabled`.

Selain itu kontainer ber-jaringan bridge diblokir dari IMDS oleh unit `sigap-imds-block.service` (aturan `DOCKER-USER -d 169.254.169.254/32 -j DROP`, dipasang di langkah 16). Dengan begitu kontainer aplikasi yang diretas tidak dapat mengambil kredensial instance profile, sementara rclone (`--network host`) tetap bisa.

Hasil yang diharapkan: pengaturan tersimpan. Verifikasi dari VM (langkah 6): `curl -s -o /dev/null -w "%{http_code}\n" http://169.254.169.254/latest/meta-data/` menampilkan `401`. Setelah langkah 16: `docker run --rm --pull missing alpine wget -T 3 -qO- http://169.254.169.254/ ; echo $?` harus gagal (bukan 0).

### Langkah 5. Domain dan DNS

Pilihan:
- **Punya domain**: buat catatan DNS `A` untuk `sigap.<domain-anda>` -> Elastic IP. TTL 300.
- **Belum punya domain (staging)**: pakai `<ip-dengan-tanda-strip>.sslip.io`. Contoh IP `203.0.113.10` menjadi `203-0-113-10.sslip.io`. Tanpa konfigurasi DNS apa pun. Telegram mewajibkan HTTPS valid, jadi nama ini diperlukan. Verifikasi batas penerbitan Let's Encrypt untuk `sslip.io` saat dipakai (domain bersama, batas bisa lebih ketat). Bila IP berubah, nama ini ikut berubah (sertifikat, `.env`, webhook harus diulang).

Cek (dari laptop):

```bash
nslookup sigap.contoh.id        # atau: nslookup 203-0-113-10.sslip.io
```

Hasil yang diharapkan: menampilkan Elastic IP Anda.

Bila gagal: tunggu propagasi (hingga beberapa menit sampai TTL); jangan lanjut ke langkah 14 sebelum benar.

---

## BAGIAN B. Menyiapkan server

### Langkah 6. Masuk, perbarui, swap 2 GiB, paket

```bash
chmod 400 kunci-sigap.pem
ssh -i kunci-sigap.pem ubuntu@203.0.113.10
```

Lalu di VM (akun `ubuntu`, punya `sudo`):

```bash
sudo apt-get update && sudo apt-get -y upgrade
sudo apt-get install -y git curl openssl age unattended-upgrades ca-certificates

# Swap 2 GiB sebagai penyangga OOM
sudo fallocate -l 2G /swapfile
sudo chmod 600 /swapfile
sudo mkswap /swapfile
sudo swapon /swapfile
echo '/swapfile none swap sw 0 0' | sudo tee -a /etc/fstab
echo 'vm.swappiness=10' | sudo tee /etc/sysctl.d/99-sigap.conf
sudo sysctl --system

# Batasi ukuran log sistem
sudo sed -i 's/^#\?SystemMaxUse=.*/SystemMaxUse=200M/' /etc/systemd/journald.conf
sudo systemctl restart systemd-journald
```

Biarkan zona waktu server UTC. Aplikasi memakai `Asia/Jakarta` lewat konfigurasi, dan timer systemd menyebut `Asia/Jakarta` sendiri.

Hasil yang diharapkan: `free -h` menampilkan Swap 2.0Gi; `swapon --show` menampilkan `/swapfile`; `age --version` mencetak versi.

Bila gagal: `fallocate` gagal -> `sudo dd if=/dev/zero of=/swapfile bs=1M count=2048`. Paket `age` tidak ada -> unduh biner dari rilis resmi `github.com/FiloSottile/age` dan taruh di `/usr/local/bin`.

### Langkah 7. Instal Docker Engine dan plugin Compose

Mengikuti petunjuk resmi Docker untuk Ubuntu (periksa `docs.docker.com/engine/install/ubuntu/` bila ada perubahan):

```bash
sudo install -m 0755 -d /etc/apt/keyrings
sudo curl -fsSL https://download.docker.com/linux/ubuntu/gpg -o /etc/apt/keyrings/docker.asc
sudo chmod a+r /etc/apt/keyrings/docker.asc
echo "deb [arch=$(dpkg --print-architecture) signed-by=/etc/apt/keyrings/docker.asc] https://download.docker.com/linux/ubuntu $(. /etc/os-release && echo "${UBUNTU_CODENAME:-$VERSION_CODENAME}") stable" | sudo tee /etc/apt/sources.list.d/docker.list >/dev/null
sudo apt-get update
sudo apt-get install -y docker-ce docker-ce-cli containerd.io docker-buildx-plugin docker-compose-plugin
sudo systemctl enable --now docker
docker --version && docker compose version
sudo docker info 2>&1 | grep -i -E "swap|warning"
```

Hasil yang diharapkan: versi Docker dan Compose v2 tercetak. Baris `docker info` **tidak** boleh memuat peringatan "No swap limit support". Bila muncul, batas `memswap_limit` kontainer tidak berlaku (lihat Troubleshooting OOM dan bagian "belum teruji").

Bila gagal: `apt` tidak bisa mengambil -> periksa outbound Security Group dan DNS.

### Langkah 8. Pengguna `deploy`

```bash
sudo adduser --disabled-password --gecos "" deploy
sudo usermod -aG docker deploy
sudo install -d -m 700 -o deploy -g deploy /home/deploy/.ssh
sudo cp ~/.ssh/authorized_keys /home/deploy/.ssh/authorized_keys
sudo chown deploy:deploy /home/deploy/.ssh/authorized_keys
sudo chmod 600 /home/deploy/.ssh/authorized_keys
sudo install -d -o deploy -g deploy /opt/sigap
sudo install -d -m 700 -o deploy -g deploy /var/backups/sigap
sudo install -d -m 755 -o deploy -g deploy /etc/sigap
```

Pengguna `deploy` sengaja tanpa `sudo`. Tugas yang butuh `sudo` (memasang timer, mengubah SSH) dikerjakan lewat akun `ubuntu`.

Hasil yang diharapkan: dari laptop `ssh -i kunci-sigap.pem deploy@203.0.113.10` berhasil, dan `docker ps` (tanpa sudo) tidak error. Setelah itu jalankan blok `dcp` di bagian 0.

Bila gagal: `permission denied (publickey)` -> periksa izin `/home/deploy/.ssh` (700) dan `authorized_keys` (600). `docker ps` ditolak -> logout lalu login ulang agar keanggotaan grup `docker` berlaku.

### Langkah 9. Ambil kode (deploy key hanya-baca)

Sebagai `deploy`:

```bash
ssh-keygen -t ed25519 -C "sigap-deploy" -f ~/.ssh/id_ed25519 -N ""
cat ~/.ssh/id_ed25519.pub
```

Tambahkan kunci **publik** itu di GitHub: repo -> Settings -> Deploy keys -> Add (jangan centang "Allow write access"). Lalu:

```bash
ssh -T git@github.com     # cocokkan sidik jari (fingerprint) dengan yang diumumkan GitHub sebelum mengetik "yes"
git clone <URL-SSH-REPO> /opt/sigap/app
cd /opt/sigap/app
git checkout feat/r01-deploy-vps    # sampai PR merge; sesudahnya develop atau tag rilis
```

Berkas deploy (`scripts/`, `docker-compose.prod.yml`, `docs/ops/`) baru ada di cabang `feat/r01-deploy-vps` sampai PR digabung ke `develop`.

Hasil yang diharapkan: `ls /opt/sigap/app/scripts/deploy.sh` ada.

Bila gagal: `Permission denied (publickey)` -> kunci belum ditambahkan ke repo yang benar. Repo privat dan tidak ada akses -> minta pemilik repo menambahkan deploy key.

---

## BAGIAN C. Konfigurasi `.env`

### Langkah 10. Siapkan `.env` (jangan di-commit)

`.env` ada di `.gitignore`, tetapi tetap jangan pernah `git add` berkas ini.

```bash
cd /opt/sigap/app
cp docs/ops/env.production.example .env
chmod 600 .env

# Bangkitkan rahasia acak langsung ke .env (nilai tidak ditampilkan)
sed -i "s|^APP_KEY=.*|APP_KEY=base64:$(openssl rand -base64 32)|" .env
sed -i "s|^DB_PASSWORD=.*|DB_PASSWORD=$(openssl rand -hex 32)|" .env
sed -i "s|^TELEGRAM_WEBHOOK_SECRET=.*|TELEGRAM_WEBHOOK_SECRET=$(openssl rand -hex 32)|" .env

# Sandi root MySQL di berkas TERPISAH (hanya kontainer mysql yang membacanya; lihat docs/ops/env.mysql.example)
umask 077 && printf 'MYSQL_ROOT_PASSWORD=%s\n' "$(openssl rand -hex 32)" > .env.mysql && chmod 600 .env.mysql
```

`DB_ROOT_PASSWORD` tidak lagi dipakai di `.env`; bila masih ada (salinan lama), pindahkan nilainya ke `.env.mysql` sebagai `MYSQL_ROOT_PASSWORD` lalu hapus barisnya dari `.env` (deploy memberi peringatan).

Catatan APP_KEY: komentar di `env.production.example` menyebut `php artisan key:generate --show` lewat Compose, tetapi itu membutuhkan image yang belum dibuat. Format `base64:` + 32 byte acak (AES-256-CBC, sesuai `config/app.php`) hasil `openssl` sama dengan hasil `key:generate`, dan lolos pemeriksaan `deploy.sh`.

Edit sisanya dengan `nano .env`:

| Variabel | Isi |
|---|---|
| `DOMAIN` | nama host publik tanpa `https://` (mis. `sigap.contoh.id` atau `203-0-113-10.sslip.io`) |
| `APP_URL` | `https://<DOMAIN>` |
| `TELEGRAM_MINIAPP_URL` | `https://<DOMAIN>/miniapp/` |
| `CERTBOT_EMAIL` | email Anda (pemberitahuan Let's Encrypt) |
| `TELEGRAM_BOT_TOKEN`, `TELEGRAM_BOT_USERNAME` | dari @BotFather (username tanpa `@`) |
| `LLM_API_KEY`, `STT_API_KEY` | kunci API Groq (dua variabel terpisah, isi keduanya) |
| `LLM_VISION_MODEL` | biarkan kosong sampai `sigap:llm-uji --vision` lolos |
| `SIGAP_AMBANG_PERSETUJUAN_PENGELUARAN` | keputusan bisnis, lihat bagian "Keputusan bisnis" |
| `DEPLOY_IMAGE_MODE` | biarkan `local` (belum ada alur push image ke registry) |

Aturan nilai: tanpa spasi di sekitar `=`, tanpa kutip di rahasia, **jangan** memakai karakter `$ # \` atau kutip dalam rahasia.

Staging dan produksi sama-sama memakai `APP_ENV=production`, `APP_DEBUG=false`, `TELEGRAM_DEV_BYPASS=false`; `deploy.sh` menolak nilai lain.

Simpan cadangan nilai penting di pengelola kata sandi: `grep '^APP_KEY=' .env` (tampil di layar Anda sendiri; jangan di-screenshot ke grup). APP_KEY **tidak** ikut cadangan otomatis; kehilangannya berarti data terenkripsi tak terbaca.

Cek akhir:

```bash
grep -n 'GANTI_' .env || echo "tidak ada placeholder tersisa"
grep -c 'GANTI_' .env.mysql   # harus: 0
stat -c '%a %U' .env .env.mysql   # keduanya: 600 deploy
```

Hasil yang diharapkan: pesan "tidak ada placeholder tersisa", `0`, dan `600 deploy` untuk kedua berkas. Saat go-live set `DEPLOY_GO_LIVE=true` agar deploy menolak berjalan tanpa tujuan cadangan.

Bila gagal: `deploy.sh` nanti akan berhenti dengan pesan variabel mana yang salah (preflight). Perbaiki lalu ulang.

---

## BAGIAN D. Deploy pertama

### Langkah 11. Jam, memori, dan deploy

Lakukan di **jam sepi** (migrasi dapat mengunci tabel sebentar, dan pada deploy pertama build memakan CPU/RAM).

Build image dilakukan di server (`--mode local`). Pada VM 2 GiB build bisa berat. Untuk deploy pertama tidak ada masalah karena belum ada layanan berjalan (MySQL baru dinyalakan setelah image selesai). Untuk pembaruan berikutnya saat aplikasi sudah berjalan, lihat bagian "Update rutin".

Deploy **wajib** berjalan lepas dari sesi SSH (putus koneksi saat migrasi meninggalkan skema setengah jadi). `deploy.sh` menolak berjalan langsung di terminal SSH kecuali di `tmux`/`screen`/`systemd-run` (atau dengan `--allow-attached`). SIGHUP selalu diabaikan dan Ctrl-C diabaikan selama migrasi.

```bash
sudo apt-get install -y tmux      # akun ubuntu, sekali
# sebagai deploy:
tmux new -s deploy                # sambung lagi bila SSH putus: tmux attach -t deploy
cd /opt/sigap/app
bash scripts/deploy.sh --ref feat/r01-deploy-vps
# nanti: bash scripts/deploy.sh --ref develop     atau tag rilis: bash scripts/deploy.sh --ref v1.0.0
```

Skrip dipanggil lewat `bash` agar tidak bergantung pada bit eksekusi berkas.

Yang dilakukan skrip, berurutan: kunci operasi -> wajib lepas terminal -> preflight (`.env`/`.env.mysql` mode 600, `APP_ENV`, `APP_DEBUG`, `TELEGRAM_DEV_BYPASS`, `LOG_LEVEL` bukan `debug`, kunci API bukan placeholder, panjang rahasia, disk bebas >= 5 GB, `docker compose config`; peringatan bila saringan CIDR webhook mati atau cadangan luar server kosong) -> checkout ref (bentuk `v*` diutamakan sebagai tag) -> build/pull image (rilis lama tetap jalan bila gagal di sini; label revisi image diperiksa) -> MySQL sehat **tanpa mengganti kontainer mysql** -> dump pra-migrasi (otomatis, wajib bila ada migrasi) -> mode maintenance -> `php artisan migrate --force` -> `up -d --no-deps --wait app queue scheduler nginx` (entrypoint membuat cache konfigurasi) -> `artisan up` -> health check -> catat rilis -> bersihkan image lama.

Bila definisi layanan `mysql` berubah (mis. `MYSQL_VERSION` atau konfigurasi), deploy berhenti sebelum apa pun berubah. Ulangi di jam sepi dengan `--allow-db-change`: dump diambil dari MySQL yang **sedang berjalan**, aplikasi masuk maintenance, baru kontainer mysql dibuat ulang.

Tentang migrasi `2026_10_27_000102`: menambah kolom persetujuan pada tabel `pengeluarans` dan **mengisi ulang semua baris pengeluaran lama menjadi `disetujui`** (agar serapan anggaran lama tidak berubah). Pada DB baru tidak ada baris lama, jadi aman. Pada DB berisi data, DDL MySQL tidak transaksional: bila gagal di tengah, migrasi tidak bisa langsung diulang (lihat Troubleshooting "migrasi gagal"). Dump pra-migrasi dibuat otomatis sebelum migrasi dan lokasinya disimpan di `.deploy/last-predeploy`.

Hasil yang diharapkan: baris terakhir `[ok] Deploy <tag> selesai.`, dan `dcp ps` menampilkan `mysql`, `app`, `nginx`, `queue`, `scheduler` berstatus `running`/`healthy`. Pada deploy pertama health check memakai HTTP lokal karena TLS belum aktif; itu normal.

Bila gagal:
- `Preflight` menolak -> baca pesan; contoh: `.env` dapat dibaca grup -> `chmod 600 .env`.
- `Pohon git memiliki perubahan lokal` -> `git status`, buang perubahan pada berkas terlacak.
- Build kehabisan memori (proses `Killed`) -> cek `free -h`; naikkan tipe VM sementara (bagian Hemat kredit), deploy ulang.
- `Operasi lain ... sedang berjalan` -> tunggu; atau tidak ada proses lain dan kunci tersangkut -> pastikan tidak ada `deploy.sh`/`backup.sh` berjalan (`pgrep -fa scripts/`).
- Setelah gagal pasca-migrasi, skrip membiarkan aplikasi dalam maintenance dan mencetak instruksi pemulihan. Jangan dipaksa `artisan up`; ikuti instruksi.

### Langkah 12. Sertifikat HTTPS (staging dulu, lalu produksi)

Syarat: DNS sudah menunjuk ke IP (langkah 5) dan port 80 terbuka di Security Group.

```bash
cd /opt/sigap/app
scripts/bootstrap-cert.sh --staging
```

Skrip menyalakan nginx (HTTP :80), meminta sertifikat dari CA staging Let's Encrypt lewat kontainer `certbot` sesaat (metode webroot, volume `certbot_www`/`certbot_etc`), mengaktifkan konfigurasi TLS nginx, lalu menguji `https://<DOMAIN>/up` secara lokal dengan `--insecure` (sertifikat staging tidak dipercaya browser).

Hasil yang diharapkan: `[ok] HTTPS aktif: https://<DOMAIN>`.

Bila lolos, terbitkan sertifikat produksi:

```bash
scripts/bootstrap-cert.sh --force
```

Hasil yang diharapkan: `[ok] HTTPS aktif: ...` tanpa `--insecure`. Verifikasi dari laptop: `curl -I https://<DOMAIN>/up` menampilkan `HTTP/2 200`.

Bila `--force` dari lineage staging menolak atau tetap menghasilkan sertifikat staging: hapus lineage lalu ulangi (perilaku ini belum diuji):

```bash
dcp --profile tools run --rm certbot delete --cert-name <DOMAIN>
scripts/bootstrap-cert.sh
```

Bila gagal (umum): tantangan ACME gagal -> DNS belum benar atau port 80 tertutup; rate limit Let's Encrypt (5 kegagalan validasi per jam) -> tunggu satu jam, gunakan `--staging` untuk gladi. Lihat Troubleshooting "sertifikat gagal".

### Langkah 13. Daftarkan webhook Telegram

Webhook membutuhkan HTTPS **valid** (sertifikat produksi, bukan staging).

```bash
dcp exec app php artisan sigap:telegram-set-webhook
dcp exec app php artisan sigap:telegram-set-webhook --info
```

Perintah pertama mendaftarkan `APP_URL/api/v1/telegram/webhook` dengan `secret_token`, `allowed_updates`, daftar perintah bot (`setMyCommands`), dan tombol menu bertipe commands. Opsi `--no-menu` melewati menu. Alternatif saat deploy: `bash scripts/deploy.sh --ref <REF> --set-webhook`.

Hasil yang diharapkan: `Webhook terdaftar: https://<DOMAIN>/api/v1/telegram/webhook`. Dari `--info`: `URL: https://<DOMAIN>/...`, `Pending: 0`, `Galat terakhir: -`.

Bila gagal: `TELEGRAM_BOT_TOKEN belum diisi` atau `TELEGRAM_WEBHOOK_SECRET wajib diisi` -> perbaiki `.env`, lalu muat ulang kontainer (lihat Pemeliharaan, rotasi secret). `URL webhook harus HTTPS` -> `APP_URL` salah. Telegram menolak sertifikat -> masih sertifikat staging. Detail: Troubleshooting "webhook 403".

### Langkah 14. Admin pertama

Pemilik akun Telegram yang akan menjadi Admin harus membuka bot Anda dan menekan Start lebih dulu. ID numerik Telegram dapat dilihat lewat bot pembaca ID (mis. @userinfobot).

```bash
dcp exec app php artisan sigap:buat-admin 7000000001 "Nama Admin"
```

Hasil yang diharapkan: `Admin dibuat.` Saat Mini App dibuka pertama kali, Admin wajib mengatur username dan password; token 6 digit dikirim oleh bot.

Bila gagal: `telegram_id sudah terdaftar sebagai akun lain` -> ubah lewat Admin di Mini App.

### Langkah 15. Uji dasar

```bash
curl -fsS -o /dev/null -w "%{http_code}\n" https://<DOMAIN>/up                 # 200
curl -s -o /dev/null -w "%{http_code} %{redirect_url}\n" http://<DOMAIN>/      # 301 https://<DOMAIN>/
curl -sI https://<DOMAIN>/miniapp/ | head -20                                  # 200, ada Content-Security-Policy
nc -vz -w 3 <IP> 3306                                                           # dari laptop: harus gagal/timeout
```

Lalu di ponsel: buka bot -> `/start` -> tekan tombol Mini App -> halaman Mini App terbuka -> masuk (aktivasi akun Admin). Kirim `/menu`.

Hasil yang diharapkan: semua sesuai komentar di atas; Mini App terbuka tanpa peringatan sertifikat.

Bila gagal: lihat Troubleshooting. Uji menyeluruh ada di `docs/ops/uji-staging.md` (wajib sebelum go-live).

### Langkah 16. Pasang timer systemd (backup, sertifikat, uji pemulihan)

Dijalankan dengan akun `ubuntu` (punya `sudo`). Unit sudah mengasumsikan `User=deploy` dan jalur `/opt/sigap/app`.

```bash
sudo cp /opt/sigap/app/scripts/systemd/sigap-*.service /opt/sigap/app/scripts/systemd/sigap-*.timer /etc/systemd/system/
sudo systemctl daemon-reload
sudo systemctl enable --now sigap-imds-block.service    # kontainer bridge tidak dapat menjangkau IMDS
sudo systemctl enable --now sigap-cert-renew.timer       # 03:17 dan 15:17 WIB (acak hingga 30 menit)
sudo systemctl enable --now sigap-restore-test.timer     # Minggu 04:00 WIB
# Aktifkan timer backup HANYA setelah Langkah 17 selesai (BACKUP_AGE_RECIPIENT terisi):
sudo systemctl enable --now sigap-backup.timer           # harian 02:30 WIB
systemctl list-timers 'sigap-*'
```

`scripts/backup.sh` (mode harian) berhenti dengan galat bila `BACKUP_AGE_RECIPIENT` kosong, jadi jangan aktifkan timer backup lebih dulu. Semua timer memakai `Persistent=true`: tugas yang terlewat saat VM mati dijalankan setelah VM menyala. Unit layanan menunggu kunci operasi hingga 30 menit (`SIGAP_LOCK_WAIT=1800`), menunggu MySQL sehat, dan mencoba ulang saat gagal (`Restart=on-failure`, jeda 10 menit, maks 3 kali per 3 jam).

Uji manual:

```bash
sudo systemctl start sigap-cert-renew.service
journalctl -u sigap-cert-renew -n 30 --no-pager
```

Hasil yang diharapkan: `list-timers` menampilkan jadwal berikutnya; log `Pembaruan sertifikat diperiksa; nginx di-reload.`

Bila gagal: `Failed to start` -> `journalctl -u <unit> -n 50`; umumnya `deploy` belum berada di grup `docker` atau `/opt/sigap/app/.env` tidak ada.

---

## BAGIAN E. Tujuan backup

### Langkah 17. Pilih tujuan, buat kunci age, hubungkan

Server membuat dump MySQL (`mysqldump --single-transaction`), memampatkan, **mengenkripsi dengan kunci publik age**, lalu mengunggah dengan rclone. Server tidak pernah memegang kunci privat, sehingga server yang diretas tidak dapat membaca arsip lama. Berkas `storage/app` (foto/nota) ikut diarsip bila `BACKUP_INCLUDE_FILES=true`. `.env` dan `APP_KEY` **tidak** ikut cadangan; simpan sendiri di pengelola kata sandi.

#### 17a. Opsi tujuan (biaya = perkiraan dari rencana, WAJIB diverifikasi di halaman harga penyedia)

| Opsi | Perkiraan biaya/bulan (data < 5 GB) | Catatan |
|---|---|---|
| S3 Standard ap-southeast-1 | sekitar US$0,13 (5 GB) | Satu akun dengan VM: bila akun ditutup saat kredit habis, cadangan ikut hilang. Akses dengan instance profile (tanpa kunci di disk). |
| S3 Standard-IA | sekitar US$0,07 | Minimum penyimpanan 30 hari dan 128 KB per objek; kurang cocok untuk dump harian. |
| S3 Glacier Instant | sekitar US$0,03 | Minimum 90 hari; hanya untuk arsip bulanan. |
| Backblaze B2 | gratis 10 GB, lalu sekitar US$0,006/GB | Di luar akun AWS (risiko berbeda), kompatibel S3. Perlu akun dan application key. |
| Cloudflare R2 | gratis 10 GB, lalu sekitar US$0,015/GB | Tanpa biaya egress, kompatibel S3. Perlu akun dan token API. |
| rclone ke Google Drive | gratis 15 GB | **Tidak disarankan**: token OAuth terikat akun pribadi dan bisa kedaluwarsa; data berisi data pribadi ditaruh di akun pribadi (risiko UU PDP). |
| Snapshot EBS (DLM) | sekitar US$0,5-2 | Memulihkan seluruh VM, bukan logis; satu akun. Pelengkap mingguan, bukan pengganti dump. |

Rekomendasi rencana: S3 ap-southeast-1 sebagai salinan utama, ditambah satu salinan di luar AWS (B2 atau R2) bila penutupan akun dianggap risiko. Keputusan akhir ada pada pemilik.

#### 17b. Buat kunci age (di LAPTOP, bukan di server)

```bash
age-keygen -o kunci-sigap-privat.txt
```

Perintah mencetak baris `Public key: age1...`. Tindakan:
1. Salin **kunci publik** `age1...` ke `.env` di server: `BACKUP_AGE_RECIPIENT=age1...`.
2. Simpan `kunci-sigap-privat.txt` **secara privat dan luring**: pengelola kata sandi + satu salinan di media luring (flashdisk/cetak) di tempat terpisah. Jangan unggah ke server, repo, atau chat.
3. Kehilangan kunci privat berarti semua cadangan tidak dapat dipulihkan.

Hasil yang diharapkan: `.env` di server memuat `BACKUP_AGE_RECIPIENT=age1...`; berkas privat tidak ada di server.

#### 17c. S3 (opsi utama)

Di konsol AWS:
1. **Bucket**: S3 -> Create bucket -> wilayah ap-southeast-1 -> nama unik (mis. `sigap-cadangan-<acak>`) -> **Block all public access: aktif** -> **Bucket Versioning: Enable** -> **Object Lock: Enable** (hanya bisa saat membuat bucket; mode *Governance*, retensi bawaan mis. 14 hari) -> Default encryption SSE-S3 (bawaan). Versioning + Object Lock mencegah cadangan lama ditimpa atau dihapus walau kredensial tulis server bocor.
2. **Lifecycle rule**: hapus versi saat ini setelah sekitar 35 hari dan versi lama (noncurrent) setelah 7 hari. Skrip tidak membedakan harian/mingguan/bulanan; semua objek masuk `db/YYYY/MM` dan `files/YYYY/MM`, sehingga retensi diatur satu aturan umur ini. Server tidak diberi hak hapus (`BACKUP_REMOTE_PRUNE=false`).
3. **Kebijakan IAM hak minimal** (IAM -> Policies -> Create policy -> JSON), ganti `NAMA-BUCKET`:

```json
{
  "Version": "2012-10-17",
  "Statement": [
    { "Effect": "Allow", "Action": ["s3:PutObject"], "Resource": "arn:aws:s3:::NAMA-BUCKET/sigap/*" },
    { "Effect": "Allow", "Action": ["s3:ListBucket"], "Resource": "arn:aws:s3:::NAMA-BUCKET",
      "Condition": { "StringLike": { "s3:prefix": ["sigap", "sigap/*"] } } }
  ]
}
```

   Tanpa `GetObject` dan `DeleteObject`: peretas server tidak dapat membaca atau menghapus cadangan.

   **Autentisitas**: age hanya memberi kerahasiaan, bukan autentisitas. Siapa pun yang tahu kunci publik (ada di server) dapat membuat arsip `.age` yang valid, dan `.sha256` ditulis oleh pihak yang sama. Peretas dengan hak `PutObject` bisa mengunggah dump palsu dengan nama baru. Mitigasi: Versioning + Object Lock (objek lama tidak dapat diganti), pilih objek berdasarkan waktu unggah/versi di konsol S3 (bukan hanya nama), dan `restore.sh` selalu memverifikasi isi di sandbox lalu memuat ke produksi dengan pengguna MySQL berhak sempit. Penguatan berikutnya (belum diterapkan): tanda tangan terpisah (mis. `minisign`/HMAC dengan kunci yang tidak ada di server penulis).
4. **Role untuk EC2** (instance profile): IAM -> Roles -> Create role -> AWS service -> EC2 -> lampirkan kebijakan di atas -> beri nama `sigap-backup-role`. Lalu EC2 -> instance -> Actions -> Security -> **Modify IAM role** -> pilih role tersebut.
5. **Pemulihan memakai kredensial lain**: untuk membaca cadangan, buat pengguna IAM terpisah dengan hak baca (`GetObject`, `ListBucket`) yang kuncinya hanya ada di laptop Anda atau dipakai sementara. Jangan menempelkan kunci itu permanen di server.

Konfigurasi rclone di server (`/etc/sigap/rclone.conf`, tanpa rahasia karena memakai instance profile):

```ini
[s3sigap]
type = s3
provider = AWS
env_auth = true
region = ap-southeast-1
no_check_bucket = true
```

Isi `.env`:

```
BACKUP_REMOTE=s3sigap:NAMA-BUCKET/sigap
BACKUP_RCLONE_CONFIG=/etc/sigap/rclone.conf
BACKUP_AGE_RECIPIENT=age1...
```

Uji (sebagai `deploy`):

```bash
cd /opt/sigap/app
scripts/backup.sh
ls -l /var/backups/sigap/enc
```

Hasil yang diharapkan: `[ok] Terunggah: db/<tahun>/<bulan>/sigap-db-<waktu>.sql.gz.age` (juga `files/...`), lalu `[ok] Cadangan selesai.` Di bucket muncul berkas `.age` dan `.sha256`. Setelah itu aktifkan `sudo systemctl enable --now sigap-backup.timer`.

Bila gagal: `AccessDenied` pada HEAD/`GetObject` -> `backup.sh` sudah memanggil rclone dengan `--no-check-dest --s3-no-head --s3-no-check-bucket` (variabel `BACKUP_RCLONE_FLAGS`) agar cukup `PutObject`; bila tetap ditolak, catat pesan persisnya (uji BK-01 di `uji-staging.md`). Jangan menambah `GetObject` begitu saja karena melemahkan perlindungan baca; bahas dulu (alternatif: B2/R2 dengan application key hanya-tulis). `Konfigurasi rclone tidak terbaca` -> `/etc/sigap/rclone.conf` belum ada. `BACKUP_AGE_RECIPIENT bukan kunci publik age` -> salah salin.

#### 17d. Backblaze B2 / Cloudflare R2 (salinan kedua, bila dipilih)

Buat bucket privat, buat kunci aplikasi/token **hanya-tulis** pada bucket itu, lalu definisikan remote kedua di rclone.conf (kunci masuk ke berkas ini, bukan `.env`; `chmod 600`, pemilik `deploy`). Skrip mengunggah ke **satu** `BACKUP_REMOTE`. Untuk dua tujuan perlu penyesuaian (mis. rclone `union`/`combine`, atau jadwal kedua); itu belum disediakan di skrip. Tanyakan sebelum mengandalkan salinan kedua.

---

## BAGIAN F. Operasi rutin

### Update rutin (deploy rilis baru)

```bash
tmux new -s deploy                        # atau: tmux attach -t deploy
cd /opt/sigap/app
bash scripts/deploy.sh --ref develop           # atau --ref v1.1.0
bash scripts/deploy.sh --ref v1.1.0 --set-webhook   # bila URL/secret/menu bot berubah
bash scripts/deploy.sh --ref v1.2.0 --allow-db-change   # hanya bila versi/konfigurasi MySQL berubah (jam sepi)
```

Sebelum deploy: jam sepi; pastikan tidak ada backup/restore berjalan; cek ruang disk `df -h /`. Build lokal di VM 2 GiB saat stack berjalan berisiko memori. Pilihan aman: naikkan tipe VM sementara (mis. t3.medium) -> deploy -> turunkan; atau jalankan saat sepi dan pantau `free -h`. Setelah deploy lokal, bersihkan cache build (skrip hanya membersihkan image, bukan cache BuildKit):

```bash
docker builder prune -af --filter until=24h
```

Hasil yang diharapkan: `Deploy <tag> selesai`, `/up` 200. Riwayat: `cat .deploy/history`.

Bila gagal: sebelum migrasi, skrip memulihkan rilis lama otomatis. Setelah migrasi, ikuti pesan pemulihan (bagian Rollback).

### Rollback

```bash
cd /opt/sigap/app
bash scripts/deploy.sh --rollback
```

Mengembalikan kode dan image ke rilis di `.deploy/previous` (image lama masih ada lokal; hanya rilis saat ini dan sebelumnya yang disimpan). Memasuki maintenance, `up -d`, `artisan up`, health check, lalu menukar catatan rilis (menjalankan rollback dua kali kembali ke rilis semula).

**Skema database TIDAK diturunkan otomatis** (melindungi data). Kebijakan: migrasi dibuat kompatibel-mundur (tambah kolom/tabel dulu, hapus belakangan). Bila rilis lama tidak cocok dengan skema baru, pulihkan DB dari dump pra-deploy (lihat berikut), dan itu berarti kehilangan data yang masuk setelah dump. Itu keputusan manusia.

Urutan bila DB harus dipulihkan dan kode dikembalikan (belum diuji, latih di staging):
1. `cat .deploy/last-predeploy` -> jalur dump pra-migrasi.
2. `bash scripts/restore.sh <jalur-dump>.sql.gz --target-db <DB_DATABASE> --confirm-overwrite` (lihat Pemulihan).
3. `bash scripts/deploy.sh --rollback` (menyalakan kembali queue/scheduler dan mengakhiri maintenance).

Hasil yang diharapkan: `Rollback selesai ke <tag>`.

Bila gagal: `Image <tag> tidak ada lokal` -> jalankan `deploy.sh --ref <sha-atau-tag-lama>` (build ulang).

### Pemulihan dari cadangan

Dua tingkat:

Semua verifikasi memuat dump ke **sandbox MySQL sesaat**: kontainer `mysql` terpisah tanpa jaringan (`--network none`), memori dibatasi `RESTORE_SANDBOX_MEM` (bawaan 512m), datadir terhapus bersama kontainer. Isi dump yang belum tepercaya tidak pernah dijalankan pada instans MySQL produksi sebagai root. Sebelum dimuat, isi dump dipindai: pernyataan seperti `USE`, `CREATE/DROP DATABASE`, `GRANT`, `CREATE USER`, `LOAD DATA`, `SELECT ... INTO OUTFILE`, perintah klien (`\!`, `system`, `source`), termasuk di dalam komentar bersyarat `/*!...*/`, ditolak; dump tanpa penanda `-- Dump completed` ditolak. Jenis sumber yang diterima hanya `*.sql.gz.age`, `*.sql.age`, `*.sql.gz`, `*.sql`.

**1. Uji tanpa risiko (sandbox, produksi hanya dibaca):**

```bash
bash scripts/restore-test.sh                 # dump produksi -> sandbox, bandingkan jumlah tabel/baris, lalu hapus sandbox
```

Hasil yang diharapkan: tabel perbandingan lalu `Uji pemulihan lolos.` Timer `sigap-restore-test.timer` menjalankannya mingguan (memori tambahan sekitar 0,5 GiB selama uji; jam sepi).

**2. Uji artefak cadangan terenkripsi (ujung-ke-ujung):** server tidak punya kunci privat, jadi operator memberikannya sementara.

```bash
# di laptop: salin kunci privat sementara ke memori server
scp -i kunci-sigap.pem kunci-sigap-privat.txt deploy@<IP>:/dev/shm/age.key
# di server:
chmod 600 /dev/shm/age.key
bash scripts/restore.sh s3sigap:NAMA-BUCKET/sigap/db/2026/10/sigap-db-<waktu>.sql.gz.age \
  --identity /dev/shm/age.key --rclone-config /etc/sigap/rclone-baca.conf --verify
shred -u /dev/shm/age.key
```

`/etc/sigap/rclone-baca.conf` adalah konfigurasi rclone dengan hak **baca** (server sengaja hanya punya hak tulis); buat sementara dari kredensial baca di laptop Anda, hapus sesudahnya. Alternatif: unduh dari laptop, verifikasi `.sha256`, lalu `age -d -i kunci-sigap-privat.txt berkas.sql.gz.age | gunzip > dump.sql` dan muat ke MySQL 8.4 lokal.

Hasil yang diharapkan: `Checksum SHA-256 cocok.`, `Isi dump lolos pemeriksaan.`, jumlah tabel dan baris tabel kunci (`migrations users sppgs pengeluarans pencairan_danas audit_logs lampirans`), `Verifikasi sandbox lolos.`, sandbox dihapus (`--keep` untuk membiarkannya).

**3. Menimpa DB produksi (bencana):**

```bash
tmux new -s restore
bash scripts/restore.sh <SUMBER> --identity /dev/shm/age.key --target-db <DB_DATABASE> --confirm-overwrite
```

Wajib dari terminal interaktif dan mengetik nama DB. Urutan skrip (produksi baru disentuh setelah sumber terbukti valid):
1. sumber disalin ke direktori kerja privat (tidak ikut rotasi dump); `.sha256` dicek bila ada;
2. dekripsi + dekompresi penuh + pindai isi; lalu muat ke sandbox dan verifikasi;
3. dump pengaman produksi ke `/var/backups/sigap/pre-restore/` (rotasi terpisah dari `pre-deploy`);
4. muat ke DB staging `<DB_DATABASE>_restore_test` di instans produksi memakai pengguna MySQL **sementara** `sigap_restore` yang hanya berhak pada DB staging (tanpa hak global; dihapus setelahnya), verifikasi lagi;
5. maintenance, hentikan `queue`/`scheduler`, lalu satu `RENAME TABLE` **atomik**: tabel produksi dipindah ke `<DB_DATABASE>_pre_<waktu UTC>` (salinan untuk pembatalan) dan tabel staging menjadi produksi.

Aplikasi **dibiarkan maintenance**. Lanjutkan sesuai petunjuk yang dicetak:
1. `dcp exec app php artisan migrate:status`
2. bila kode lebih baru dari dump: `dcp exec app php artisan migrate --force`
3. `dcp up -d queue scheduler && dcp exec app php artisan up`
4. setelah yakin: `DROP DATABASE <DB_DATABASE>_pre_<waktu>` (pembatalan: `RENAME TABLE` balik dari DB itu).

Bila gagal: `Berkas terenkripsi: --identity ... wajib` -> berikan kunci privat. `Checksum SHA-256 tidak cocok` -> berkas rusak/dimodifikasi; jangan dipakai, ambil objek lain. `Dump ditolak` -> dump terpotong atau memuat pernyataan terlarang; jangan dipakai. `Ada VIEW ...` -> pertukaran otomatis tidak mendukung VIEW (aplikasi saat ini tidak memakai VIEW). `RENAME TABLE gagal` -> pertukaran atomik tidak terjadi; produksi utuh, aplikasi masih maintenance.

File unggahan (`sigap-files-*.tar.gz.age`): dekripsi lalu ekstrak ke `storage/` pada volume `app_storage` (prosedur ini belum diuji; latih di staging).

### Hemat kredit

Kredit AWS terbatas dan paket gratis berakhir **2027-03-16**. Perkiraan dari rencana (belum diverifikasi; cek AWS Pricing Calculator): t3.small Singapura sekitar US$19/bulan 24 jam; EBS gp3 38 GB sekitar US$3; IPv4 publik/Elastic IP sekitar US$3,7. Total sekitar US$26/bulan 24x7; VM berhenti tetap sekitar US$7 (EBS + IP).

- **Billing alert**: konsol Billing -> Budgets -> Create budget (cost budget bulanan, mis. US$20) dengan peringatan 50%, 80%, 100% ke email Anda; aktifkan juga Free tier usage alerts di Billing preferences. Cek sisa kredit di Billing -> Credits secara berkala. Pasang sebelum go-live.
- **Stop VM saat tidak dipakai** (EC2 -> Stop, bukan Terminate). Konsekuensi:
  - Elastic IP tetap ditagih saat instance berhenti (IP statis tak terpasang pada instance berjalan berbayar); EBS tetap ditagih.
  - Webhook Telegram gagal selama mati; Telegram mencoba ulang lalu membuang pembaruan lama.
  - Tugas scheduler terlewat dan tidak dikejar: cek stok 06:00, rekap harian 17:00 WIB, pengecekan keterlambatan. Rekap bisa disusun manual: `dcp exec app php artisan sigap:rekap-harian --tanggal=YYYY-MM-DD`.
  - Backup 02:30 terlewat; timer `Persistent=true` mengejarnya saat VM menyala (backup/sertifikat/uji pemulihan).
  - Sertifikat kedaluwarsa jika mati lama (masa berlaku sekitar 90 hari; pembaruan dimulai < 30 hari tersisa). Setelah menyala, jalankan `sudo systemctl start sigap-cert-renew.service`.
  - Semua kontainer menyala sendiri (`restart: unless-stopped`, Docker `enable`). Pastikan setelah start: `dcp ps` dan `curl https://<DOMAIN>/up`.
  - Jangan mematikan VM saat masa uji pengguna; hanya saat idle panjang.
  - Bila tanpa Elastic IP, IP (dan nama sslip.io) berubah setiap start: sertifikat, `.env`, dan webhook harus diulang.
- **Uji beban sementara**: stop VM -> ubah tipe (EC2 -> Actions -> Instance settings -> Change instance type, mis. t3.large) -> start -> uji dari luar (k6/hey dari laptop) -> stop -> kembalikan ke t3.small. Elastic IP tetap. Naikkan `MEM_*`/`MEMSWAP_*` dan `PHP_FPM_MAX_CHILDREN` di `.env` hanya selama uji, lalu kembalikan dan `dcp up -d --force-recreate app queue scheduler mysql nginx`. Batas laju nginx (per IP) dan throttle Laravel akan menahan uji dari satu IP; nilai batas nginx tertanam di templat (tidak ada variabel `.env`).
- **Jangan build berat di instance kecil terus-menerus**: t3 dalam mode unlimited menagih surplus CPU credit.
- **Bersih-bersih**:

```bash
docker stats --no-stream           # pemakaian memori per kontainer vs batas
free -h; df -h /; docker system df
docker image prune -f              # image tak dipakai (deploy.sh sudah menyisakan rilis ini + sebelumnya)
docker builder prune -af --filter until=24h
```

- **Sebelum 2027-03-16**: putuskan lanjut berbayar, pindah penyedia, atau berhenti. Bila berhenti, ekspor cadangan terakhir dan simpan kunci age sebelum akun/instans dihapus.

### Pemeliharaan

**Log**

```bash
dcp logs --tail=100 -f app              # php-fpm
dcp logs --tail=100 nginx               # akses (tanpa query string) dan galat
dcp logs --tail=100 queue scheduler
dcp exec app sh -c 'tail -n 100 storage/logs/laravel-$(date +%F).log'   # log Laravel harian (retensi 14 hari)
journalctl -u sigap-backup -n 50 --no-pager
```

Log Docker dirotasi (10 MB x 3 berkas per layanan).

**Mengubah `.env` (rotasi secret)**: `.env` dibaca saat kontainer dibuat dan konfigurasi di-cache saat mulai. Setelah mengubah, muat ulang:

```bash
dcp up -d --force-recreate app queue scheduler
```

- **`TELEGRAM_WEBHOOK_SECRET`**: bangkitkan baru (`openssl rand -hex 32`), ubah di `.env`, recreate seperti di atas, lalu `dcp exec app php artisan sigap:telegram-set-webhook`.
- **`TELEGRAM_BOT_TOKEN`**: @BotFather -> `/revoke` -> token baru -> `.env` -> recreate -> `sigap:telegram-set-webhook`.
- **`DB_PASSWORD` (`.env`) / `MYSQL_ROOT_PASSWORD` (`.env.mysql`)**: MySQL hanya memakai nilai ini saat volume `mysql_data` pertama diinisialisasi. Mengubah berkas saja membuat aplikasi/skrip tidak bisa masuk. Ubah juga di MySQL: `dcp exec mysql mysql -uroot -p` (ketik sandi root saat diminta, tidak tampil) lalu `ALTER USER 'sigap'@'%' IDENTIFIED BY '<password-baru>';` (untuk root: `ALTER USER 'root'@'localhost' ...`), baru ubah berkas dan `dcp up -d --force-recreate app queue scheduler` (root: recreate `mysql` di jam sepi). Lakukan setelah backup.
- **`APP_KEY`**: jangan diganti sembarangan; mengganti membatalkan sesi dan data terenkripsi. Bila perlu, pindahkan kunci lama ke `APP_PREVIOUS_KEYS`, isi `APP_KEY` baru, recreate. Semua token tombol bot menjadi tidak berlaku (aman).
- **`LLM_API_KEY` / `STT_API_KEY`**: ubah di `.env`, recreate, jalankan `dcp exec app php artisan sigap:llm-uji`.

**Disk dan image**: `docker system df`, `df -h /`. `deploy.sh` mempertahankan image rilis saat ini dan sebelumnya. Pantau ruang bebas (deploy menolak bila < 5 GB; ubah `DEPLOY_MIN_FREE_GB`).

**Pemantauan cepat harian**: `dcp ps`, `curl -fsS https://<DOMAIN>/up`, `dcp exec app php artisan sigap:telegram-set-webhook --info` (Pending harus ~0, galat kosong), `systemctl list-timers 'sigap-*'`.

### Keamanan operasional

- **SSH hanya kunci**. Buat `/etc/ssh/sshd_config.d/99-sigap.conf` (akun `ubuntu`, buka sesi SSH kedua sebelum menutup yang pertama):

```bash
printf 'PasswordAuthentication no\nPermitRootLogin no\nKbdInteractiveAuthentication no\n' | sudo tee /etc/ssh/sshd_config.d/99-sigap.conf
sudo sshd -t && sudo systemctl reload ssh
```

- **Security Group**: hanya 80/443 untuk umum; 22 hanya dari IP Anda/32. **Jangan buka 3306** (dan tidak ada alasan membukanya; backup berjalan di dalam kontainer).
- **Pembaruan otomatis**: `sudo apt-get install -y unattended-upgrades && sudo dpkg-reconfigure -plow unattended-upgrades`. Kernel baru perlu reboot manual terjadwal; kontainer menyala sendiri setelah reboot.
- **fail2ban (opsional)**: `sudo apt-get install -y fail2ban`; berguna bila SSH terbuka lebih luas dari satu IP.
- **Pengguna `deploy`** setara root (grup `docker`): hanya satu orang, kunci SSH-nya dilindungi.
- **IMDSv2 wajib**, hop limit 1, dan kontainer bridge diblokir dari IMDS (`sigap-imds-block.service`). `.env` dan `.env.mysql` mode 600. Tidak ada soket Docker yang dipasang ke kontainer mana pun.
- **Disk EBS terenkripsi** wajib (dump pengaman polos disimpan lokal maks 14 hari).
- **Bucket cadangan**: Versioning + Object Lock, tanpa akses publik; server hanya `PutObject`/`ListBucket`.
- Telegram: aplikasi hanya menerima webhook dari CIDR Telegram (`TELEGRAM_WEBHOOK_IP_FILTER=on`) ditambah header secret.
- Data pribadi: cadangan wajib terenkripsi, bucket privat. Jangan simpan NIK/data pribadi berlebih.

### Keputusan bisnis: `SIGAP_AMBANG_PERSETUJUAN_PENGELUARAN`

Nilai dalam **rupiah** di `.env`, diisi pemilik bisnis saat go-live.
- `0` (bawaan): **semua** pengeluaran non-menu yang dicatat langsung wajib disetujui investor.
- `> 0`: hanya pengeluaran dengan nominal >= ambang yang butuh persetujuan (mis. `1000000`). Kelemahan yang dicatat di `config/operasional.php`: ambang > 0 membuka celah pemecahan nominal menjadi beberapa pengeluaran kecil.
- Tidak dapat diubah lewat API; ubah di `.env` lalu `dcp up -d --force-recreate app queue scheduler`.
- Tidak ada arti hukum/keuangan otomatis; pemilik menetapkannya. Tanyakan ke pemilik bisnis dan catat keputusannya (tanggal, nilai, penetap).

---

## BAGIAN G. Hal yang belum teruji tanpa server

Dokumen dan skrip ditulis tanpa akses ke VM. Hal berikut **tidak terbukti** sampai diuji di staging (`docs/ops/uji-staging.md`):

- Penerbitan dan pembaruan sertifikat nyata, termasuk peralihan dari lineage staging ke produksi dengan `--force`.
- Kontrol Security Group, port terbuka, IMDSv2, instance profile S3, dan kebijakan hanya-tulis (kemungkinan HEAD/`GetObject` oleh rclone).
- Anggaran memori 2 GiB, OOM, swap, dan kebenaran `memswap_limit` pada kernel AWS (cgroup swap accounting).
- Durasi dan puncak memori build image di server; apakah build aman saat stack berjalan.
- Perilaku MySQL 8.4 di EBS: migrasi `2026_10_27_000102` pada data nyata, kunci baris, savepoint, durabilitas.
- age/rclone ujung-ke-ujung; logika penanda "Dump completed" di `backup.sh` pada bash server; timer systemd dan pengejaran setelah VM berhenti; jalur `restore.sh` ke produksi.
- Telegram sungguhan: webhook dari CIDR Telegram, Telegram Web membingkai Mini App (CSP `frame-ancestors`), tombol, ForceReply, deep link di Android/iOS/Desktop/Web.
- IP klien asli yang dilihat Laravel (throttle per IP), CPU credit t3, waktu pemulihan (RTO) nyata.
- Mekanisme `deploy.sh --rollback`, pemulihan otomatis, dan instruksi pemulihan pasca-migrasi.
- Sandbox MySQL sesaat (`--network none`, 512m) untuk `restore.sh`/`restore-test.sh`; dukungan `--commands=OFF` pada klien mysql 8.4 (dipakai hanya bila tersedia); pengguna sementara `sigap_restore`; `RENAME TABLE` atomik lintas DB; deteksi perubahan definisi mysql (`config --hash`).
- php-fpm `clear_env=yes` di produksi (aplikasi bergantung penuh pada cache config); `.env.mysql` sebagai env_file khusus mysql; aturan `DOCKER-USER` untuk IMDS; `oom_score_adj` mysql.
- Tidak ada alur CI yang membangun/mendorong image (belum ada registry); jalur resmi sementara adalah build lokal.

---

## BAGIAN H. Troubleshooting singkat

**Sertifikat gagal** (`bootstrap-cert.sh` / `renew-cert.sh`)
1. DNS: `nslookup <DOMAIN>` harus berisi IP server. 2. Dari luar: `curl -I http://<DOMAIN>/.well-known/acme-challenge/tes` harus menjawab dari nginx (404 normal), bukan timeout; bila timeout cek Security Group port 80. 3. `dcp logs nginx`. 4. Rate limit Let's Encrypt (5 kegagalan/jam): tunggu, gladi dengan `--staging`. 5. Lihat sertifikat: `dcp --profile tools run --rm certbot certificates`. 6. Setelah penerbitan, nginx tidak melayani 443 bila `fullchain.pem`/`privkey.pem` belum ada; jalankan ulang `scripts/bootstrap-cert.sh`.

**502 Bad Gateway / 504**
- 502: php-fpm belum siap atau crash. `dcp ps` (apakah `app` healthy), `dcp logs --tail=100 app`. Setelah deploy nginx menyelesaikan nama `app` per permintaan (10 detik), tunggu sebentar. Periksa OOM (berikut).
- 504: permintaan > 90 detik (`fastcgi_read_timeout`/`request_terminate_timeout`); biasanya panggilan Agen AI lambat atau pool penuh (`pm.max_children` = `PHP_FPM_MAX_CHILDREN`, bawaan 4). Cari "max_children" di `dcp logs app`.
- 429 dari nginx: batas laju per IP (`sigap_api`, `sigap_auth`); wajar pada uji beban dari satu IP.

**OOM / kehabisan memori**
```bash
dmesg -T | grep -i -E 'killed process|out of memory'
docker inspect -f '{{.Name}} OOMKilled={{.State.OOMKilled}} restarts={{.RestartCount}}' $(docker ps -aq)
free -h; docker stats --no-stream
```
Tindakan: hentikan sementara pekerjaan berat (build, `restore-test`); turunkan `PHP_FPM_MAX_CHILDREN` ke 3; naikkan `MEM_*` serta `MEMSWAP_*` (selalu `MEMSWAP_X >= MEM_X`) hanya bila VM punya RAM; naikkan tipe VM. Jangan menaikkan swap MySQL (sengaja tanpa swap).

**Webhook 403 / tidak ada pesan masuk**
- `dcp exec app php artisan sigap:telegram-set-webhook --info`: lihat `Galat terakhir` dan `Pending`.
- 403 dari nginx: pengirim bukan CIDR Telegram (`149.154.160.0/20`, `91.108.4.0/22`) saat `TELEGRAM_WEBHOOK_IP_FILTER=on`. Menguji dengan `curl` dari laptop memang 403; itu wajar.
- 401/403 dari Laravel: secret berbeda antara webhook terdaftar dan `.env`. Setelah mengubah secret: recreate kontainer lalu daftarkan ulang webhook.
- 502/503: aplikasi down atau maintenance (Telegram mencoba ulang); `dcp ps`.
- Sertifikat tidak valid / staging: Telegram menolak; terbitkan sertifikat produksi.
- Pesan tersimpan tetapi tidak diproses: pastikan `queue` berjalan; `sigap:proses-ulang-telegram` (tiap 5 menit) mengirim ulang update yang belum diproses.

**Migrasi gagal** (`deploy.sh` mencetak "Deploy berhenti SETELAH migrasi dimulai")
Aplikasi sengaja tetap maintenance. Dump pra-migrasi: `cat .deploy/last-predeploy`. Pilihan:
1. Penyebab sepele (mis. memori): perbaiki lalu `bash scripts/deploy.sh --ref <REF>` ulang (di tmux). Aman hanya bila tidak ada perubahan skema parsial.
2. Migrasi parsial (khusus `2026_10_27_000102`: DDL tidak transaksional): periksa apakah kolom `status_persetujuan`, `versi`, `pembelian_bahan_id` dan indeks `pengeluarans_sppg_status_tanggal_index` sudah ada, juga FK pada `pembelian_bahans`/`pengeluarans`; hapus yang sudah terbentuk secara manual, lalu ulangi migrasi. Bila hanya backfill yang terputus, jalankan kembali: pembaruan memakai kondisi `status_persetujuan='diajukan'` sehingga aman diulang.
3. Pulihkan dump: `bash scripts/restore.sh $(cat .deploy/last-predeploy) --target-db <DB_DATABASE> --confirm-overwrite`, lalu `bash scripts/deploy.sh --rollback` (data sejak dump hilang; dump dibuat sebelum maintenance sehingga ada jendela singkat).

**Lain-lain**
- `docker compose config` galat "required variable" -> variabel wajib (`DOMAIN`, `DB_DATABASE`, `DB_USERNAME`, `DB_PASSWORD`) kosong di `.env`; galat `env file .../.env.mysql not found` -> buat `.env.mysql` (langkah 10).
- nginx gagal mulai dengan `DOMAIN kosong dan SIGAP_NGINX_MODE bukan dev` -> `DOMAIN` belum diisi di `.env`.
- `Jalankan deploy di tmux/screen atau systemd-run` -> buka `tmux new -s deploy` lalu ulangi.
- `Definisi layanan mysql berubah` -> lihat Update rutin (`--allow-db-change`).
- MySQL tidak sehat saat start pertama -> beri waktu hingga 60 detik; `dcp logs mysql`. "Access denied" setelah mengubah `DB_PASSWORD` -> lihat rotasi secret.
- Login Mini App gagal ("initData tidak valid") -> `TELEGRAM_BOT_TOKEN` salah/berubah, atau jam server melenceng (`timedatectl`), atau `TELEGRAM_INIT_DATA_MAX_AGE` terlampaui.
- `.deploy/`, `*.age`, `*.sql`, `*.sql.gz` diabaikan git; tetap jangan `git add -A` di server.

---

## Lampiran. Ringkasan perintah

```bash
dcp ps                                   # status layanan
bash scripts/deploy.sh --ref <REF> [--set-webhook] [--skip-backup] [--mode local|registry] [--image-tag TAG] [--allow-db-change] [--allow-attached]
bash scripts/deploy.sh --rollback        # kembali ke rilis sebelumnya (skema DB tidak diturunkan)
bash scripts/bootstrap-cert.sh [--staging] [--email EMAIL] [--force]
bash scripts/renew-cert.sh               # dipanggil timer
bash scripts/backup.sh [--db-only] [--predeploy] [--tag pre-deploy|pre-restore]
bash scripts/restore.sh SUMBER [--identity BERKAS] [--rclone-config BERKAS] [--keep]          # verifikasi di sandbox
bash scripts/restore.sh SUMBER --target-db <DB_DATABASE> --confirm-overwrite [--identity BERKAS]  # menimpa produksi
bash scripts/restore-test.sh [--from SUMBER --identity BERKAS] [--keep]
dcp exec app php artisan sigap:telegram-set-webhook [--info|--delete|--drop-pending|--url=...|--no-menu]
dcp exec app php artisan sigap:buat-admin <telegram_id> "<nama>"
dcp exec app php artisan sigap:llm-uji [--vision=BERKAS|--stt=BERKAS|--ukur]
```
