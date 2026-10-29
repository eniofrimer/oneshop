#!/usr/bin/env bash
set -euo pipefail
cd "$(dirname "$0")/.."

task_revision="$(git rev-parse --short=12 HEAD)"
task_commit="$(git rev-parse HEAD)"
task_output="$(pwd)/build/oneshop-${task_revision}.tar.gz"
task_staging="$(mktemp -d /tmp/oneshop-release.XXXXXXXX)"
trap 'rm -rf "$task_staging"' EXIT
mkdir -p build

git archive --format=tar HEAD | tar -xf - -C "$task_staging"
(
    cd "$task_staging"
    test -s composer.lock
    test -s package-lock.json
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
    php -r '
        $metadata = ["revision" => $argv[1], "built_at_utc" => gmdate("c"),
            "composer_lock_sha256" => hash_file("sha256", "composer.lock"),
            "package_lock_sha256" => hash_file("sha256", "package-lock.json")];
        file_put_contents("release.json", json_encode($metadata, JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR)."\n");
    ' "$task_commit"
    rm -rf node_modules
    tar --exclude='./.env' --exclude='./auth.json' --exclude='./.github' --exclude='./tests' -czf "$task_output" .
)
(
    cd "$(dirname "$task_output")"
    task_archive_name="$(basename "$task_output")"
    sha256sum "$task_archive_name" > "$task_archive_name.sha256"
    sha256sum --check "$task_archive_name.sha256"
)
printf 'Release archive: %s\n' "$task_output"
