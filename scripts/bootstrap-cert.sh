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
# Syarat: DNS DOMAIN dan MINIAPP_DOMAIN (bila diisi) -> IP publik server, port 80 terbuka. Bila sertifikat
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

  local domain miniapp_domain host
  domain="$(env_get DOMAIN)"
  miniapp_domain="$(env_get MINIAPP_DOMAIN)"
  local cutover
  cutover="$(env_get MINIAPP_CUTOVER)"
  [[ "${cutover:-off}" == off ]] || die "Bootstrap TLS memerlukan MINIAPP_CUTOVER=off. Gunakan renew-cert.sh setelah cutover."
  local host_pattern='^[A-Za-z0-9]([A-Za-z0-9-]*[A-Za-z0-9])?(\.[A-Za-z0-9]([A-Za-z0-9-]*[A-Za-z0-9])?)+$'
  [[ "$domain" =~ $host_pattern ]] ||
    die "DOMAIN kosong/tidak valid."
  local hosts=("$domain")
  if [[ -n "$miniapp_domain" ]]; then
    [[ "$miniapp_domain" =~ $host_pattern && "$miniapp_domain" == "${miniapp_domain,,}" && "$miniapp_domain" != "${domain,,}" ]] ||
      die "MINIAPP_DOMAIN harus berupa nama host huruf kecil yang berbeda dari DOMAIN."
    hosts+=("$miniapp_domain")
  fi
  email="${email:-$(env_get CERTBOT_EMAIL)}"
  [[ "$email" =~ ^[^@[:space:]]+@[^@[:space:]]+\.[^@[:space:]]+$ ]] || die "CERTBOT_EMAIL kosong/tidak valid."
  # Compose harus memakai nilai yang sama dengan pemilihan lineage dan probe.
  local -x DOMAIN="$domain" MINIAPP_DOMAIN="$miniapp_domain" MINIAPP_CUTOVER=off

  info "Menjalankan nginx (HTTP :80 untuk tantangan ACME) ..."
  dc up -d --no-deps --pull never --wait --wait-timeout 60 nginx

  for host in "${hosts[@]}"; do
    if [[ "$force" == "0" ]] && dc exec -T nginx test -s "/etc/letsencrypt/live/$host/fullchain.pem" &&
      dc exec -T nginx test -s "/etc/letsencrypt/live/$host/privkey.pem"; then
      info "Sertifikat untuk $host sudah ada; penerbitan dilewati."
      continue
    fi
    local args=(certonly --webroot -w /var/www/certbot --cert-name "$host" -d "$host" --email "$email"
      --agree-tos --no-eff-email --non-interactive --key-type ecdsa)
    [[ "$staging" == "1" ]] && args+=(--staging)
    [[ "$force" == "1" ]] && args+=(--force-renewal)
    info "Meminta sertifikat untuk $host$([[ $staging == 1 ]] && echo ' (STAGING)') ..."
    dc --profile tools run --rm certbot "${args[@]}"
  done

  info "Mengaktifkan konfigurasi TLS nginx ..."
  dc exec -T nginx /docker-entrypoint.d/40-sigap-config.sh
  dc exec -T nginx nginx -t
  dc exec -T nginx nginx -s reload

  local curl_args=() gagal=0
  # Sertifikat staging tidak dipercaya; --insecure HANYA untuk uji lokal gladi ini.
  [[ "$staging" == "1" ]] && curl_args+=(--insecure)
  for host in "${hosts[@]}"; do
    local paths=(/up) path
    [[ -z "$miniapp_domain" ]] || paths+=(/miniapp/)
    for path in "${paths[@]}"; do
      if https_probe "$host" "$path" "${curl_args[@]}"; then
        ok "HTTPS aktif: https://$host$path"
      else
        err "Probe gagal: https://$host$path. Periksa: docker compose ... logs nginx"
        gagal=1
      fi
    done
  done
  return "$gagal"
}

main "$@"
exit $?
