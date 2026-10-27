# syntax=docker/dockerfile:1
# SiGAP SPPG - image multi-stage: vendor (composer, tanpa dev) -> assets (Vite -> public/miniapp)
# -> app (php-fpm, non-root) -> web (nginx + berkas statis public/).
# Rahasia TIDAK pernah masuk image: .env, bootstrap/cache/*.php, storage/* dikecualikan (.dockerignore);
# cache config/route/event dibuat saat kontainer mulai (docker/php/entrypoint.sh).

# TODO(R01-L18): pin digest base image (php/node/nginx/composer, @sha256:...) setelah digest diverifikasi
# dari registry; tidak dapat ditentukan offline. Versi minor tetap dikunci lewat ARG di bawah.
ARG PHP_VERSION=8.4
ARG NODE_VERSION=22
ARG NGINX_VERSION=1.28
# Commit sumber image (diisi scripts/deploy.sh lewat IMAGE_REVISION); diverifikasi deploy mode registry.
ARG VCS_REF=unknown

# ---------- Stage 1: dependensi PHP produksi ----------
FROM composer:2 AS vendor
WORKDIR /app
COPY composer.json composer.lock ./
RUN composer install --no-dev --no-interaction --no-progress --prefer-dist \
    --no-scripts --no-autoloader --ignore-platform-reqs
COPY . .
RUN composer dump-autoload --no-dev --optimize --classmap-authoritative --no-scripts

# ---------- Stage 2: build Mini App (Vite: root resources/miniapp, keluaran public/miniapp) ----------
FROM node:${NODE_VERSION}-alpine AS assets
WORKDIR /app
COPY package.json package-lock.json .npmrc ./
RUN npm ci --no-audit --no-fund
COPY vite.config.js ./
# main.js mengimpor ../css/miniapp.css, jadi seluruh resources/ disalin.
COPY resources ./resources
RUN npm run build && test -f public/miniapp/index.html

# ---------- Stage 3: runtime PHP-FPM ----------
FROM php:${PHP_VERSION}-fpm-alpine AS app
RUN set -eux; \
    apk add --no-cache icu-libs icu-data-full libzip libpng libjpeg-turbo freetype libwebp fcgi; \
    apk add --no-cache --virtual .build-deps $PHPIZE_DEPS icu-dev libzip-dev libpng-dev \
      libjpeg-turbo-dev freetype-dev libwebp-dev; \
    docker-php-ext-configure gd --with-freetype --with-jpeg --with-webp; \
    docker-php-ext-install -j"$(nproc)" pdo_mysql bcmath intl gd zip opcache pcntl; \
    apk del .build-deps; \
    rm -rf /tmp/* /usr/src/php.tar* ; \
    cp "$PHP_INI_DIR/php.ini-production" "$PHP_INI_DIR/php.ini"

# php.ini produksi + pool php-fpm (pm=ondemand). Nama berkas diurutkan setelah bawaan image.
COPY docker/php/php.ini /usr/local/etc/php/conf.d/zz-sigap.ini
COPY docker/php/php-fpm.conf /usr/local/etc/php-fpm.d/zzz-sigap.conf
COPY --chmod=0755 docker/php/entrypoint.sh /usr/local/bin/sigap-entrypoint
COPY --chmod=0755 docker/php/healthcheck.sh /usr/local/bin/sigap-healthcheck

# Dapat dinaikkan lewat environment compose saat uji beban (PHP_FPM_MAX_CHILDREN).
# PHP_FPM_CLEAR_ENV: `no` untuk pengembangan (tanpa cache config); docker-compose.prod.yml memakai `yes`
# (worker tidak mewarisi rahasia lingkungan; config sudah di-cache oleh entrypoint).
ENV PHP_FPM_MAX_CHILDREN=4 \
    PHP_FPM_CLEAR_ENV=no

# User non-root; UID/GID harus sama dengan pemilik volume storage.
ARG UID=1000
ARG GID=1000
RUN addgroup -g "${GID}" sigap && adduser -D -H -u "${UID}" -G sigap -s /sbin/nologin sigap
WORKDIR /var/www/html

# Kode dimiliki root (hanya-baca bagi proses PHP); hanya storage/ dan bootstrap/cache/ yang dapat ditulis.
COPY . .
COPY --from=vendor /app/vendor ./vendor
COPY --from=assets /app/public/miniapp ./public/miniapp
RUN set -eux; \
    rm -f bootstrap/cache/*.php public/hot; \
    mkdir -p storage/app/secure storage/app/private storage/app/public \
      storage/framework/cache/data storage/framework/sessions storage/framework/views \
      storage/logs bootstrap/cache; \
    chown -R sigap:sigap storage bootstrap/cache; \
    chmod -R u+rwX,g+rX,o-rwx storage bootstrap/cache; \
    test -f public/miniapp/index.html; \
    test ! -e .env

ARG VCS_REF
LABEL org.opencontainers.image.revision="${VCS_REF}" org.opencontainers.image.title="sigap-sppg-app"

USER sigap
EXPOSE 9000
HEALTHCHECK --interval=30s --timeout=5s --start-period=60s --retries=3 CMD ["sigap-healthcheck"]
ENTRYPOINT ["sigap-entrypoint"]
CMD ["php-fpm"]

# ---------- Stage 4: nginx (berkas statis public/ + FastCGI ke php-fpm) ----------
FROM nginx:${NGINX_VERSION}-alpine AS web
# Konfigurasi dirakit saat mulai oleh /docker-entrypoint.d/40-sigap-config.sh dari templat
# (DOMAIN kosong = mode pengembangan HTTP; DOMAIN terisi = :80 ACME+redirect, :443 bila sertifikat ada).
RUN rm -f /etc/nginx/conf.d/default.conf && mkdir -p /var/www/certbot
COPY docker/nginx/templates/ /etc/nginx/sigap-templates/
COPY docker/nginx/snippets/ /etc/nginx/snippets/
COPY --chmod=0755 docker/nginx/entrypoint/40-sigap-config.sh /docker-entrypoint.d/40-sigap-config.sh
COPY --from=app /var/www/html/public /var/www/html/public
ARG VCS_REF
LABEL org.opencontainers.image.revision="${VCS_REF}" org.opencontainers.image.title="sigap-sppg-web"
EXPOSE 80 443
HEALTHCHECK --interval=30s --timeout=5s --start-period=10s --retries=3 \
  CMD ["wget", "-q", "-O", "/dev/null", "http://127.0.0.1:8080/nginx-health"]
