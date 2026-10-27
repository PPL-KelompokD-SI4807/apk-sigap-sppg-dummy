#!/bin/sh
# SiGAP SPPG - entrypoint image `app` (php-fpm, queue, scheduler, perintah artisan sekali jalan).
#
# - Memastikan struktur storage/ ada (volume bernama bisa kosong saat pertama dipasang).
# - SIGAP_WARMUP=auto (bawaan): di APP_ENV=production membuat cache config/route/event (+ view untuk
#   CONTAINER_ROLE=app) saat kontainer MULAI, bukan saat build, karena rahasia hanya ada saat runtime dan
#   bootstrap/cache/ tidak berada di volume (tiap kontainer memiliki salinannya sendiri).
#   SIGAP_WARMUP=0 dipakai scripts/deploy.sh untuk `run --rm` (migrate) agar tidak menulis cache view.
# - Gagal membuat cache = kontainer gagal mulai (terlihat di health check deploy), bukan diam-diam jalan.
# Tidak pernah mencetak nilai variabel lingkungan.
set -eu

cd /var/www/html

role="${CONTAINER_ROLE:-app}"
warmup="${SIGAP_WARMUP:-auto}"

mkdir -p storage/app/secure storage/app/private storage/app/public \
  storage/framework/cache/data storage/framework/sessions storage/framework/views \
  storage/logs bootstrap/cache

if [ "$warmup" = "auto" ]; then
  if [ "${APP_ENV:-production}" = "production" ]; then warmup=1; else warmup=0; fi
fi

# Manifest paket (composer dijalankan dengan --no-scripts saat build).
php artisan package:discover --quiet

if [ "$warmup" = "1" ]; then
  php artisan config:cache --quiet
  php artisan route:cache --quiet
  php artisan event:cache --quiet
  if [ "$role" = "app" ]; then
    php artisan view:cache --quiet
  fi
else
  # Hindari cache usang dari image/kontainer sebelumnya (mode pengembangan atau perintah sekali jalan).
  php artisan config:clear --quiet >/dev/null 2>&1 || true
  php artisan route:clear --quiet >/dev/null 2>&1 || true
  php artisan event:clear --quiet >/dev/null 2>&1 || true
fi

exec "$@"
