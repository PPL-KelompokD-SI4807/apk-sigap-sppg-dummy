#!/usr/bin/env bash
# SiGAP SPPG - menerbitkan sertifikat Let's Encrypt PERTAMA (webroot) lalu mengaktifkan nginx :443.
#
# Pemakaian:
#   scripts/bootstrap-cert.sh [--staging] [--email EMAIL] [--force]
#     --staging   CA staging Let's Encrypt (gladi; sertifikat tidak dipercaya browser). Batas produksi LE:
#                 5 kegagalan validasi per akun/host/jam, jadi gladi dulu dengan --staging.
#     --email     bawaan CERTBOT_EMAIL di .env
#     --force     terbitkan ulang walau sertifikat sudah ada (mis. dari staging ke produksi)
#
# Syarat: DNS A DOMAIN -> IP publik server, port 80 terbuka di Security Group. Idempoten: bila sertifikat
# sudah ada dan tanpa --force, hanya mengaktifkan konfigurasi TLS. Tanpa soket Docker di kontainer mana pun.
set -Eeuo pipefail

# shellcheck source=lib/common.sh
source "$(dirname "${BASH_SOURCE[0]}")/lib/common.sh"

main() {
  local staging=0 force=0 email=""
  while [[ $# -gt 0 ]]; do
    case "$1" in
      --staging) staging=1 ;;
      --force) force=1 ;;
      --email)
        email="${2:-}"
        shift
        ;;
      -h | --help)
        awk 'NR > 1 && /^set /{exit} NR > 1' "${BASH_SOURCE[0]}" | sed 's/^# \{0,1\}//'
        return 0
        ;;
      *) die "Argumen tidak dikenal: $1 (lihat --help)" ;;
    esac
    shift
  done

  require_cmd docker curl flock
  [[ -f "$SIGAP_ENV_FILE" ]] || die ".env tidak ditemukan di $SIGAP_REPO_DIR."
  take_lock

  local domain
  domain="$(env_get DOMAIN)"
  [[ "$domain" =~ ^[A-Za-z0-9]([A-Za-z0-9-]*[A-Za-z0-9])?(\.[A-Za-z0-9]([A-Za-z0-9-]*[A-Za-z0-9])?)+$ ]] ||
    die "DOMAIN kosong/tidak valid."
  email="${email:-$(env_get CERTBOT_EMAIL)}"
  [[ "$email" =~ ^[^@[:space:]]+@[^@[:space:]]+\.[^@[:space:]]+$ ]] || die "CERTBOT_EMAIL kosong/tidak valid."

  info "Menjalankan nginx (HTTP :80 untuk tantangan ACME) ..."
  dc up -d --no-deps --pull never --wait --wait-timeout 60 nginx

  local have=0
  if dc exec -T nginx test -s "/etc/letsencrypt/live/$domain/fullchain.pem"; then have=1; fi

  if [[ "$have" == "1" && "$force" == "0" ]]; then
    info "Sertifikat untuk $domain sudah ada; penerbitan dilewati (pakai --force untuk menerbitkan ulang)."
  else
    local args=(certonly --webroot -w /var/www/certbot -d "$domain" --email "$email"
      --agree-tos --no-eff-email --non-interactive --key-type ecdsa)
    [[ "$staging" == "1" ]] && args+=(--staging)
    [[ "$force" == "1" ]] && args+=(--force-renewal)
    info "Meminta sertifikat untuk $domain$([[ $staging == 1 ]] && echo ' (STAGING)') ..."
    dc --profile tools run --rm certbot "${args[@]}"
  fi

  info "Mengaktifkan konfigurasi TLS nginx ..."
  dc exec -T nginx /docker-entrypoint.d/40-sigap-config.sh
  dc exec -T nginx nginx -t
  dc exec -T nginx nginx -s reload

  local curl_args=(-fsS -o /dev/null --max-time 10 --resolve "$domain:443:127.0.0.1")
  # Sertifikat staging tidak dipercaya; --insecure HANYA untuk uji lokal gladi ini.
  [[ "$staging" == "1" ]] && curl_args+=(--insecure)
  if curl "${curl_args[@]}" "https://$domain/up"; then
    ok "HTTPS aktif: https://$domain"
  else
    warn "HTTPS belum menjawab /up (aplikasi mungkin belum berjalan). Periksa: docker compose ... logs nginx"
  fi
}

main "$@"
exit $?
