#!/usr/bin/env bash
# SiGAP SPPG - deploy ke VPS (docker-compose.prod.yml). Jalankan sebagai pengguna `deploy` dari repo di server.
#
# Pemakaian:
#   scripts/deploy.sh [--ref REF] [--mode local|registry] [--image-tag TAG] [--set-webhook] [--skip-backup]
#                     [--allow-db-change] [--allow-attached]
#   scripts/deploy.sh --rollback [--allow-attached]
#
#   --ref REF        cabang (bawaan develop), tag (mis. v1.2.0), atau commit
#   --mode           local    = build image di server (cadangan; berat untuk 2 GiB, jalankan saat sepi)
#                    registry = tarik ${IMAGE_REGISTRY}/sigap-sppg-{app,web}:${TAG} (bawaan DEPLOY_IMAGE_MODE)
#   --image-tag TAG  label image (bawaan: tag git persis pada commit, selain itu sha 12 karakter)
#   --set-webhook    jalankan `php artisan sigap:telegram-set-webhook` setelah sehat (idempoten)
#   --skip-backup    lewati dump bila TIDAK ada migrasi tertunda (bila ada migrasi, dump selalu wajib)
#   --rollback       kembali ke rilis sebelumnya (.deploy/previous). SKEMA DB TIDAK DITURUNKAN.
#   --allow-db-change izinkan definisi layanan mysql berubah (versi image/konfigurasi). Dump diambil dari MySQL
#                    yang SEDANG berjalan sebelum kontainer mysql dibuat ulang. Tanpa opsi ini deploy berhenti.
#   --allow-attached izinkan berjalan terikat terminal SSH (bawaan: wajib tmux/screen/systemd-run, karena
#                    putus koneksi saat migrasi meninggalkan skema setengah jadi). SIGHUP selalu diabaikan.
#
# Urutan: kunci -> preflight (.env, nilai berbahaya, disk, compose, git bersih, DB siap) -> checkout ->
#   build/pull (gagal = rilis lama tetap jalan) -> dump pra-migrasi -> maintenance + hentikan queue/scheduler ->
#   migrate --force -> up (entrypoint membuat cache config/route/event/view) -> artisan up -> health /up ->
#   catat rilis -> bersihkan image lama (simpan rilis ini dan sebelumnya).
# Aman dijalankan ulang. Tidak mencetak nilai .env.
#
# MIGRASI 2026_10_27_000102 (persetujuan pengeluaran) TIDAK idempoten bila gagal di tengah (DDL MySQL tidak
# transaksional): jalankan deploy yang memuatnya di JAM SEPI. Dump pra-migrasi otomatis dibuat sebelumnya.
set -Eeuo pipefail

# shellcheck source=lib/common.sh
source "$(dirname "${BASH_SOURCE[0]}")/lib/common.sh"

# Seluruh logika di dalam main(): bash membaca fungsi utuh sebelum dijalankan, sehingga `git checkout`
# yang mengganti berkas skrip ini di tengah jalan tidak memengaruhi eksekusi.

usage() {
  awk 'NR > 1 && /^set /{exit} NR > 1' "${BASH_SOURCE[0]}" | sed 's/^# \{0,1\}//'
}

STAGE="init"
PREV_SHA=""
PREV_TAG=""
NEW_SHA=""
NEW_TAG=""
MIGRATED=0
DOMAIN=""
IMAGE_REGISTRY=""

# to_bytes 448m|1g|512k|1024 -> byte
to_bytes() {
  local v="${1,,}" n u
  [[ "$v" =~ ^([0-9]+)([kmg]?)b?$ ]] || return 1
  n="${BASH_REMATCH[1]}" u="${BASH_REMATCH[2]}"
  case "$u" in
    k) n=$((n * 1024)) ;;
    m) n=$((n * 1024 * 1024)) ;;
    g) n=$((n * 1024 * 1024 * 1024)) ;;
  esac
  printf '%s' "$n"
}

# ---------- preflight ----------
preflight() {
  require_cmd docker git curl flock gzip awk sha256sum stat df
  docker compose version >/dev/null 2>&1 || die "Docker Compose v2 (docker compose) tidak tersedia."

  [[ -f "$SIGAP_ENV_FILE" ]] || die ".env tidak ditemukan (salin docs/ops/env.production.example ke .env lalu isi)."
  local mode
  mode="$(file_mode "$SIGAP_ENV_FILE")"
  ((8#$mode & 8#077)) && die ".env dapat dibaca grup/lainnya (mode $mode). Jalankan: chmod 600 .env"

  local v key
  v="$(env_get APP_ENV)"
  [[ "$v" == "production" ]] || die "APP_ENV harus production."
  v="$(env_get APP_DEBUG)"
  [[ "${v,,}" == "false" ]] || die "APP_DEBUG harus false."
  v="$(env_get TELEGRAM_DEV_BYPASS)"
  is_true "$v" && die "TELEGRAM_DEV_BYPASS harus false."
  [[ "$(env_get LLM_PROVIDER)" != "fake" ]] || die "LLM_PROVIDER=fake hanya untuk tes."

  DOMAIN="$(env_get DOMAIN)"
  [[ "$DOMAIN" =~ ^[A-Za-z0-9]([A-Za-z0-9-]*[A-Za-z0-9])?(\.[A-Za-z0-9]([A-Za-z0-9-]*[A-Za-z0-9])?)+$ ]] ||
    die "DOMAIN kosong/tidak valid."
  v="$(env_get APP_URL)"
  [[ "$v" == https://* ]] || die "APP_URL harus https://."
  [[ "${v%/}" == "https://$DOMAIN" ]] || warn "APP_URL tidak sama dengan https://DOMAIN (periksa)."

  v="$(env_get APP_KEY)"
  [[ "$v" == base64:* && ${#v} -ge 40 ]] || die "APP_KEY kosong/tidak valid (php artisan key:generate --show)."

  for key in DB_DATABASE DB_USERNAME DB_PASSWORD TELEGRAM_BOT_TOKEN TELEGRAM_WEBHOOK_SECRET; do
    v="$(env_get "$key")"
    [[ -n "$v" ]] || die "$key wajib diisi di .env."
    [[ "$v" != GANTI_* ]] || die "$key masih placeholder."
  done
  v="$(env_get TELEGRAM_WEBHOOK_SECRET)"
  [[ "$v" =~ ^[A-Za-z0-9_-]{32,256}$ ]] || die "TELEGRAM_WEBHOOK_SECRET harus 32-256 karakter [A-Za-z0-9_-] (openssl rand -hex 32)."
  v="$(env_get DB_PASSWORD)"
  [[ ${#v} -ge 16 ]] || die "DB_PASSWORD terlalu pendek (min 16; openssl rand -hex 32)."
  v="$(env_get DB_QUEUE_RETRY_AFTER)"
  [[ -z "$v" || ("$v" =~ ^[0-9]+$ && "$v" -gt 80) ]] || die "DB_QUEUE_RETRY_AFTER harus > 80 (timeout worker)."

  # Sandi root MySQL HANYA di .env.mysql (env_file khusus kontainer mysql), tidak terlihat oleh app/queue/scheduler.
  [[ -f "$SIGAP_MYSQL_ENV_FILE" ]] || die ".env.mysql tidak ditemukan (lihat docs/ops/env.mysql.example)."
  mode="$(file_mode "$SIGAP_MYSQL_ENV_FILE")"
  ((8#$mode & 8#077)) && die ".env.mysql dapat dibaca grup/lainnya (mode $mode). Jalankan: chmod 600 .env.mysql"
  v="$(env_get MYSQL_ROOT_PASSWORD "$SIGAP_MYSQL_ENV_FILE")"
  [[ -n "$v" && "$v" != GANTI_* && ${#v} -ge 16 ]] || die "MYSQL_ROOT_PASSWORD di .env.mysql kosong/placeholder/terlalu pendek."
  [[ -z "$(env_get DB_ROOT_PASSWORD)" ]] ||
    warn "DB_ROOT_PASSWORD masih ada di .env (terlihat oleh kontainer aplikasi); pindahkan ke .env.mysql lalu hapus dari .env."

  # Kunci API eksternal tidak boleh placeholder bila fiturnya aktif.
  if [[ -n "$(env_get LLM_PROVIDER)" ]]; then
    v="$(env_get LLM_API_KEY)"
    [[ -n "$v" && "$v" != GANTI_* ]] || die "LLM_PROVIDER aktif tetapi LLM_API_KEY kosong/placeholder."
  fi
  if [[ -n "$(env_get STT_DRIVER)" ]]; then
    v="$(env_get STT_API_KEY)"
    [[ -n "$v" && "$v" != GANTI_* ]] || die "STT_DRIVER aktif tetapi STT_API_KEY kosong/placeholder."
  fi
  [[ "$(env_get LOG_LEVEL)" != "debug" ]] || die "LOG_LEVEL=debug dilarang di produksi (data pribadi masuk log)."
  [[ "$(cfg TELEGRAM_WEBHOOK_IP_FILTER on)" == "on" ]] ||
    warn "TELEGRAM_WEBHOOK_IP_FILTER=off: webhook hanya dilindungi secret token."
  if [[ -z "$(cfg BACKUP_REMOTE)" || -z "$(cfg BACKUP_AGE_RECIPIENT)" ]]; then
    if is_true "$(cfg DEPLOY_GO_LIVE false)"; then
      die "DEPLOY_GO_LIVE=true tetapi BACKUP_REMOTE/BACKUP_AGE_RECIPIENT kosong (cadangan luar server wajib)."
    fi
    warn "BACKUP_REMOTE/BACKUP_AGE_RECIPIENT kosong: cadangan hanya lokal (tidak boleh untuk go-live)."
  fi

  IMAGE_REGISTRY="$(cfg IMAGE_REGISTRY localhost)"

  # memswap_limit harus >= mem_limit (galat Docker baru muncul saat kontainer dibuat, yaitu setelah migrasi).
  local svc mem swap
  for svc in APP:448m:704m QUEUE:192m:448m SCHEDULER:160m:416m NGINX:48m:304m; do
    IFS=: read -r key mem swap <<<"$svc"
    mem="$(to_bytes "$(cfg "MEM_$key" "$mem")")" || die "MEM_$key tidak valid."
    swap="$(to_bytes "$(cfg "MEMSWAP_$key" "$swap")")" || die "MEMSWAP_$key tidak valid."
    ((swap >= mem)) || die "MEMSWAP_$key harus >= MEM_$key."
  done

  local min_gb free_kb path
  min_gb="$(cfg DEPLOY_MIN_FREE_GB 5)"
  for path in "$SIGAP_REPO_DIR" "$(docker info -f '{{.DockerRootDir}}' 2>/dev/null || echo /var/lib/docker)"; do
    [[ -d "$path" ]] || continue
    free_kb="$(df -Pk "$path" | awk 'NR==2{print $4}')"
    ((free_kb >= min_gb * 1024 * 1024)) || die "Ruang disk bebas di $path < ${min_gb} GB."
  done

  dc config -q || die "docker-compose.prod.yml tidak valid untuk .env ini."

  [[ -z "$(git -C "$SIGAP_REPO_DIR" status --porcelain --untracked-files=no)" ]] ||
    die "Pohon git memiliki perubahan lokal pada berkas terlacak; bersihkan dulu."
  ok "Preflight lolos."
}

# Deploy harus lepas dari sesi SSH: putusnya koneksi saat migrasi meninggalkan skema setengah jadi.
require_detached() {
  [[ "${ALLOW_ATTACHED:-0}" == "1" ]] && {
    warn "--allow-attached: deploy terikat terminal; jangan tutup sesi sampai selesai."
    return 0
  }
  [[ -n "${TMUX:-}${STY:-}${INVOCATION_ID:-}" ]] && return 0
  [[ -t 0 || -t 1 ]] || return 0
  die "Jalankan deploy di tmux/screen atau systemd-run (lihat runbook), atau tambahkan --allow-attached."
}

# MySQL: JANGAN biarkan `up` mengganti kontainer mysql (versi/konfigurasi baru) sebelum dump diambil.
# DB_CHANGE=1 bila definisi layanan mysql di compose baru berbeda dari kontainer yang berjalan.
DB_CHANGE=0
mysql_check_change() {
  local id want have
  id="$(dc ps -q mysql 2>/dev/null || true)"
  if [[ -z "$id" ]]; then
    DB_CHANGE=0
    return 0
  fi
  want="$(dc config --hash mysql 2>/dev/null | awk '{print $2}')"
  have="$(docker inspect -f '{{index .Config.Labels "com.docker.compose.config-hash"}}' "$id" 2>/dev/null || true)"
  if [[ -n "$want" && "$want" != "$have" ]]; then DB_CHANGE=1; else DB_CHANGE=0; fi
}

db_ready() {
  info "Memastikan MySQL sehat (tanpa mengganti kontainer yang berjalan) ..."
  dc up -d --no-recreate --wait --wait-timeout 180 mysql || die "MySQL tidak sehat (DB tidak siap)."
}

db_upgrade() {
  warn "Menerapkan perubahan definisi MySQL (kontainer mysql dibuat ulang) ..."
  dc up -d --wait --wait-timeout 300 mysql || die "MySQL tidak sehat setelah perubahan definisi."
}

# pending_migrations: 0 = tidak ada, 1 = ada (termasuk DB baru tanpa tabel migrations). Galat lain = berhenti.
pending_migrations() {
  local out rc=0
  out="$(dc run --rm --no-deps --pull never -e SIGAP_WARMUP=0 app php artisan migrate:status --pending=3 2>&1)" || rc=$?
  case "$rc" in
    0) return 1 ;;
    3)
      printf '%s\n' "$out" | grep -i 'pending' | sed 's/^/    /' || true
      return 0
      ;;
    *)
      if grep -qi 'migration table not found' <<<"$out"; then
        info "Tabel migrations belum ada (DB baru)."
        return 0
      fi
      printf '%s\n' "$out" | tail -n 5 >&2
      die "Status migrasi tidak dapat dibaca (DB tidak siap?)."
      ;;
  esac
}

health_check() {
  local i url tls=0
  if dc exec -T nginx test -f /etc/nginx/conf.d/20-sigap-443.conf 2>/dev/null; then tls=1; fi
  for i in $(seq 1 20); do
    if [[ "$tls" == "1" ]]; then
      url="https://$DOMAIN/up"
      curl -fsS -o /dev/null --max-time 5 --resolve "$DOMAIN:443:127.0.0.1" "$url" && return 0
    else
      url="http://$DOMAIN/up (lokal, TLS belum aktif)"
      curl -fsS -o /dev/null --max-time 5 -H "Host: $DOMAIN" "http://127.0.0.1/up" && return 0
    fi
    sleep 3
  done
  err "Health check gagal: $url"
  return 1
}

image_exists() {
  docker image inspect "$IMAGE_REGISTRY/sigap-sppg-app:$1" >/dev/null 2>&1 &&
    docker image inspect "$IMAGE_REGISTRY/sigap-sppg-web:$1" >/dev/null 2>&1
}

get_images() {
  local mode="$1" rev
  export IMAGE_TAG="$NEW_TAG"
  # Label org.opencontainers.image.revision pada build lokal (Dockerfile ARG VCS_REF).
  export IMAGE_REVISION="$NEW_SHA"
  if [[ "$mode" == "registry" ]]; then
    info "Menarik image $IMAGE_REGISTRY/sigap-sppg-{app,web}:$NEW_TAG ..."
    dc pull app nginx
  else
    warn "Build lokal di server (CPU/memori tinggi beberapa menit; lakukan saat sepi)."
    dc build --pull app
    dc build nginx
  fi
  image_exists "$NEW_TAG" || die "Image $NEW_TAG tidak tersedia setelah build/pull."
  rev="$(docker image inspect -f '{{index .Config.Labels "org.opencontainers.image.revision"}}' \
    "$IMAGE_REGISTRY/sigap-sppg-app:$NEW_TAG" 2>/dev/null || true)"
  if [[ -n "$rev" && "$rev" != "<no value>" && "$rev" != "unknown" ]]; then
    [[ "$rev" == "$NEW_SHA" ]] || die "Label revisi image ($rev) berbeda dari commit $NEW_SHA; image ditolak."
  else
    warn "Image tanpa label org.opencontainers.image.revision: asal image tidak terverifikasi."
  fi
  ok "Image $NEW_TAG siap."
}

write_state() {
  mkdir -p "$SIGAP_STATE_DIR"
  printf 'IMAGE_TAG=%s\n' "$1" >"$SIGAP_IMAGE_ENV"
  if [[ -n "$2" && -n "$3" ]]; then
    printf '%s %s\n' "$2" "$3" >"$SIGAP_STATE_DIR/previous"
  fi
}

log_history() {
  mkdir -p "$SIGAP_STATE_DIR"
  printf '%s\t%s\t%s\t%s\n' "$(date -u +%Y-%m-%dT%H:%M:%SZ)" "$1" "$2" "$3" >>"$SIGAP_STATE_DIR/history"
}

maintenance_on() {
  [[ "$STAGE" == "rollback" ]] || STAGE="maintenance"
  if service_running app; then
    if dc exec -T app php artisan down --retry=60 >/dev/null; then
      info "Mode maintenance aktif."
    else
      warn "Gagal mengaktifkan maintenance (lanjut)."
    fi
    dc exec -T app php artisan queue:restart >/dev/null 2>&1 || true
    dc exec -T app php artisan schedule:interrupt >/dev/null 2>&1 || true
  fi
  info "Menghentikan queue dan scheduler (menunggu job berjalan selesai) ..."
  dc stop queue scheduler >/dev/null 2>&1 || true
}

maintenance_off() {
  dc exec -T app php artisan up >/dev/null || return 1
  info "Mode maintenance berakhir."
}

# Kembalikan rilis sebelumnya (kode + image) tanpa menyentuh skema DB.
revert_to_previous() {
  [[ -n "$PREV_TAG" ]] || {
    warn "Tidak ada rilis sebelumnya untuk dikembalikan."
    return 1
  }
  warn "Mengembalikan rilis sebelumnya ($PREV_TAG) ..."
  git -C "$SIGAP_REPO_DIR" checkout --quiet --detach "$PREV_SHA" || true
  export IMAGE_TAG="$PREV_TAG"
  dc up -d --no-deps --remove-orphans --pull never --wait --wait-timeout 240 app queue scheduler nginx &&
    maintenance_off && health_check
}

print_recovery() {
  local dump
  dump="$(cat "$SIGAP_STATE_DIR/last-predeploy" 2>/dev/null || echo '-')"
  err "Deploy berhenti SETELAH migrasi dimulai. Aplikasi tetap MAINTENANCE (data dilindungi)."
  err "Dump pra-migrasi: $dump"
  err "Pilihan: (a) perbaiki lalu jalankan ulang scripts/deploy.sh --ref <REF>;"
  err "  (b) migrasi parsial (mis. 2026_10_27_000102): periksa kolom/indeks/FK yang sempat terbentuk, hapus manual, ulangi;"
  err "  (c) pulihkan dump: scripts/restore.sh <dump> --target-db <DB_DATABASE> --confirm-overwrite (data sejak dump hilang)."
}

# Satu titik keluar: kegagalan (set -e maupun die) ditangani menurut tahap terakhir yang tercapai.
on_exit() {
  local rc=$?
  trap - EXIT
  [[ $rc -eq 0 ]] && return 0
  case "$STAGE" in
    checked_out | images)
      warn "Gagal sebelum maintenance: kode dikembalikan ke $PREV_SHA; rilis lama tetap berjalan."
      git -C "$SIGAP_REPO_DIR" checkout --quiet --detach "$PREV_SHA" || true
      ;;
    maintenance)
      warn "Gagal sebelum migrasi: memulihkan layanan rilis lama."
      git -C "$SIGAP_REPO_DIR" checkout --quiet --detach "$PREV_SHA" || true
      if [[ -n "$PREV_TAG" ]]; then
        IMAGE_TAG="$PREV_TAG" dc up -d --no-deps --pull never queue scheduler || true
        maintenance_off || true
      fi
      ;;
    migrating | migrated)
      print_recovery
      ;;
    rollback)
      err "Rollback gagal. Periksa: docker compose ... ps / logs; aplikasi mungkin masih maintenance."
      ;;
  esac
  if [[ -n "$NEW_SHA" && "$STAGE" != "handled" ]]; then
    log_history "$NEW_SHA" "${NEW_TAG:--}" "gagal:$STAGE" || true
  fi
  exit "$rc"
}

do_rollback() {
  [[ -f "$SIGAP_STATE_DIR/previous" ]] || die "Tidak ada .deploy/previous (belum ada rilis sebelumnya)."
  local cur_tag cur_sha
  read -r PREV_SHA PREV_TAG <"$SIGAP_STATE_DIR/previous"
  [[ "$PREV_SHA" =~ ^[0-9a-f]{40}$ && -n "$PREV_TAG" ]] || die ".deploy/previous rusak."
  cur_sha="$(git -C "$SIGAP_REPO_DIR" rev-parse HEAD)"
  cur_tag="$(env_get IMAGE_TAG "$SIGAP_IMAGE_ENV")"

  if ! image_exists "$PREV_TAG"; then
    [[ "$(cfg DEPLOY_IMAGE_MODE local)" == "registry" ]] || die "Image $PREV_TAG tidak ada lokal; gunakan deploy --ref."
    IMAGE_TAG="$PREV_TAG" dc pull app nginx
  fi
  warn "Rollback ke $PREV_TAG ($PREV_SHA). Skema DB TIDAK diturunkan (kebijakan expand/contract)."
  NEW_SHA="$PREV_SHA" NEW_TAG="$PREV_TAG"
  STAGE="rollback"
  maintenance_on
  git -C "$SIGAP_REPO_DIR" checkout --quiet --detach "$PREV_SHA"
  export IMAGE_TAG="$PREV_TAG"
  dc up -d --no-deps --remove-orphans --pull never --wait --wait-timeout 240 app queue scheduler nginx
  maintenance_off
  health_check
  write_state "$PREV_TAG" "$cur_sha" "$cur_tag"
  log_history "$PREV_SHA" "$PREV_TAG" "rollback"
  ok "Rollback selesai ke $PREV_TAG."
}

prune_images() {
  local repo ref tag
  for repo in app web; do
    while IFS= read -r ref; do
      [[ -n "$ref" ]] || continue
      tag="${ref##*:}"
      [[ "$tag" == "$NEW_TAG" || "$tag" == "$PREV_TAG" || "$tag" == "<none>" ]] && continue
      docker image rm "$ref" >/dev/null 2>&1 || true
    done < <(docker image ls --format '{{.Repository}}:{{.Tag}}' "$IMAGE_REGISTRY/sigap-sppg-$repo")
  done
  docker image prune -f >/dev/null 2>&1 || true
}

main() {
  local ref="develop" mode="" image_tag="" set_webhook=0 skip_backup=0 rollback=0 allow_db_change=0

  while [[ $# -gt 0 ]]; do
    case "$1" in
      --ref)
        ref="${2:-}"
        shift
        ;;
      --mode)
        mode="${2:-}"
        shift
        ;;
      --image-tag)
        image_tag="${2:-}"
        shift
        ;;
      --set-webhook) set_webhook=1 ;;
      --skip-backup) skip_backup=1 ;;
      --rollback) rollback=1 ;;
      --allow-db-change) allow_db_change=1 ;;
      --allow-attached) ALLOW_ATTACHED=1 ;;
      -h | --help)
        usage
        return 0
        ;;
      *) die "Argumen tidak dikenal: $1 (lihat --help)" ;;
    esac
    shift
  done
  [[ "$ref" =~ ^[A-Za-z0-9._/-]{1,100}$ && "$ref" != -* ]] || die "--ref tidak valid."
  mode="${mode:-$(cfg DEPLOY_IMAGE_MODE local)}"
  [[ "$mode" == "local" || "$mode" == "registry" ]] || die "--mode harus local atau registry."
  [[ -z "$image_tag" || "$image_tag" =~ ^[A-Za-z0-9_][A-Za-z0-9_.-]{0,127}$ ]] || die "--image-tag tidak valid."

  cd "$SIGAP_REPO_DIR"
  take_lock
  trap on_exit EXIT
  # Putus SSH tidak boleh membunuh deploy (diwariskan ke docker/artisan anak).
  trap '' HUP
  require_detached
  preflight

  PREV_SHA="$(git rev-parse HEAD)"
  PREV_TAG="$(env_get IMAGE_TAG "$SIGAP_IMAGE_ENV")"

  if [[ "$rollback" == "1" ]]; then
    db_ready
    do_rollback
    return 0
  fi

  # --- kode ---
  info "Mengambil $ref ..."
  git fetch --quiet --tags --prune --force origin
  local order=(remote tag raw)
  [[ "$ref" =~ ^v[0-9] ]] && order=(tag remote raw)
  local kind
  NEW_SHA=""
  for kind in "${order[@]}"; do
    case "$kind" in
      remote) NEW_SHA="$(git rev-parse --verify -q "refs/remotes/origin/$ref^{commit}" || true)" ;;
      tag) NEW_SHA="$(git rev-parse --verify -q "refs/tags/$ref^{commit}" || true)" ;;
      raw) NEW_SHA="$(git rev-parse --verify -q "$ref^{commit}" || true)" ;;
    esac
    [[ -n "$NEW_SHA" ]] && break
  done
  [[ -n "$NEW_SHA" ]] || die "Ref '$ref' tidak ditemukan."
  if [[ -z "$image_tag" ]]; then
    image_tag="$(git describe --tags --exact-match "$NEW_SHA" 2>/dev/null || git rev-parse --short=12 "$NEW_SHA")"
  fi
  NEW_TAG="$image_tag"
  [[ "$NEW_TAG" =~ ^[A-Za-z0-9_][A-Za-z0-9_.-]{0,127}$ ]] || die "Label image '$NEW_TAG' tidak valid; pakai --image-tag."

  STAGE="checked_out"
  git checkout --quiet --detach "$NEW_SHA"
  info "Kode: $(git log -1 --format='%h %s' "$NEW_SHA") (image $NEW_TAG, mode $mode)"
  dc config -q

  # --- image (rilis lama tetap berjalan) ---
  STAGE="images"
  get_images "$mode"

  # --- DB & dump pra-migrasi ---
  local dumped=0
  mysql_check_change
  if [[ "$DB_CHANGE" == "1" ]]; then
    [[ "$allow_db_change" == "1" ]] ||
      die "Definisi layanan mysql berubah (versi/konfigurasi). Jalankan ulang dengan --allow-db-change di jam sepi."
    wait_mysql_healthy 180 || die "MySQL yang berjalan tidak sehat; dump sebelum perubahan tidak dapat diambil."
    warn "Dump WAJIB dari MySQL lama sebelum kontainer mysql diganti."
    bash "$SIGAP_REPO_DIR/scripts/backup.sh" --predeploy --tag pre-deploy
    dumped=1
    maintenance_on
    db_upgrade
  else
    db_ready
  fi
  local has_pending=0
  if pending_migrations; then has_pending=1; fi
  if [[ "$dumped" == "0" && "$has_pending" == "1" ]]; then
    warn "Ada migrasi tertunda: dump pra-migrasi WAJIB."
    bash "$SIGAP_REPO_DIR/scripts/backup.sh" --predeploy --tag pre-deploy
  elif [[ "$dumped" == "0" && "$skip_backup" == "0" ]]; then
    bash "$SIGAP_REPO_DIR/scripts/backup.sh" --predeploy --tag pre-deploy
  elif [[ "$dumped" == "0" ]]; then
    info "Tanpa migrasi tertunda; dump dilewati (--skip-backup)."
  fi

  # --- maintenance & migrasi ---
  [[ "$STAGE" == "maintenance" ]] || maintenance_on
  if [[ "$has_pending" == "1" ]]; then
    STAGE="migrating"
    info "php artisan migrate --force ..."
    # Ctrl-C/TERM diabaikan selama migrasi (DDL MySQL tidak transaksional).
    trap '' INT TERM
    dc run --rm --no-deps --pull never -e SIGAP_WARMUP=0 app php artisan migrate --force
    trap - INT TERM
    MIGRATED=1
  fi
  STAGE="migrated"

  # --- layanan baru ---
  info "Menjalankan layanan rilis $NEW_TAG ..."
  if ! dc up -d --no-deps --remove-orphans --pull never --wait --wait-timeout 240 app queue scheduler nginx ||
    ! maintenance_off || ! health_check; then
    STAGE="handled"
    dc ps >&2 || true
    if [[ "$MIGRATED" == "0" ]] && revert_to_previous; then
      log_history "$NEW_SHA" "$NEW_TAG" "gagal:dikembalikan"
      die "Rilis $NEW_TAG gagal health check; rilis $PREV_TAG dipulihkan."
    fi
    dc exec -T app php artisan down --retry=60 >/dev/null 2>&1 || true
    if [[ "$MIGRATED" == "1" ]]; then print_recovery; else err "Aplikasi dibiarkan maintenance; periksa log: docker compose ... logs app nginx"; fi
    log_history "$NEW_SHA" "$NEW_TAG" "gagal:health"
    die "Rilis $NEW_TAG gagal health check."
  fi
  if dc run --rm --no-deps --pull never -e SIGAP_WARMUP=0 app php artisan migrate:status --pending=3 >/dev/null 2>&1; then
    ok "Tidak ada migrasi tertunda."
  else
    warn "migrate:status melaporkan migrasi tertunda/galat; periksa manual."
  fi
  ok "Sehat: https://$DOMAIN/up"

  # --- catat ---
  write_state "$NEW_TAG" "$PREV_SHA" "$PREV_TAG"
  log_history "$NEW_SHA" "$NEW_TAG" "ok"
  STAGE="done"

  if [[ "$set_webhook" == "1" ]]; then
    dc exec -T app php artisan sigap:telegram-set-webhook || warn "setWebhook gagal (lihat pesan di atas)."
  fi
  dc exec -T app php artisan sigap:telegram-set-webhook --info || warn "Status webhook tidak dapat dibaca."

  prune_images
  ok "Deploy $NEW_TAG selesai."
}

main "$@"
exit $?
