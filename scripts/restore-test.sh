#!/usr/bin/env bash
# SiGAP SPPG - uji pemulihan di SANDBOX MySQL sesaat (kontainer terpisah, tanpa jaringan); produksi hanya dibaca.
#
# Pemakaian:
#   scripts/restore-test.sh                 dump langsung DB produksi (snapshot konsisten, hanya-baca) ->
#                                           sandbox, pindai isi, bandingkan jumlah tabel dan baris tabel kunci
#                                           dengan produksi, lalu hapus sandbox.
#   scripts/restore-test.sh --from SUMBER --identity KUNCI_PRIVAT [--keep]
#                                           uji artefak cadangan (lokal atau remote:jalur) lewat restore.sh.
#   --keep                                  biarkan sandbox hidup untuk inspeksi.
#
# Sandbox memakai memori tambahan (RESTORE_SANDBOX_MEM, bawaan 512m): jalankan di jam sepi
# (scripts/systemd/sigap-restore-test.timer). Uji ujung-ke-ujung dengan kunci privat dilakukan operator
# dengan --from + --identity sementara, karena server tidak memegang kunci privat.
set -Eeuo pipefail

# shellcheck source=lib/common.sh
source "$(dirname "${BASH_SOURCE[0]}")/lib/common.sh"

KEEP=0
DUMP_FILE=""

on_exit() {
  local rc=$?
  sbx_stop "$KEEP"
  [[ -n "$DUMP_FILE" && -e "$DUMP_FILE" ]] && rm -f -- "$DUMP_FILE"
  exit "$rc"
}

main() {
  local from="" identity=""
  while [[ $# -gt 0 ]]; do
    case "$1" in
      --from)
        from="${2:-}"
        shift
        ;;
      --identity)
        identity="${2:-}"
        shift
        ;;
      --keep) KEEP=1 ;;
      -h | --help)
        awk 'NR > 1 && /^set /{exit} NR > 1' "${BASH_SOURCE[0]}" | sed 's/^# \{0,1\}//'
        return 0
        ;;
      *) die "Argumen tidak dikenal: $1 (lihat --help)" ;;
    esac
    shift
  done

  if [[ -n "$from" ]]; then
    local args=("$from" --verify)
    [[ -n "$identity" ]] && args+=(--identity "$identity")
    [[ "$KEEP" == "1" ]] && args+=(--keep)
    exec bash "$SIGAP_REPO_DIR/scripts/restore.sh" "${args[@]}"
  fi

  require_cmd docker flock gzip awk
  [[ -f "$SIGAP_ENV_FILE" ]] || die ".env tidak ditemukan di $SIGAP_REPO_DIR."
  take_lock
  trap on_exit EXIT

  local prod_db
  prod_db="$(env_get DB_DATABASE)"
  valid_db_name "$prod_db" || die "DB_DATABASE kosong/tidak valid di .env."
  wait_mysql_healthy 300 || die "MySQL produksi tidak sehat."

  # Dump ke berkas sementara terkompresi (mode 600) agar bisa dipindai lalu dimuat; dihapus saat keluar.
  local work
  work="$(cfg BACKUP_LOCAL_DIR /var/backups/sigap)"
  ensure_private_dir "$work"
  DUMP_FILE="$(mktemp "$work/restore-test.XXXXXX")"
  chmod 600 "$DUMP_FILE"
  info "Dump '$prod_db' (hanya-baca) ..."
  mysql_exec mysqldump "${SIGAP_DUMP_OPTS[@]}" "$prod_db" | gzip -1 >"$DUMP_FILE"
  gzip -dc "$DUMP_FILE" | scan_dump || die "Dump produksi gagal dipindai."

  sbx_start
  info "Memuat ke sandbox ..."
  gzip -dc "$DUMP_FILE" | sbx_load

  local rep_prod rep_test
  rep_prod="$(db_report "$prod_db")"
  rep_test="$(db_report "$SIGAP_SBX_DB" sbx_query)"

  printf '%-28s %12s %12s\n' "objek" "produksi" "uji"
  local failed=0 key vp vt
  while read -r key vp; do
    vt="$(awk -v k="$key" '$1==k{print $2}' <<<"$rep_test")"
    printf '%-28s %12s %12s\n' "$key" "$vp" "$vt"
    if [[ "$key" == "tabel" && "$vp" != "$vt" ]]; then
      err "Jumlah tabel berbeda."
      failed=1
    elif [[ "$key" != "tabel" && "$vp" != "$vt" ]]; then
      # Produksi dapat berubah setelah snapshot; selisih dilaporkan, bukan gagal.
      warn "Baris '$key' berbeda (produksi berubah setelah snapshot?)."
    fi
  done <<<"$rep_prod"

  report_ok "$rep_test" || {
    err "Sandbox tanpa tabel atau tabel migrations kosong."
    failed=1
  }

  [[ "$failed" == "0" ]] || die "Uji pemulihan GAGAL."
  ok "Uji pemulihan lolos."
}

main "$@"
exit $?
