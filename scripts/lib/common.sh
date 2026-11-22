# shellcheck shell=bash
# SiGAP SPPG - fungsi bersama skrip operasi (deploy.sh, backup.sh, restore.sh, restore-test.sh,
# bootstrap-cert.sh, renew-cert.sh). Di-source, tidak dijalankan langsung.
#
# Prinsip: tidak pernah mencetak nilai .env; .env dibaca baris demi baris (tidak di-source sehingga tidak ada
# eksekusi isi berkas); kredensial MySQL hanya di dalam kontainer mysql (defaults-extra-file sementara);
# isi dump yang belum tepercaya tidak pernah dijalankan sebagai root MySQL produksi.

SIGAP_REPO_DIR="${SIGAP_REPO_DIR:-$(cd "$(dirname "${BASH_SOURCE[0]}")/../.." && pwd)}"
SIGAP_COMPOSE_FILE="${SIGAP_COMPOSE_FILE:-$SIGAP_REPO_DIR/docker-compose.prod.yml}"
SIGAP_ENV_FILE="${SIGAP_ENV_FILE:-$SIGAP_REPO_DIR/.env}"
SIGAP_MYSQL_ENV_FILE="${SIGAP_MYSQL_ENV_FILE:-$SIGAP_REPO_DIR/.env.mysql}"
SIGAP_STATE_DIR="${SIGAP_STATE_DIR:-$SIGAP_REPO_DIR/.deploy}"
SIGAP_IMAGE_ENV="$SIGAP_STATE_DIR/image.env"

# ---------- log ----------
if [[ -t 1 && -z "${NO_COLOR:-}" ]]; then
  _c_info=$'\033[36m' _c_ok=$'\033[32m' _c_warn=$'\033[33m' _c_err=$'\033[31m' _c_off=$'\033[0m'
else
  _c_info='' _c_ok='' _c_warn='' _c_err='' _c_off=''
fi
_ts() { date '+%Y-%m-%d %H:%M:%S'; }
info() { printf '%s %s[..]%s %s\n' "$(_ts)" "$_c_info" "$_c_off" "$*"; }
ok() { printf '%s %s[ok]%s %s\n' "$(_ts)" "$_c_ok" "$_c_off" "$*"; }
warn() { printf '%s %s[!!]%s %s\n' "$(_ts)" "$_c_warn" "$_c_off" "$*" >&2; }
err() { printf '%s %s[xx]%s %s\n' "$(_ts)" "$_c_err" "$_c_off" "$*" >&2; }
die() {
  err "$*"
  exit 1
}

require_cmd() {
  local c
  for c in "$@"; do
    command -v "$c" >/dev/null 2>&1 || die "Perintah '$c' tidak ditemukan."
  done
}

# ---------- .env ----------
# env_get KUNCI [berkas]: nilai terakhir KUNCI=... (tanpa kutip pembungkus, tanpa CR). Kosong bila tidak ada.
env_get() {
  local key="$1" file="${2:-$SIGAP_ENV_FILE}" line val
  [[ -r "$file" ]] || return 0
  line="$(grep -E "^${key}=" "$file" | tail -n 1 || true)"
  val="${line#*=}"
  val="${val%$'\r'}"
  if [[ ${#val} -ge 2 ]]; then
    if [[ "${val:0:1}" == '"' && "${val: -1}" == '"' ]] || [[ "${val:0:1}" == "'" && "${val: -1}" == "'" ]]; then
      val="${val:1:${#val}-2}"
    fi
  fi
  printf '%s' "$val"
}

# cfg KUNCI [bawaan]: variabel lingkungan proses > .env > bawaan. Hanya untuk nilai NON-rahasia.
cfg() {
  local key="$1" def="${2:-}" val
  val="${!key:-}"
  [[ -n "$val" ]] || val="$(env_get "$key")"
  [[ -n "$val" ]] || val="$def"
  printf '%s' "$val"
}

is_true() {
  case "${1,,}" in
    1 | true | yes | on) return 0 ;;
    *) return 1 ;;
  esac
}

# ---------- Docker Compose ----------
dc() {
  local args=(-f "$SIGAP_COMPOSE_FILE" --project-directory "$SIGAP_REPO_DIR" --env-file "$SIGAP_ENV_FILE")
  [[ -f "$SIGAP_IMAGE_ENV" ]] && args+=(--env-file "$SIGAP_IMAGE_ENV")
  docker compose "${args[@]}" "$@"
}

service_running() {
  [[ -n "$(dc ps --status running -q "$1" 2>/dev/null)" ]]
}

# ---------- MySQL di kontainer produksi ----------
# Skrip shell yang dijalankan DI DALAM kontainer mysql. Kata sandi root ditulis ke berkas [client] oleh builtin
# printf (tidak pernah muncul di argumen proses), dikutip ganda dengan `\` dan `"` di-escape (sed membaca dari
# stdin). Direktori sementara mode 700 dihapus saat keluar. $1 = program, sisanya argumen.
read -r -d '' _SIGAP_SH_ROOT <<'SH' || true
umask 077
d=$(mktemp -d) || exit 1
trap 'rm -rf "$d"' EXIT
esc=$(printf '%s' "$MYSQL_ROOT_PASSWORD" | sed -e 's/\\/\\\\/g' -e 's/"/\\"/g')
printf '[client]\nuser=root\npassword="%s"\n' "$esc" > "$d/root.cnf"
prog="$1"
shift
"$prog" --defaults-extra-file="$d/root.cnf" "$@"
SH

# Memuat SQL dari stdin ke DB $1 memakai pengguna SEMENTARA `sigap_restore`@`localhost` (sandi acak, hanya
# GRANT ALL pada DB itu: tanpa hak global FILE/SUPER/CREATE USER), klien --commands=OFF bila didukung. Pengguna dihapus setelahnya.
read -r -d '' _SIGAP_SH_LOAD <<'SH' || true
umask 077
d=$(mktemp -d) || exit 1
trap 'rm -rf "$d"' EXIT
esc=$(printf '%s' "$MYSQL_ROOT_PASSWORD" | sed -e 's/\\/\\\\/g' -e 's/"/\\"/g')
printf '[client]\nuser=root\npassword="%s"\n' "$esc" > "$d/root.cnf"
db="$1"
pw=$(head -c 24 /dev/urandom | od -An -tx1 | tr -d ' \n')
printf 'DROP USER IF EXISTS `sigap_restore`@`localhost`;\nCREATE USER `sigap_restore`@`localhost` IDENTIFIED BY "%s";\nGRANT ALL ON `%s`.* TO `sigap_restore`@`localhost`;\n' "$pw" "$db" |
  mysql --defaults-extra-file="$d/root.cnf" || exit 1
printf '[client]\nuser=sigap_restore\npassword="%s"\n' "$pw" > "$d/r.cnf"
# --commands=OFF hanya ada di klien MySQL yang lebih baru; scan_dump sudah menolak \! dan system.
copt=""
mysql --help 2>/dev/null | grep -q -- '--commands' && copt="--commands=OFF"
rc=0
mysql --defaults-extra-file="$d/r.cnf" $copt --default-character-set=utf8mb4 "$db" || rc=$?
printf 'DROP USER IF EXISTS `sigap_restore`@`localhost`;\n' | mysql --defaults-extra-file="$d/root.cnf" || rc=1
exit $rc
SH

# mysql_exec PROGRAM [argumen...]: klien MySQL (mysql, mysqldump, mysqladmin) sebagai root@localhost di kontainer
# mysql. HANYA untuk perintah tepercaya (dump, DDL buatan skrip, kueri laporan). stdin/stdout diteruskan.
# Untuk memuat isi dump gunakan mysql_load_restricted atau sandbox (sbx_*).
mysql_exec() {
  dc exec -T mysql sh -c "$_SIGAP_SH_ROOT" sigap-mysql "$@"
}

# mysql_query DB SQL: keluaran tab-separated tanpa header (root, kueri tepercaya).
mysql_query() {
  local db="$1" sql="$2"
  mysql_exec mysql -N -B --default-character-set=utf8mb4 "$db" -e "$sql"
}

# mysql_load_restricted DB: SQL dari stdin -> DB di instans produksi dengan pengguna sementara berhak sempit.
mysql_load_restricted() {
  local db="$1"
  valid_db_name "$db" || die "Nama DB tidak valid."
  dc exec -T mysql sh -c "$_SIGAP_SH_LOAD" sigap-mysql "$db"
}

# wait_mysql_healthy [detik]: tunggu kontainer mysql berstatus healthy (bawaan 300 s).
wait_mysql_healthy() {
  local limit="${1:-300}" waited=0 id status
  while ((waited < limit)); do
    id="$(dc ps -q mysql 2>/dev/null || true)"
    if [[ -n "$id" ]]; then
      status="$(docker inspect -f '{{if .State.Health}}{{.State.Health.Status}}{{else}}{{.State.Status}}{{end}}' "$id" 2>/dev/null || true)"
      [[ "$status" == "healthy" ]] && return 0
    fi
    sleep 5
    waited=$((waited + 5))
  done
  return 1
}

# Opsi mysqldump standar (tanpa --databases: dump tidak memuat USE/CREATE DATABASE dan dapat dimuat ke DB lain).
SIGAP_DUMP_OPTS=(--single-transaction --quick --no-tablespaces --routines --triggers --events
  --hex-blob --set-gtid-purged=OFF --default-character-set=utf8mb4)

# Tabel kunci untuk verifikasi pemulihan (ada di database/migrations).
SIGAP_KEY_TABLES="${SIGAP_KEY_TABLES:-migrations users sppgs pengeluarans pencairan_danas audit_logs lampirans}"

valid_db_name() {
  [[ "$1" =~ ^[A-Za-z0-9_]{1,64}$ ]]
}

# scan_dump: baca SQL polos dari stdin. Kode 2 bila ada pernyataan yang tidak dihasilkan mysqldump satu-DB dan
# berbahaya saat dimuat (pindah/buat/hapus DB, kelola pengguna/hak, plugin, berkas server, replikasi, perintah
# klien), termasuk di dalam komentar bersyarat /*!NNNNN ... */. Kode 3 bila penanda akhir "-- Dump completed"
# tidak ada (dump terpotong). Pertahanan berlapis, bukan pengganti sandbox/pengguna berhak sempit.
read -r -d '' _SIGAP_AWK_SCAN <<'AWK' || true
{
  line = $0
  sub(/^[ \t]*(\/\*![0-9]*[ \t]*)?/, "", line)
  u = toupper(substr(line, 1, 80))
  if (u ~ /^(USE[ \t`;]|CREATE[ \t]+(DATABASE|SCHEMA|USER|ROLE|SERVER)|DROP[ \t]+(DATABASE|SCHEMA|USER|ROLE)|ALTER[ \t]+(USER|DATABASE|SCHEMA|INSTANCE)|RENAME[ \t]+USER|GRANT[ \t]|REVOKE[ \t]|SET[ \t]+(PASSWORD|ROLE|GLOBAL|PERSIST)|INSTALL[ \t]|UNINSTALL[ \t]|SHUTDOWN|RESTART|LOAD[ \t]+(DATA|XML)|CLONE[ \t]|CHANGE[ \t]+(MASTER|REPLICATION)|START[ \t]+(SLAVE|REPLICA)|FLUSH[ \t]|KILL[ \t]|SYSTEM[ \t]|SOURCE[ \t]|\\)/) {
    bad = NR ": " substr(line, 1, 40)
    exit 2
  }
  if (u ~ /^SELECT/ && toupper(line) ~ /INTO[ \t]+(OUT|DUMP)FILE/) { bad = NR ": SELECT INTO FILE"; exit 2 }
  if ($0 ~ /^-- Dump completed/) done = 1
}
END {
  if (bad != "") { print "Pernyataan terlarang di dump (baris " bad ")" > "/dev/stderr"; exit 2 }
  if (!done) { print "Penanda akhir \"-- Dump completed\" tidak ada (dump terpotong?)" > "/dev/stderr"; exit 3 }
}
AWK

scan_dump() {
  LC_ALL=C awk "$_SIGAP_AWK_SCAN"
}

# db_report DB [FUNGSI_KUERI]: cetak "tabel <n>" lalu "<tabel> <baris>" untuk tabel kunci ("-" bila tidak ada).
# FUNGSI_KUERI DB SQL: bawaan mysql_query (instans produksi), sbx_query (sandbox).
db_report() {
  local db="$1" q="${2:-mysql_query}" t n
  valid_db_name "$db" || die "Nama DB tidak valid."
  n="$("$q" information_schema "SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA='${db}'")"
  printf 'tabel %s\n' "$n"
  for t in $SIGAP_KEY_TABLES; do
    if [[ "$("$q" information_schema "SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA='${db}' AND TABLE_NAME='${t}'")" == "1" ]]; then
      printf '%s %s\n' "$t" "$("$q" "$db" "SELECT COUNT(*) FROM \`${t}\`")"
    else
      printf '%s -\n' "$t"
    fi
  done
}

# report_ok LAPORAN: sukses bila ada tabel dan tabel migrations berisi baris.
report_ok() {
  local tables migrations
  tables="$(awk '$1=="tabel"{print $2}' <<<"$1")"
  migrations="$(awk '$1=="migrations"{print $2}' <<<"$1")"
  [[ -n "$tables" && "$tables" != "0" && -n "$migrations" && "$migrations" != "-" && "$migrations" != "0" ]]
}

# ---------- sandbox MySQL sesaat (memuat dump yang belum tepercaya) ----------
# Kontainer mysql terpisah: --network none (tidak terjangkau selain `docker exec`), batas memori, datadir di
# volume anonim yang terhapus bersama kontainer, tanpa volume/rahasia produksi. Root tanpa sandi aman di sini
# karena satu-satunya jalan masuk adalah docker exec (setara root host).
SIGAP_SBX=""
SIGAP_SBX_DB="sigap_restore_test"

sbx_start() {
  local image mem name i
  image="mysql:$(cfg MYSQL_VERSION 8.4)"
  mem="$(cfg RESTORE_SANDBOX_MEM 512m)"
  name="sigap-restore-sbx-$(date +%s)-$$"
  info "Menjalankan sandbox MySQL sesaat ($name, $mem, tanpa jaringan) ..."
  docker run -d --rm --name "$name" --pull never --network none \
    --memory "$mem" --memory-swap "$mem" --pids-limit 256 \
    --security-opt no-new-privileges:true --label sigap.role=restore-sandbox \
    -e MYSQL_ALLOW_EMPTY_PASSWORD=yes \
    "$image" --performance-schema=OFF --innodb-buffer-pool-size=64M --innodb-redo-log-capacity=64M \
    --disable-log-bin --mysqlx=OFF --max-connections=10 --local-infile=OFF \
    --character-set-server=utf8mb4 --collation-server=utf8mb4_unicode_ci >/dev/null ||
    die "Sandbox MySQL gagal dijalankan (image $image tersedia lokal?)."
  SIGAP_SBX="$name"
  # Server sementara saat inisialisasi memakai skip-networking; TCP 127.0.0.1 baru menjawab saat server final siap.
  for i in $(seq 1 60); do
    if docker exec "$name" mysql -h127.0.0.1 -uroot -N -B -e 'SELECT 1' >/dev/null 2>&1; then
      docker exec "$name" mysql -h127.0.0.1 -uroot \
        -e "CREATE DATABASE \`${SIGAP_SBX_DB}\` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;"
      return 0
    fi
    sleep 3
  done
  die "Sandbox MySQL tidak siap dalam 180 detik."
}

# sbx_load: SQL dari stdin -> DB sandbox (--commands=OFF bila klien mendukung).
sbx_load() {
  local copt=()
  if docker exec "$SIGAP_SBX" sh -c 'mysql --help 2>/dev/null | grep -q -- --commands'; then copt=(--commands=OFF); fi
  docker exec -i "$SIGAP_SBX" mysql -h127.0.0.1 -uroot "${copt[@]}" --default-character-set=utf8mb4 "$SIGAP_SBX_DB"
}

sbx_query() {
  local db="$1" sql="$2"
  docker exec -i "$SIGAP_SBX" mysql -h127.0.0.1 -uroot -N -B --default-character-set=utf8mb4 "$db" -e "$sql"
}

# sbx_stop [keep]: hapus sandbox (kecuali keep=1). Aman dipanggil berkali-kali.
sbx_stop() {
  [[ -n "${SIGAP_SBX:-}" ]] || return 0
  if [[ "${1:-0}" == "1" ]]; then
    warn "Sandbox dibiarkan hidup: $SIGAP_SBX (hapus: docker rm -f $SIGAP_SBX)."
  else
    docker rm -f "$SIGAP_SBX" >/dev/null 2>&1 || true
  fi
  SIGAP_SBX=""
}

# ---------- kunci & direktori ----------
# take_lock: satu operasi (deploy/backup/restore/sertifikat) pada satu waktu. Skrip anak yang dipanggil oleh
# pemegang kunci mewarisi SIGAP_LOCK_HELD=1 dan tidak mengunci ulang. SIGAP_LOCK_WAIT=<detik> (dipakai timer
# systemd) menunggu kunci alih-alih langsung gagal.
take_lock() {
  [[ "${SIGAP_LOCK_HELD:-}" == "1" ]] && return 0
  mkdir -p "$SIGAP_STATE_DIR"
  exec 9>"$SIGAP_STATE_DIR/ops.lock"
  local wait="${SIGAP_LOCK_WAIT:-0}"
  if [[ "$wait" =~ ^[1-9][0-9]*$ ]]; then
    flock -w "$wait" 9 || die "Kunci operasi tidak didapat dalam $wait detik (deploy/backup/restore lain berjalan)."
  else
    flock -n 9 || die "Operasi lain (deploy/backup/restore) sedang berjalan. Coba lagi nanti."
  fi
  export SIGAP_LOCK_HELD=1
}

ensure_private_dir() {
  local d="$1"
  (umask 077 && mkdir -p "$d") || die "Tidak dapat membuat direktori $d (buat dulu: sudo install -d -m 700 -o \$USER $d)."
  chmod 700 "$d" 2>/dev/null || true
  [[ -w "$d" ]] || die "Direktori $d tidak dapat ditulis oleh $(id -un)."
}

# stat mode oktal berkas (Linux).
file_mode() {
  stat -c '%a' "$1"
}
