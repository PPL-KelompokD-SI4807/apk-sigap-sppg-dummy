#!/usr/bin/env bash
# SiGAP SPPG - uji sintaks nginx (nginx -t) pada image web untuk tiga mode, tanpa menyalakan layanan:
#   1) produksi tanpa sertifikat (HTTP :80), 2) produksi dengan sertifikat tiruan (HTTP+HTTPS), 3) dev.
# Memakai entrypoint image yang sama dengan produksi, sehingga bentrok dengan nginx.conf bawaan image
# (mis. direktif ganda di konteks http) tertangkap sebelum rilis.
#
# Pemakaian: scripts/nginx-test.sh [IMAGE]    (bawaan: ${IMAGE_REGISTRY:-localhost}/sigap-sppg-web:${IMAGE_TAG:-latest})
set -Eeuo pipefail

# shellcheck source=lib/common.sh
source "$(dirname "${BASH_SOURCE[0]}")/lib/common.sh"

require_cmd docker openssl mktemp

image="${1:-${IMAGE_REGISTRY:-localhost}/sigap-sppg-web:${IMAGE_TAG:-latest}}"
domain="uji.sigap.invalid"
tmp="$(mktemp -d)"
trap 'rm -rf "$tmp"' EXIT

# Git Bash di Windows: openssl/docker bawaan membutuhkan path Windows (di Linux/VPS tidak berpengaruh).
tmp_native="$tmp"
if command -v cygpath >/dev/null 2>&1; then tmp_native="$(cygpath -m "$tmp")"; fi

mkdir -p "$tmp/live/$domain"
MSYS_NO_PATHCONV=1 openssl req -x509 -newkey rsa:2048 -nodes -days 1 -subj "/CN=$domain" \
  -keyout "$tmp_native/live/$domain/privkey.pem" -out "$tmp_native/live/$domain/fullchain.pem" >/dev/null 2>&1
chmod -R a+rX "$tmp"

# Di dalam kontainer: `app` (upstream php-fpm) harus dapat di-resolve saat nginx -t, jadi dipetakan ke loopback.
inner='set -e; /docker-entrypoint.d/40-sigap-config.sh >/dev/null; nginx -t'

t() { # t NAMA [opsi docker ...]
  local name="$1"
  shift
  if out="$(MSYS_NO_PATHCONV=1 docker run --rm --add-host app:127.0.0.1 --entrypoint sh "$@" "$image" -c "$inner" 2>&1)"; then
    ok "nginx -t ($name): sintaks OK"
  else
    printf '%s\n' "$out" >&2
    die "nginx -t ($name) GAGAL pada image $image."
  fi
}

t "HTTP saja" -e "DOMAIN=$domain"
t "HTTP+HTTPS (sertifikat tiruan)" -e "DOMAIN=$domain" -v "$tmp_native/live:/etc/letsencrypt/live:ro"
t "dev" -e DOMAIN= -e SIGAP_NGINX_MODE=dev
