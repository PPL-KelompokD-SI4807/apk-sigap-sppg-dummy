#!/bin/sh
# SiGAP SPPG - merakit konfigurasi nginx dari /etc/nginx/sigap-templates ke /etc/nginx/conf.d.
# Dijalankan otomatis oleh entrypoint image nginx (/docker-entrypoint.d) dan aman dijalankan ulang setelah
# sertifikat terbit/diperbarui (scripts/bootstrap-cert.sh, scripts/renew-cert.sh):
#   docker compose ... exec -T nginx /docker-entrypoint.d/40-sigap-config.sh && ... exec -T nginx nginx -s reload
#
#   DOMAIN kosong + SIGAP_NGINX_MODE=dev -> mode pengembangan: aplikasi di HTTP :80 (docker-compose.yml lokal).
#   DOMAIN kosong tanpa SIGAP_NGINX_MODE=dev -> gagal mulai (exit 1).
#   DOMAIN terisi   -> :80 hanya ACME + /up + redirect HTTPS; :443 diaktifkan HANYA bila sertifikat
#                      /etc/letsencrypt/live/$DOMAIN/{fullchain,privkey}.pem ada (nginx tidak gagal start
#                      sebelum sertifikat pertama terbit).
# MINIAPP_DOMAIN menyiapkan vhost baru. MINIAPP_CUTOVER=on menutup jalur lama.
# Variabel nginx tidak ikut disubstitusi.
set -eu

TPL=/etc/nginx/sigap-templates
OUT=/etc/nginx/conf.d
ME=40-sigap-config

log() { echo "$ME: $*"; }

DOMAIN="${DOMAIN:-}"
MINIAPP_DOMAIN="${MINIAPP_DOMAIN:-}"
MINIAPP_CUTOVER="${MINIAPP_CUTOVER:-off}"
SIGAP_WEB_MINIAPP_SNIPPET=sigap-miniapp.conf
TELEGRAM_WEBHOOK_IP_FILTER="${TELEGRAM_WEBHOOK_IP_FILTER:-on}"
export DOMAIN MINIAPP_DOMAIN SIGAP_WEB_MINIAPP_SNIPPET TELEGRAM_WEBHOOK_IP_FILTER

case "$TELEGRAM_WEBHOOK_IP_FILTER" in
  on|off) ;;
  *) log "TELEGRAM_WEBHOOK_IP_FILTER harus on atau off"; exit 1 ;;
esac

case "$MINIAPP_CUTOVER" in
  off) ;;
  on)
    if [ -z "$MINIAPP_DOMAIN" ]; then
      log "MINIAPP_CUTOVER=on memerlukan MINIAPP_DOMAIN."
      exit 1
    fi
    for host in "$DOMAIN" "$MINIAPP_DOMAIN"; do
      case "$host" in
        ''|*[!A-Za-z0-9.-]*|.*|*.|*..*|-*) log "Host TLS tidak valid."; exit 1 ;;
      esac
      if [ ! -s "/etc/letsencrypt/live/$host/fullchain.pem" ] || [ ! -s "/etc/letsencrypt/live/$host/privkey.pem" ]; then
        log "Cutover memerlukan sertifikat aktif untuk $host."
        exit 1
      fi
    done
    SIGAP_WEB_MINIAPP_SNIPPET=sigap-tolak-miniapp.conf
    ;;
  *) log "MINIAPP_CUTOVER harus on atau off."; exit 1 ;;
esac

render() {
  envsubst '${DOMAIN} ${MINIAPP_DOMAIN} ${SIGAP_WEB_MINIAPP_SNIPPET} ${TELEGRAM_WEBHOOK_IP_FILTER}' < "$1" > "$2.tmp"
  mv "$2.tmp" "$2"
}

rm -f "$OUT/default.conf"
# Bersihkan vhost opsional saat berpindah mode atau sebelum membuat ulang berkas.
rm -f "$OUT/11-sigap-miniapp-80.conf" "$OUT/21-sigap-miniapp-443.conf"
render "$TPL/00-http.conf.template" "$OUT/00-sigap-http.conf"

if [ -z "$DOMAIN" ]; then
  # Mode pengembangan hanya bila diminta eksplisit (docker-compose.yml lokal): DOMAIN yang lupa diisi di
  # produksi tidak boleh diam-diam menyajikan aplikasi lewat HTTP.
  if [ "${SIGAP_NGINX_MODE:-}" != "dev" ]; then
    log "DOMAIN kosong dan SIGAP_NGINX_MODE bukan dev: menolak mulai."
    exit 1
  fi
  rm -f "$OUT/10-sigap-80.conf" "$OUT/20-sigap-443.conf"
  render "$TPL/dev.conf.template" "$OUT/10-sigap-dev.conf"
  log "DOMAIN kosong: mode pengembangan (HTTP :80, tanpa TLS)."
  exit 0
fi

# Validasi nama host (cegah injeksi direktif lewat DOMAIN).
case "$DOMAIN" in
  *[!A-Za-z0-9.-]*|.*|*.|*..*|-*)
    log "DOMAIN tidak valid."
    exit 1
    ;;
esac

if [ -n "$MINIAPP_DOMAIN" ]; then
  case "$MINIAPP_DOMAIN" in
    *[!a-z0-9.-]*|.*|*.|*..*|-*)
      log "MINIAPP_DOMAIN tidak valid (gunakan nama host huruf kecil)."
      exit 1
      ;;
  esac
  if [ "$(printf '%s' "$DOMAIN" | tr '[:upper:]' '[:lower:]')" = "$MINIAPP_DOMAIN" ]; then
    log "DOMAIN dan MINIAPP_DOMAIN harus berbeda."
    exit 1
  fi

  render "$TPL/miniapp-80.conf.template" "$OUT/11-sigap-miniapp-80.conf"
  miniapp_live="/etc/letsencrypt/live/$MINIAPP_DOMAIN"
  if [ -s "$miniapp_live/fullchain.pem" ] && [ -s "$miniapp_live/privkey.pem" ]; then
    render "$TPL/miniapp-443.conf.template" "$OUT/21-sigap-miniapp-443.conf"
    log "TLS aktif untuk $MINIAPP_DOMAIN."
  else
    log "Sertifikat untuk $MINIAPP_DOMAIN belum ada: hanya HTTP :80 (ACME)."
  fi
fi

rm -f "$OUT/10-sigap-dev.conf"
render "$TPL/80.conf.template" "$OUT/10-sigap-80.conf"

live="/etc/letsencrypt/live/$DOMAIN"
if [ -s "$live/fullchain.pem" ] && [ -s "$live/privkey.pem" ]; then
  render "$TPL/443.conf.template" "$OUT/20-sigap-443.conf"
  log "TLS aktif untuk $DOMAIN."
else
  rm -f "$OUT/20-sigap-443.conf"
  log "Sertifikat untuk $DOMAIN belum ada: hanya HTTP :80 (ACME). Jalankan scripts/bootstrap-cert.sh."
fi
