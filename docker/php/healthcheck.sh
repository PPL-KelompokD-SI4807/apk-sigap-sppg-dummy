#!/bin/sh
# SiGAP SPPG - health check kontainer image `app` menurut CONTAINER_ROLE.
#   app       : ping php-fpm lewat FastCGI (ping.path=/fpm-ping, docker/php/php-fpm.conf)
#   queue     : proses `artisan queue:work` hidup
#   scheduler : proses `artisan schedule:work` hidup
# Catatan: queue/scheduler hanya memeriksa proses hidup (bukan macet); kontainer yang prosesnya keluar
# dimulai ulang oleh restart policy.
set -eu

case "${CONTAINER_ROLE:-app}" in
  app)
    out=$(SCRIPT_NAME=/fpm-ping SCRIPT_FILENAME=/fpm-ping REQUEST_METHOD=GET \
      cgi-fcgi -bind -connect 127.0.0.1:9000 2>/dev/null) || exit 1
    case "$out" in
      *pong*) exit 0 ;;
      *) exit 1 ;;
    esac
    ;;
  queue)
    pgrep -f 'artisan queue:work' >/dev/null
    ;;
  scheduler)
    pgrep -f 'artisan schedule:work' >/dev/null
    ;;
  *)
    exit 0
    ;;
esac
