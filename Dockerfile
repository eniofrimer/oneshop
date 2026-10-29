# syntax=docker/dockerfile:1

FROM composer:2 AS composer
FROM node:24-bookworm-slim AS node

FROM php:8.5.12-fpm-bookworm AS base

ARG PHP_REDIS_VERSION=6.3.0

RUN apt-get update \
    && apt-get install -y --no-install-recommends \
        ca-certificates \
        curl \
        git \
        libatomic1 \
        libfreetype6-dev \
        libicu-dev \
        libjpeg62-turbo-dev \
        libpng-dev \
        libwebp-dev \
        libpq-dev \
        libzip-dev \
        unzip \
    && docker-php-ext-configure gd --with-freetype --with-jpeg --with-webp \
    && docker-php-ext-install -j"$(nproc)" \
        bcmath \
        exif \
        gd \
        intl \
        pcntl \
        pdo_pgsql \
        pdo_mysql \
        zip \
    && (php -r 'exit(extension_loaded("posix") ? 0 : 1);' \
        || docker-php-ext-install -j"$(nproc)" posix) \
    && pecl install "redis-${PHP_REDIS_VERSION}" \
    && docker-php-ext-enable redis \
    && rm -rf /var/lib/apt/lists/* /tmp/pear

COPY --from=composer /usr/bin/composer /usr/local/bin/composer

RUN groupadd --gid 1000 app \
    && useradd --uid 1000 --gid app --create-home --shell /bin/sh app \
    && mkdir -p /var/www/html \
    && chown app:app /var/www/html \
    && printf '%s\n' \
        'memory_limit=512M' \
        'upload_max_filesize=64M' \
        'post_max_size=72M' \
        'expose_php=Off' \
        'cgi.fix_pathinfo=0' \
        > /usr/local/etc/php/conf.d/oneshop-dev.ini

COPY --chmod=0755 docker/php/entrypoint.sh /usr/local/bin/oneshop-entrypoint

ENV COMPOSER_HOME=/tmp/composer \
    NPM_CONFIG_CACHE=/tmp/npm

WORKDIR /var/www/html

USER app

ENTRYPOINT ["oneshop-entrypoint"]
CMD ["php-fpm"]

# Development image only: source and dependencies live in the bind mount.
# The production targets below contain locked code and built assets.
FROM base AS dev

USER root

COPY --from=node /usr/local/bin/node /usr/local/bin/node
COPY --from=node /usr/local/lib/node_modules /usr/local/lib/node_modules

RUN ln -s ../lib/node_modules/npm/bin/npm-cli.js /usr/local/bin/npm \
    && ln -s ../lib/node_modules/npm/bin/npx-cli.js /usr/local/bin/npx

USER app

# Build the same locked source into a self-contained release. Secrets and
# per-installation data are excluded from this context by .dockerignore.
FROM dev AS release-build

COPY --chown=app:app . /var/www/html

RUN composer install --no-dev --prefer-dist --no-interaction --classmap-authoritative \
    && composer check-platform-reqs --no-dev \
    && npm ci --ignore-scripts \
    && npm run build \
    && php artisan storage:link --relative \
    && rm -rf node_modules tests .phpunit.cache \
    && rm -f bootstrap/cache/*.php

FROM base AS production-app

USER root

ARG VCS_REF=unknown
LABEL org.opencontainers.image.source="https://github.com/eniofrimer/oneshop" \
    org.opencontainers.image.revision="${VCS_REF}"

COPY --from=release-build --chown=root:root /var/www/html /var/www/html
COPY --chmod=0755 docker/php/production-entrypoint.sh /usr/local/bin/oneshop-production-entrypoint

RUN rm -f /usr/local/bin/composer \
    && mkdir -p /var/www/html/storage/app/public /var/www/html/storage/app/private \
        /var/www/html/storage/framework/cache/data /var/www/html/storage/framework/sessions \
        /var/www/html/storage/framework/views /var/www/html/storage/logs /var/www/html/bootstrap/cache \
    && chown -R app:app /var/www/html/storage /var/www/html/bootstrap/cache

USER app

ENTRYPOINT ["oneshop-production-entrypoint"]
CMD ["php-fpm"]

# The HTTP container gets built public assets and the public-media volume only.
# No application source, vendor packages, .env or private downloads are copied.
FROM nginx:stable-alpine AS production-web

ARG VCS_REF=unknown
LABEL org.opencontainers.image.source="https://github.com/eniofrimer/oneshop" \
    org.opencontainers.image.revision="${VCS_REF}"

COPY --from=release-build /var/www/html/public /var/www/html/public
COPY docker/nginx/production.conf /etc/nginx/conf.d/default.conf
RUN mkdir -p /var/www/html/storage/app/public \
    && chown -R 1000:1000 /var/www/html/storage
