#!/bin/sh
set -eu
umask 0027
cd /var/www/html

# Read only explicitly supported Compose secrets. Never echo their content or
# write them into the image, the source directory or a generated .env file.
if [ -n "${APP_KEY_FILE:-}" ]; then
    if [ -n "${APP_KEY:-}" ]; then
        echo 'Configure APP_KEY or APP_KEY_FILE, not both.' >&2
        exit 1
    fi
    APP_KEY="$(cat "$APP_KEY_FILE")"
    export APP_KEY
fi
if [ -n "${DB_PASSWORD_FILE:-}" ]; then
    if [ -n "${DB_PASSWORD:-}" ]; then
        echo 'Configure DB_PASSWORD or DB_PASSWORD_FILE, not both.' >&2
        exit 1
    fi
    DB_PASSWORD="$(cat "$DB_PASSWORD_FILE")"
    export DB_PASSWORD
fi

if [ -z "${APP_KEY:-}" ]; then
    echo 'A persistent application key must be supplied before starting the release.' >&2
    exit 1
fi

for directory in bootstrap/cache storage/app/public storage/app/private \
    storage/framework/cache/data storage/framework/sessions storage/framework/views storage/logs
do
    mkdir -p "$directory"
    if [ ! -w "$directory" ]; then
        echo "Runtime directory is not writable: $directory" >&2
        exit 1
    fi
done

# Migrations and installation are explicit deploy operations. Every web/worker
# process starts against the same key, locked code and persistent data volumes.
exec docker-php-entrypoint "$@"
