#!/usr/bin/env bash
# SiGAP SPPG - verifikasi dan pemulihan dump database dari cadangan.
#
# Pemakaian:
#   scripts/restore.sh SUMBER [--identity BERKAS] [--rclone-config BERKAS] [--keep]
#       VERIFIKASI (bawaan, sama dengan --verify): muat ke sandbox MySQL sesaat (kontainer terpisah, tanpa
#       jaringan), laporkan jumlah tabel/baris tabel kunci, lalu hapus sandbox. Tidak menyentuh produksi.
#   scripts/restore.sh SUMBER --target-db <DB_DATABASE> --confirm-overwrite [--identity BERKAS]
#       MENIMPA PRODUKSI. Wajib terminal interaktif + mengetik nama DB.
#
#   SUMBER              berkas lokal ATAU objek rclone "remote:jalur/berkas". Jenis yang diterima HANYA:
#                       *.sql.gz.age, *.sql.age, *.sql.gz, *.sql (arsip berkas *.tar.gz.age ditolak).
#   --identity BERKAS   kunci PRIVAT age untuk *.age. Diberikan operator sementara (mis. /dev/shm, chmod 600)
#                       dan dihapus setelahnya; kunci privat tidak pernah disimpan di server.
#   --rclone-config F   konfigurasi rclone dengan hak BACA (server sengaja hanya punya hak tulis);
#                       bawaan BACKUP_RCLONE_CONFIG.
#   --keep              biarkan sandbox hidup untuk inspeksi (hapus manual: docker rm -f <nama>).
#
# Urutan menimpa produksi (data produksi baru disentuh setelah sumber terbukti valid):
#   1. sumber disalin ke direktori kerja privat (tidak terhapus rotasi dump); cek .sha256 bila ada;
#   2. dekripsi/dekompresi penuh + pindai isi (scan_dump: tolak USE/CREATE DATABASE/GRANT/... dan dump terpotong);
#   3. muat ke sandbox, verifikasi (ada tabel, tabel migrations berisi);
#   4. dump pengaman produksi (backup.sh --predeploy --tag pre-restore, rotasi terpisah dari pre-deploy);
#   5. muat ke DB staging <DB>_restore_test di instans produksi dengan pengguna MySQL sementara yang hanya berhak
#      pada DB staging (isi dump tidak pernah dijalankan sebagai root), verifikasi lagi;
#   6. maintenance, hentikan queue/scheduler, tukar ATOMIK satu RENAME TABLE: tabel produksi -> <DB>_pre_<waktu>
#      (salinan untuk pembatalan), tabel staging -> produksi. Aplikasi DIBIARKAN maintenance.
# Catatan: age tidak memberi autentisitas (siapa pun yang tahu kunci publik dapat membuat arsip); integritas
# asal dijaga oleh bucket (versioning/Object Lock, hak tulis server terbatas) dan .sha256 - lihat runbook.
# Tidak mencetak rahasia.
set -Eeuo pipefail

# shellcheck source=lib/common.sh
source "$(dirname "${BASH_SOURCE[0]}")/lib/common.sh"

usage() {
  awk 'NR > 1 && /^set /{exit} NR > 1' "${BASH_SOURCE[0]}" | sed 's/^# \{0,1\}//'
}

TMP_DIR=""
KEEP=0
FILE=""
IDENTITY=""

on_exit() {
  local rc=$?
  sbx_stop "$KEEP"
  [[ -n "$TMP_DIR" && -d "$TMP_DIR" ]] && rm -rf -- "$TMP_DIR"
  exit "$rc"
}

# emit_sql: SQL polos dari FILE ke stdout.
emit_sql() {
  case "$FILE" in
    *.sql.gz.age) age -d -i "$IDENTITY" "$FILE" | gzip -dc ;;
    *.sql.age) age -d -i "$IDENTITY" "$FILE" ;;
    *.sql.gz) gzip -dc "$FILE" ;;
    *.sql) cat "$FILE" ;;
  esac
}

rclone_get() {
  local conf="$1" src="$2" dst="$3"
  docker run --rm --network host --user "$(id -u):$(id -g)" \
    --security-opt no-new-privileges:true --cap-drop ALL -e HOME=/tmp \
    -v "$conf:/config/rclone.conf:ro" -v "$TMP_DIR:/data" \
    "$(cfg BACKUP_RCLONE_IMAGE rclone/rclone:1.69)" --config /config/rclone.conf -q \
    copyto "$src" "/data/$dst"
}

main() {
  local src="" target="" overwrite=0 rclone_conf=""

  while [[ $# -gt 0 ]]; do
    case "$1" in
      --identity)
        IDENTITY="${2:-}"
        shift
        ;;
      --target-db)
        target="${2:-}"
        shift
        ;;
      --rclone-config)
        rclone_conf="${2:-}"
        shift
        ;;
      --verify) ;;
      --keep) KEEP=1 ;;
      --confirm-overwrite) overwrite=1 ;;
      -h | --help)
        usage
        return 0
        ;;
      -*) die "Argumen tidak dikenal: $1 (lihat --help)" ;;
      *)
        [[ -z "$src" ]] || die "Hanya satu SUMBER."
        src="$1"
        ;;
    esac
    shift
  done
  [[ -n "$src" ]] || {
    usage
    die "SUMBER wajib."
  }

  local base
  base="$(basename "$src")"
  case "$base" in
    *.sql.gz.age | *.sql.age | *.sql.gz | *.sql) ;;
    *) die "Jenis sumber ditolak: hanya *.sql.gz.age, *.sql.age, *.sql.gz, *.sql." ;;
  esac
  [[ "$base" =~ ^[A-Za-z0-9._-]+$ ]] || die "Nama berkas sumber mengandung karakter tidak diizinkan."

  require_cmd docker gzip flock awk
  [[ -f "$SIGAP_ENV_FILE" ]] || die ".env tidak ditemukan di $SIGAP_REPO_DIR."
  take_lock
  trap on_exit EXIT

  local prod_db is_prod=0
  prod_db="$(env_get DB_DATABASE)"
  valid_db_name "$prod_db" || die "DB_DATABASE kosong/tidak valid di .env."
  if [[ -n "$target" ]]; then
    [[ "$target" == "$prod_db" ]] || die "--target-db hanya untuk DB produksi ($prod_db); verifikasi selalu di sandbox."
    [[ "$overwrite" == "1" ]] || die "Menimpa DB produksi wajib --confirm-overwrite."
    [[ -t 0 ]] || die "Menimpa DB produksi wajib dari terminal interaktif."
    is_prod=1
  fi

  # --- 1. salin sumber ke direktori kerja privat ---
  local work_base
  work_base="$(cfg BACKUP_LOCAL_DIR /var/backups/sigap)"
  ensure_private_dir "$work_base"
  TMP_DIR="$(mktemp -d "$work_base/restore.XXXXXX")"
  chmod 700 "$TMP_DIR"
  FILE="$TMP_DIR/$base"
  if [[ -f "$src" ]]; then
    cp -- "$src" "$FILE"
    [[ -f "$src.sha256" ]] && cp -- "$src.sha256" "$FILE.sha256"
  else
    [[ "$src" == *:* ]] || die "SUMBER bukan berkas lokal maupun objek rclone (remote:jalur)."
    rclone_conf="${rclone_conf:-$(cfg BACKUP_RCLONE_CONFIG /etc/sigap/rclone.conf)}"
    [[ -r "$rclone_conf" ]] || die "Konfigurasi rclone tidak terbaca: $rclone_conf."
    info "Mengunduh $base ..."
    rclone_get "$rclone_conf" "$src" "$base"
    rclone_get "$rclone_conf" "$src.sha256" "$base.sha256" 2>/dev/null ||
      warn "Berkas .sha256 tidak ada di remote."
  fi
  chmod 600 "$FILE"

  if [[ -f "$FILE.sha256" ]]; then
    require_cmd sha256sum
    (cd "$TMP_DIR" && sha256sum -c --quiet "$base.sha256") || die "Checksum SHA-256 tidak cocok."
    ok "Checksum SHA-256 cocok."
  else
    warn "Tanpa .sha256: integritas hanya dicek dekripsi/gzip/pindai isi."
  fi

  if [[ "$FILE" == *.age ]]; then
    require_cmd age
    [[ -n "$IDENTITY" && -r "$IDENTITY" ]] || die "Berkas terenkripsi: --identity <kunci privat age> wajib."
  fi

  # --- 2. dekripsi + dekompresi penuh + pindai ---
  info "Memeriksa isi dump (dekripsi, gzip, pindai pernyataan, penanda akhir) ..."
  emit_sql | scan_dump || die "Dump ditolak (rusak, terpotong, atau memuat pernyataan terlarang)."
  ok "Isi dump lolos pemeriksaan."

  # --- 3. sandbox ---
  sbx_start
  info "Memuat ke sandbox ..."
  emit_sql | sbx_load || die "Gagal memuat dump ke sandbox."
  local report
  report="$(db_report "$SIGAP_SBX_DB" sbx_query)"
  printf '%s\n' "$report"
  report_ok "$report" || die "Verifikasi sandbox gagal: tidak ada tabel atau tabel migrations kosong."
  ok "Verifikasi sandbox lolos."

  if [[ "$is_prod" == "0" ]]; then
    ok "Verifikasi selesai (produksi tidak disentuh)."
    return 0
  fi
  sbx_stop "$KEEP"

  overwrite_production "$prod_db"
}

overwrite_production() {
  local prod="$1" stg old stamp answer
  stamp="$(date -u +%Y%m%d%H%M%S)"
  stg="${prod}_restore_test"
  old="${prod}_pre_${stamp}"
  ((${#stg} <= 64 && ${#old} <= 64)) || die "Nama DB produksi terlalu panjang untuk DB staging/cadangan."
  service_running mysql || die "Kontainer mysql tidak berjalan."

  warn "Anda akan MENIMPA database produksi '$prod' dengan $(basename "$FILE")."
  warn "Data yang ditulis sejak cadangan itu akan HILANG (tabel lama disimpan di '$old' untuk pembatalan)."
  read -r -p "Ketik nama database untuk melanjutkan: " answer
  [[ "$answer" == "$prod" ]] || die "Dibatalkan."

  # --- 4. dump pengaman ---
  info "Dump pengaman sebelum menimpa ..."
  bash "$SIGAP_REPO_DIR/scripts/backup.sh" --predeploy --tag pre-restore

  # --- 5. staging di instans produksi, pengguna berhak sempit ---
  info "Memuat ke DB staging '$stg' (pengguna MySQL sementara, hanya berhak pada '$stg') ..."
  mysql_exec mysql -e "DROP DATABASE IF EXISTS \`${stg}\`; CREATE DATABASE \`${stg}\` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;"
  emit_sql | mysql_load_restricted "$stg" || die "Gagal memuat ke DB staging (produksi belum disentuh)."
  local report
  report="$(db_report "$stg")"
  printf '%s\n' "$report"
  report_ok "$report" || die "Verifikasi DB staging gagal (produksi belum disentuh; DB '$stg' dibiarkan untuk diperiksa)."

  local views
  views="$(mysql_query information_schema "SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA IN ('${prod}','${stg}') AND TABLE_TYPE<>'BASE TABLE'")"
  [[ "$views" == "0" ]] || die "Ada VIEW di DB produksi/staging; pertukaran otomatis tidak didukung (tukar manual)."

  # --- 6. maintenance + tukar atomik ---
  if service_running app; then
    dc exec -T app php artisan down --retry=60 >/dev/null || warn "Gagal masuk maintenance."
  fi
  dc stop queue scheduler >/dev/null 2>&1 || true

  local t parts=()
  while IFS= read -r t; do
    [[ -n "$t" ]] || continue
    [[ "$t" =~ ^[A-Za-z0-9_]+$ ]] || die "Nama tabel tidak terduga di produksi: dibatalkan sebelum menukar."
    parts+=("\`${prod}\`.\`${t}\` TO \`${old}\`.\`${t}\`")
  done < <(mysql_query information_schema "SELECT TABLE_NAME FROM information_schema.TABLES WHERE TABLE_SCHEMA='${prod}'")
  while IFS= read -r t; do
    [[ -n "$t" ]] || continue
    [[ "$t" =~ ^[A-Za-z0-9_]+$ ]] || die "Nama tabel tidak terduga di staging: dibatalkan sebelum menukar."
    parts+=("\`${stg}\`.\`${t}\` TO \`${prod}\`.\`${t}\`")
  done < <(mysql_query information_schema "SELECT TABLE_NAME FROM information_schema.TABLES WHERE TABLE_SCHEMA='${stg}'")

  local sql
  sql="CREATE DATABASE \`${old}\` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci; RENAME TABLE $(
    IFS=,
    printf '%s' "${parts[*]}"
  );"
  info "Menukar tabel secara atomik ($((${#parts[@]})) RENAME) ..."
  mysql_exec mysql -e "$sql" || die "RENAME TABLE gagal (atomik: produksi tidak berubah). Aplikasi masih maintenance."
  mysql_exec mysql -e "DROP DATABASE IF EXISTS \`${stg}\`;" || warn "DB staging '$stg' tidak terhapus."

  ok "DB produksi '$prod' dipulihkan. Tabel lama disimpan di '$old'."
  warn "Aplikasi MASIH maintenance, queue/scheduler berhenti. Langkah berikut:"
  warn "  1) periksa: docker compose ... exec app php artisan migrate:status"
  warn "  2) bila kode lebih baru dari dump: docker compose ... exec app php artisan migrate --force"
  warn "  3) docker compose ... up -d queue scheduler && docker compose ... exec app php artisan up"
  warn "  4) setelah yakin: hapus '$old' (DROP DATABASE). Pembatalan: RENAME TABLE balik dari '$old'."
}

main "$@"
exit $?
