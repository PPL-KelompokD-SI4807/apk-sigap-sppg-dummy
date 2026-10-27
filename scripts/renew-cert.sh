#!/usr/bin/env bash
# SiGAP SPPG - pembaruan sertifikat Let's Encrypt (dipanggil scripts/systemd/sigap-cert-renew.timer 2x sehari).
#
# Pemakaian:  scripts/renew-cert.sh
#
# certbot berjalan sebagai kontainer SESAAT (profil tools, webroot); hanya memperbarui bila < 30 hari tersisa.
# Setelahnya konfigurasi nginx dirakit ulang dan di-reload dengan anggun (tanpa memutus koneksi).
# Tidak ada proses residen dan tidak ada soket Docker di kontainer.
set -Eeuo pipefail

# shellcheck source=lib/common.sh
source "$(dirname "${BASH_SOURCE[0]}")/lib/common.sh"

main() {
  case "${1:-}" in
    -h | --help)
      awk 'NR > 1 && /^set /{exit} NR > 1' "${BASH_SOURCE[0]}" | sed 's/^# \{0,1\}//'
      return 0
      ;;
    "") ;;
    *) die "Argumen tidak dikenal: $1" ;;
  esac
  require_cmd docker flock
  [[ -f "$SIGAP_ENV_FILE" ]] || die ".env tidak ditemukan di $SIGAP_REPO_DIR."
  take_lock
  service_running nginx || die "Kontainer nginx tidak berjalan."

  dc --profile tools run --rm certbot renew --webroot -w /var/www/certbot --quiet --no-random-sleep-on-renew
  dc exec -T nginx /docker-entrypoint.d/40-sigap-config.sh >/dev/null
  dc exec -T nginx nginx -t -q
  dc exec -T nginx nginx -s reload
  ok "Pembaruan sertifikat diperiksa; nginx di-reload."
}

main "$@"
exit $?
