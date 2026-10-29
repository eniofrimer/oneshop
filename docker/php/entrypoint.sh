#!/bin/sh
set -eu

umask 0002

cd /var/www/html

# Composer/npm can run before vendor exists. Startup deliberately does not
# install dependencies, create APP_KEY, optimize the application or migrate DBs.
for directory in \
    bootstrap/cache \
    storage/app/public \
    storage/app/private \
    storage/framework/cache/data \
    storage/framework/sessions \
    storage/framework/testing \
    storage/framework/views \
    storage/logs
do
    mkdir -p "$directory"

    if [ ! -w "$directory" ]; then
        printf '%s\n' \
            "Cannot write to $directory. Set LOCAL_UID and LOCAL_GID to the owner of the checkout." >&2
        exit 1
    fi
done

exec docker-php-entrypoint "$@"
