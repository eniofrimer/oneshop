#!/usr/bin/env bash
set -euo pipefail
cd "$(dirname "$0")/.."

task_revision="$(git rev-parse --short=12 HEAD)"
task_output="$(pwd)/build/oneshop-${task_revision}.tar.gz"
task_staging="$(mktemp -d /tmp/oneshop-release.XXXXXXXX)"
trap 'rm -rf "$task_staging"' EXIT
mkdir -p build

git archive --format=tar HEAD | tar -xf - -C "$task_staging"
(
    cd "$task_staging"
    composer install --no-dev --prefer-dist --no-interaction --classmap-authoritative
    composer check-platform-reqs --no-dev
    npm ci --ignore-scripts
    npm run build
    # Exercise the native, no-Horizon application before packaging.
    php artisan about
    php artisan config:cache
    php artisan route:cache
    php artisan view:cache
    # Never ship configuration or views compiled with build-machine paths/settings.
    php artisan config:clear
    php artisan route:clear
    php artisan view:clear
    rm -rf node_modules
    tar --exclude='./.env' --exclude='./auth.json' --exclude='./.github' --exclude='./tests' -czf "$task_output" .
)
printf 'Release archive: %s\n' "$task_output"
