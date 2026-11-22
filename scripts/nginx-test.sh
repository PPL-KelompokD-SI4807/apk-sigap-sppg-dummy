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
snip="$(cd "$(dirname "${BASH_SOURCE[0]}")/../docker/nginx/snippets" && pwd)"
snip_native="$snip"
if command -v cygpath >/dev/null 2>&1; then snip_native="$(cygpath -m "$snip")"; fi
# Snippet dari working tree dipasang di atas isi image: yang diuji adalah berkas repo, bukan salinan lama di image.
inner='set -e; /docker-entrypoint.d/40-sigap-config.sh >/dev/null; nginx -t'

t() { # t NAMA [opsi docker ...]
  local name="$1"
  shift
  if out="$(MSYS_NO_PATHCONV=1 docker run --rm --add-host app:127.0.0.1 --entrypoint sh -v "$snip_native:/etc/nginx/snippets:ro" "$@" "$image" -c "$inner" 2>&1)"; then
    ok "nginx -t ($name): sintaks OK"
  else
    printf '%s\n' "$out" >&2
    die "nginx -t ($name) GAGAL pada image $image."
  fi
}

t "HTTP saja" -e "DOMAIN=$domain"
t "HTTP+HTTPS (sertifikat tiruan)" -e "DOMAIN=$domain" -v "$tmp_native/live:/etc/letsencrypt/live:ro"
t "dev" -e DOMAIN= -e SIGAP_NGINX_MODE=dev

# --- Uji perilaku klien web ("/" dan /web/): nginx dijalankan (mode dev) dengan snippet dari repo dan docroot palsu. ---
# Snippet repo dipasang di atas isi image, sehingga yang diuji adalah berkas di working tree.
mkdir -p "$tmp/web/assets" "$tmp/miniapp"
printf '<!doctype html><title>web</title>' >"$tmp/web/index.html"
printf 'console.log(1)' >"$tmp/web/assets/app-abc123.js"
printf '<!doctype html><title>mini</title>' >"$tmp/miniapp/index.html"
chmod -R a+rX "$tmp"
tmp_web="$tmp_native/web"
tmp_mini="$tmp_native/miniapp"

perilaku='set -e; /docker-entrypoint.d/40-sigap-config.sh >/dev/null; nginx
sleep 1
for p in / /web /web/ /web/assets/app-abc123.js /web/assets/tidak-ada.js /miniapp/; do
  echo "=== $p"; wget -S -q -O /dev/null "http://127.0.0.1/${p#/}" 2>&1 || true
done
echo "=== BODI /"; wget -q -O - http://127.0.0.1/'
if ! hasil="$(MSYS_NO_PATHCONV=1 docker run --rm --add-host app:127.0.0.1 --entrypoint sh -e DOMAIN= -e SIGAP_NGINX_MODE=dev \
  -v "$snip_native:/etc/nginx/snippets:ro" -v "$tmp_web:/var/www/html/public/web:ro" -v "$tmp_mini:/var/www/html/public/miniapp:ro" \
  "$image" -c "$perilaku" 2>&1)"; then
  printf '%s\n' "$hasil" >&2
  die "uji perilaku web GAGAL (nginx tidak jalan)."
fi

bagian() { # bagian JALUR -> keluaran bagian itu
  printf '%s\n' "$hasil" | awk -v p="=== $1" '/^=== /{on=($0==p)} on'
}
harus() { # harus JALUR POLA KETERANGAN
  bagian "$1" | grep -qiE -- "$2" || { printf '%s\n' "$hasil" >&2; die "uji web: $1 tidak memenuhi: $3"; }
}
tidak() {
  if bagian "$1" | grep -qiE -- "$2"; then printf '%s\n' "$hasil" >&2; die "uji web: $1 melanggar: $3"; fi
}

harus "/" "HTTP/1.1 200" "'/' melayani 200 dari index web"
harus "BODI /" "<title>web</title>" "'/' berisi index.html web (bukan Laravel)"
harus "/" "Content-Security-Policy: default-src 'self'; script-src 'self';" "CSP tegak web"
tidak "/" "telegram" "CSP web tanpa telegram"
harus "/" "Cache-Control: no-cache" "index no-cache"
harus "/" "X-Frame-Options: DENY" "X-Frame-Options"
harus "/" "Cross-Origin-Opener-Policy: same-origin" "COOP"
harus "/" "Cross-Origin-Resource-Policy: same-origin" "CORP"
harus "/" "Content-Security-Policy-Report-Only: require-trusted-types-for 'script'" "Trusted Types Report-Only"
harus "/web" "301" "/web dialihkan ke /web/"
harus "/web/" "HTTP/1.1 200" "/web/ 200"
harus "/web/" "Cache-Control: no-cache" "/web/ no-cache"
harus "/web/assets/app-abc123.js" "Cache-Control: public, max-age=31536000, immutable" "aset immutable"
harus "/web/assets/app-abc123.js" "Content-Security-Policy: default-src 'self'" "aset membawa CSP web"
harus "/web/assets/tidak-ada.js" "HTTP/1.1 404" "aset hilang 404 (bukan index)"
harus "/miniapp/" "script-src 'self' https://telegram.org/js/telegram-web-app.js" "Mini App tetap mengizinkan skrip Telegram"
tidak "/miniapp/" "Cross-Origin-Opener-Policy|X-Frame-Options" "Mini App tidak berubah"
ok "perilaku nginx web: '/' dan /web/ (header, cache, 404, Mini App tak berubah) OK"
