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
# Production will use a separate immutable image after deployment is agreed.
FROM base AS dev

USER root

COPY --from=node /usr/local/bin/node /usr/local/bin/node
COPY --from=node /usr/local/lib/node_modules /usr/local/lib/node_modules

RUN ln -s ../lib/node_modules/npm/bin/npm-cli.js /usr/local/bin/npm \
    && ln -s ../lib/node_modules/npm/bin/npx-cli.js /usr/local/bin/npx

USER app
