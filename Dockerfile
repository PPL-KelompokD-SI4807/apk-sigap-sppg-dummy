# syntax=docker/dockerfile:1
# SiGAP SPPG - image multi-stage: vendor (composer) -> assets (node/vite) -> app (php-fpm) -> web (nginx)

ARG PHP_VERSION=8.4
ARG NODE_VERSION=22

# ---------- Stage 1: dependensi PHP produksi ----------
FROM composer:2 AS vendor
WORKDIR /app
COPY composer.json composer.lock* ./
RUN composer install --no-dev --no-interaction --no-progress --prefer-dist \
    --no-scripts --no-autoloader --ignore-platform-reqs
COPY . .
RUN composer dump-autoload --no-dev --optimize --classmap-authoritative --no-scripts

# ---------- Stage 2: build Mini App / aset (Vite) ----------
FROM node:${NODE_VERSION}-alpine AS assets
WORKDIR /app
COPY package.json package-lock.json* ./
RUN npm ci
COPY . .
RUN npm run build

# ---------- Stage 3: runtime PHP-FPM ----------
FROM php:${PHP_VERSION}-fpm-alpine AS app
RUN apk add --no-cache icu-libs libzip libpng libjpeg-turbo freetype libwebp \
 && apk add --no-cache --virtual .build-deps $PHPIZE_DEPS icu-dev libzip-dev libpng-dev \
      libjpeg-turbo-dev freetype-dev libwebp-dev \
 && docker-php-ext-configure gd --with-freetype --with-jpeg --with-webp \
 && docker-php-ext-install -j"$(nproc)" pdo_mysql bcmath intl gd zip opcache \
 && apk del .build-deps

COPY docker/php/php.ini /usr/local/etc/php/conf.d/zz-sigap.ini

# user non-root
ARG UID=1000
ARG GID=1000
RUN addgroup -g ${GID} sigap && adduser -D -u ${UID} -G sigap sigap
WORKDIR /var/www/html

COPY --chown=sigap:sigap . .
COPY --from=vendor --chown=sigap:sigap /app/vendor ./vendor
COPY --from=assets --chown=sigap:sigap /app/public/build ./public/build
RUN mkdir -p storage/framework/cache storage/framework/sessions storage/framework/views storage/logs bootstrap/cache \
 && chown -R sigap:sigap storage bootstrap/cache

USER sigap
EXPOSE 9000
CMD ["php-fpm"]

# ---------- Stage 4: nginx (berkas statis public/ + proxy ke php-fpm) ----------
FROM nginx:1.27-alpine AS web
COPY docker/nginx/default.conf /etc/nginx/conf.d/default.conf
COPY --from=app /var/www/html/public /var/www/html/public
EXPOSE 80
