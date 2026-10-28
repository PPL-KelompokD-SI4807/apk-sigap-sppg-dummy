#!/usr/bin/env bash
# SiGAP SPPG - cadangan database (+ storage/app) terenkripsi age, diunggah lewat rclone.
#
# Pemakaian:
#   scripts/backup.sh                     cadangan harian: DB + storage/app (BACKUP_INCLUDE_FILES)
#   scripts/backup.sh --db-only           hanya DB
#   scripts/backup.sh --predeploy [--tag pre-deploy|pre-restore]
#                                         dump pengaman: dump POLOS terkompresi lokal (mode 600) di
#                                         BACKUP_LOCAL_DIR/<tag>/ (rotasi per tag: BACKUP_PREDEPLOY_KEEP terbaru,
#                                         maks BACKUP_PREDEPLOY_MAX_DAYS hari) untuk pemulihan cepat di server, lalu
#                                         salinan terenkripsi diunggah bila BACKUP_REMOTE dan BACKUP_AGE_RECIPIENT terisi.
#   scripts/backup.sh --tag NAMA          label tambahan pada nama berkas
#
# Alur harian: mysqldump (di kontainer mysql, kredensial lewat defaults-extra-file) | gzip | age -r <kunci publik>
#   -> berkas lokal terenkripsi (BACKUP_LOCAL_DIR/enc, mode 600) -> cek penanda "Dump completed" -> rclone copyto.
#   Berkas terenkripsi ditulis lokal dulu agar objek remote tidak pernah berisi dump terpotong.
# Kunci privat age TIDAK pernah ada di server. Gagal di langkah mana pun = keluar non-nol + log (journald via
# systemd) + ping /fail opsional. Tidak mencetak rahasia.
# Variabel (.env): BACKUP_REMOTE, BACKUP_RCLONE_CONFIG, BACKUP_RCLONE_IMAGE, BACKUP_AGE_RECIPIENT,
#   BACKUP_RCLONE_FLAGS, BACKUP_LOCAL_DIR, BACKUP_RETENTION_DAYS, BACKUP_PREDEPLOY_KEEP, BACKUP_PREDEPLOY_MAX_DAYS,
#   BACKUP_REMOTE_PRUNE, BACKUP_REMOTE_RETENTION_DAYS, BACKUP_INCLUDE_FILES, BACKUP_PING_URL, DB_DATABASE.
set -Eeuo pipefail

# shellcheck source=lib/common.sh
source "$(dirname "${BASH_SOURCE[0]}")/lib/common.sh"

usage() {
  awk 'NR > 1 && /^set /{exit} NR > 1' "${BASH_SOURCE[0]}" | sed 's/^# \{0,1\}//'
}

main() {
  local mode="daily" tag="" db_only=0

  while [[ $# -gt 0 ]]; do
    case "$1" in
      --predeploy) mode="predeploy" ;;
      --db-only) db_only=1 ;;
      --tag)
        tag="${2:-}"
        shift
        ;;
      -h | --help)
        usage
        return 0
        ;;
      *) die "Argumen tidak dikenal: $1 (lihat --help)" ;;
    esac
    shift
  done
  [[ -z "$tag" || "$tag" =~ ^[a-z0-9-]{1,32}$ ]] || die "--tag hanya huruf kecil, angka, tanda minus (maks 32)."

  require_cmd docker gzip sha256sum flock
  [[ -f "$SIGAP_ENV_FILE" ]] || die ".env tidak ditemukan di $SIGAP_REPO_DIR."
  take_lock

  DB_NAME="$(env_get DB_DATABASE)"
  valid_db_name "$DB_NAME" || die "DB_DATABASE kosong/tidak valid di .env."
  REMOTE="$(cfg BACKUP_REMOTE)"
  RCLONE_CONFIG_FILE="$(cfg BACKUP_RCLONE_CONFIG /etc/sigap/rclone.conf)"
  RCLONE_IMAGE="$(cfg BACKUP_RCLONE_IMAGE rclone/rclone:1.69)"
  RECIPIENT="$(cfg BACKUP_AGE_RECIPIENT)"
  LOCAL_DIR="$(cfg BACKUP_LOCAL_DIR /var/backups/sigap)"
  RETENTION_DAYS="$(cfg BACKUP_RETENTION_DAYS 7)"
  PREDEPLOY_KEEP="$(cfg BACKUP_PREDEPLOY_KEEP 3)"
  PREDEPLOY_MAX_DAYS="$(cfg BACKUP_PREDEPLOY_MAX_DAYS 14)"
  PING_URL="$(cfg BACKUP_PING_URL)"
  # Flag rclone untuk hak IAM hanya-tulis (PutObject/ListBucket): tanpa HEAD/GET objek tujuan, tanpa cek bucket.
  read -r -a RCLONE_FLAGS <<<"$(cfg BACKUP_RCLONE_FLAGS "--no-check-dest --s3-no-head --s3-no-check-bucket")"
  [[ "$RETENTION_DAYS" =~ ^[0-9]+$ && "$PREDEPLOY_KEEP" =~ ^[0-9]+$ && "$PREDEPLOY_MAX_DAYS" =~ ^[0-9]+$ ]] ||
    die "BACKUP_RETENTION_DAYS/BACKUP_PREDEPLOY_KEEP/BACKUP_PREDEPLOY_MAX_DAYS harus angka."

  WORK_PARTIAL=""
  TAIL_FILE=""
  trap on_exit EXIT

  # Timer dapat berjalan tepat setelah VM menyala: tunggu MySQL sehat (maks 5 menit).
  wait_mysql_healthy 300 || die "Kontainer mysql tidak sehat dalam 5 menit."

  if [[ -n "$RECIPIENT" ]]; then
    require_cmd age
    [[ "$RECIPIENT" =~ ^age1[0-9a-z]{20,}$ || "$RECIPIENT" =~ ^ssh-(ed25519|rsa)\  ]] ||
      die "BACKUP_AGE_RECIPIENT bukan kunci publik age (age1...)."
  fi

  STAMP="$(date -u +%Y%m%dT%H%M%SZ)"
  SUFFIX="${tag:+-$tag}"

  if [[ "$mode" == "predeploy" ]]; then
    backup_predeploy
  else
    [[ -n "$RECIPIENT" ]] || die "BACKUP_AGE_RECIPIENT kosong: cadangan harian wajib terenkripsi."
    backup_daily "$db_only"
  fi

  ping_monitor ""
  ok "Cadangan selesai."
}

# Satu titik keluar: hapus berkas setengah jadi; bila gagal (set -e/die) catat ke log + ping /fail.
on_exit() {
  local rc=$?
  [[ -n "${WORK_PARTIAL:-}" && -e "$WORK_PARTIAL" ]] && rm -f "$WORK_PARTIAL"
  [[ -n "${TAIL_FILE:-}" && -e "$TAIL_FILE" ]] && rm -f "$TAIL_FILE"
  if [[ $rc -ne 0 ]]; then
    err "Cadangan GAGAL (kode $rc)."
    if command -v logger >/dev/null 2>&1; then
      logger -t sigap-backup -p user.err "Cadangan SiGAP gagal (kode $rc)" || true
    fi
    ping_monitor "/fail" || true
  fi
  exit "$rc"
}

ping_monitor() {
  [[ -n "${PING_URL:-}" ]] || return 0
  curl -fsS -m 10 --retry 2 -o /dev/null "${PING_URL}$1" || warn "Ping pemantau gagal (diabaikan)."
}

# dump_db_to_gzip BERKAS_KELUARAN: mysqldump | gzip ke berkas, lalu pastikan dump lengkap.
dump_db_to_gzip() {
  local out="$1"
  TAIL_FILE="$(mktemp)"
  # Penanda akhir mysqldump ("-- Dump completed") diambil dari ujung aliran polos lewat tee.
  mysql_exec mysqldump "${SIGAP_DUMP_OPTS[@]}" "$DB_NAME" |
    tee >(tail -c 4096 >"$TAIL_FILE") |
    gzip -6 >"$out"
  wait_tail
  grep -q -- '-- Dump completed' "$TAIL_FILE" || die "Dump tidak lengkap (penanda akhir tidak ada)."
  rm -f "$TAIL_FILE"
  TAIL_FILE=""
}

# Tunggu proses substitusi tail selesai menulis (bash >= 5.1 menyetel $! untuk proses substitusi terakhir).
wait_tail() {
  local pid i
  set +u
  pid="$!"
  set -u
  [[ -n "$pid" ]] && { wait "$pid" 2>/dev/null || true; }
  for i in 1 2 3 4 5; do
    [[ -s "$TAIL_FILE" ]] && return 0
    sleep 1
  done
}

min_size_check() {
  local f="$1" min="$2" size
  size="$(stat -c '%s' "$f")"
  [[ "$size" -ge "$min" ]] || die "Berkas cadangan terlalu kecil ($size byte): $(basename "$f")."
}

upload() {
  local file="$1" dest="$2"
  if [[ -z "$REMOTE" ]]; then
    warn "BACKUP_REMOTE kosong: $(basename "$file") HANYA tersimpan lokal."
    return 0
  fi
  [[ -r "$RCLONE_CONFIG_FILE" ]] || die "Konfigurasi rclone tidak terbaca: $RCLONE_CONFIG_FILE."
  local dir base
  dir="$(dirname "$file")"
  base="$(basename "$file")"
  # Kontainer sesaat; --network host agar instance profile EC2 (IMDSv2, hop limit 1) dapat dijangkau.
  docker run --rm --network host --user "$(id -u):$(id -g)" \
    --security-opt no-new-privileges:true --cap-drop ALL -e HOME=/tmp \
    -v "$RCLONE_CONFIG_FILE:/config/rclone.conf:ro" -v "$dir:/data:ro" \
    "$RCLONE_IMAGE" --config /config/rclone.conf -q --retries 3 --low-level-retries 10 "${RCLONE_FLAGS[@]}" \
    copyto "/data/$base" "$REMOTE/$dest/$base"
  docker run --rm --network host --user "$(id -u):$(id -g)" \
    --security-opt no-new-privileges:true --cap-drop ALL -e HOME=/tmp \
    -v "$RCLONE_CONFIG_FILE:/config/rclone.conf:ro" -v "$dir:/data:ro" \
    "$RCLONE_IMAGE" --config /config/rclone.conf -q --retries 3 "${RCLONE_FLAGS[@]}" \
    copyto "/data/$base.sha256" "$REMOTE/$dest/$base.sha256"
  ok "Terunggah: $dest/$base"
}

prune_remote() {
  is_true "$(cfg BACKUP_REMOTE_PRUNE false)" || return 0
  [[ -n "$REMOTE" ]] || return 0
  local days
  days="$(cfg BACKUP_REMOTE_RETENTION_DAYS 30)"
  [[ "$days" =~ ^[0-9]+$ && "$days" -ge 7 ]] || die "BACKUP_REMOTE_RETENTION_DAYS harus angka >= 7."
  docker run --rm --network host --user "$(id -u):$(id -g)" \
    --security-opt no-new-privileges:true --cap-drop ALL -e HOME=/tmp \
    -v "$RCLONE_CONFIG_FILE:/config/rclone.conf:ro" \
    "$RCLONE_IMAGE" --config /config/rclone.conf -q delete --min-age "${days}d" "$REMOTE"
  info "Objek remote lebih tua dari $days hari dihapus."
}

# encrypt_file SUMBER TUJUAN: age -r ke TUJUAN (+ .sha256).
encrypt_file() {
  local src="$1" dst="$2"
  WORK_PARTIAL="$dst.partial"
  age -r "$RECIPIENT" -o "$WORK_PARTIAL" "$src"
  mv "$WORK_PARTIAL" "$dst"
  WORK_PARTIAL=""
  (cd "$(dirname "$dst")" && sha256sum "$(basename "$dst")" >"$(basename "$dst").sha256")
}

backup_daily() {
  local db_only="$1" enc name ym
  enc="$LOCAL_DIR/enc"
  ensure_private_dir "$LOCAL_DIR"
  ensure_private_dir "$enc"
  umask 077
  ym="$(date -u +%Y/%m)"

  name="sigap-db-$STAMP$SUFFIX.sql.gz.age"
  info "Dump database -> $name"
  WORK_PARTIAL="$enc/$name.partial"
  TAIL_FILE="$(mktemp)"
  mysql_exec mysqldump "${SIGAP_DUMP_OPTS[@]}" "$DB_NAME" |
    tee >(tail -c 4096 >"$TAIL_FILE") |
    gzip -6 |
    age -r "$RECIPIENT" -o "$WORK_PARTIAL"
  wait_tail
  grep -q -- '-- Dump completed' "$TAIL_FILE" || die "Dump tidak lengkap (penanda akhir tidak ada)."
  rm -f "$TAIL_FILE"
  TAIL_FILE=""
  min_size_check "$WORK_PARTIAL" 1024
  mv "$WORK_PARTIAL" "$enc/$name"
  WORK_PARTIAL=""
  (cd "$enc" && sha256sum "$name" >"$name.sha256")
  upload "$enc/$name" "db/$ym"

  if [[ "$db_only" == "0" ]] && is_true "$(cfg BACKUP_INCLUDE_FILES true)"; then
    service_running app || die "Kontainer app tidak berjalan (arsip storage/app)."
    name="sigap-files-$STAMP$SUFFIX.tar.gz.age"
    info "Arsip storage/app -> $name"
    WORK_PARTIAL="$enc/$name.partial"
    dc exec -T app tar -C /var/www/html/storage -cf - app |
      gzip -6 |
      age -r "$RECIPIENT" -o "$WORK_PARTIAL"
    min_size_check "$WORK_PARTIAL" 100
    mv "$WORK_PARTIAL" "$enc/$name"
    WORK_PARTIAL=""
    (cd "$enc" && sha256sum "$name" >"$name.sha256")
    upload "$enc/$name" "files/$ym"
  fi

  # Retensi lokal salinan terenkripsi.
  find "$enc" -maxdepth 1 -type f \( -name 'sigap-*.age' -o -name 'sigap-*.age.sha256' \) \
    -mtime "+$RETENTION_DAYS" -delete
  prune_remote
}

backup_predeploy() {
  local pre name file kind
  # Rotasi terpisah per jenis: pre-deploy (deploy.sh) dan pre-restore (restore.sh), agar dump pengaman restore
  # tidak menyingkirkan dump pra-deploy (dan sebaliknya).
  kind="${SUFFIX#-}"
  kind="${kind:-pre-deploy}"
  pre="$LOCAL_DIR/$kind"
  ensure_private_dir "$LOCAL_DIR"
  ensure_private_dir "$pre"
  umask 077

  name="sigap-db-$STAMP-$kind.sql.gz"
  file="$pre/$name"
  info "Dump $kind (lokal, polos terkompresi) -> $file"
  WORK_PARTIAL="$file.partial"
  dump_db_to_gzip "$WORK_PARTIAL"
  gzip -t "$WORK_PARTIAL"
  min_size_check "$WORK_PARTIAL" 200
  mv "$WORK_PARTIAL" "$file"
  WORK_PARTIAL=""
  chmod 600 "$file"
  mkdir -p "$SIGAP_STATE_DIR"
  if [[ "$kind" == "pre-deploy" ]]; then
    printf '%s\n' "$file" >"$SIGAP_STATE_DIR/last-predeploy"
  else
    printf '%s\n' "$file" >"$SIGAP_STATE_DIR/last-$kind"
  fi

  # Rotasi: simpan PREDEPLOY_KEEP terbaru dan hapus yang lebih tua dari PREDEPLOY_MAX_DAYS (data pribadi polos
  # tidak menumpuk di disk; disk EBS wajib terenkripsi - lihat runbook).
  local old
  # shellcheck disable=SC2012 # nama berkas terkendali (sigap-db-<UTC>...)
  ls -1t "$pre"/sigap-db-*.sql.gz 2>/dev/null | tail -n "+$((PREDEPLOY_KEEP + 1))" | while IFS= read -r old; do
    rm -f -- "$old"
  done
  find "$pre" -maxdepth 1 -type f -name 'sigap-db-*.sql.gz' -mtime "+$PREDEPLOY_MAX_DAYS" ! -path "$file" -delete

  if [[ -n "$RECIPIENT" && -n "$REMOTE" ]]; then
    local enc
    enc="$LOCAL_DIR/enc"
    ensure_private_dir "$enc"
    encrypt_file "$file" "$enc/$name.age"
    upload "$enc/$name.age" "db/$(date -u +%Y/%m)"
  else
    warn "BACKUP_REMOTE/BACKUP_AGE_RECIPIENT kosong: dump pra-deploy HANYA lokal."
  fi
}

main "$@"
exit $?
