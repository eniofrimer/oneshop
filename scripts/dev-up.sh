#!/usr/bin/env bash
set -euo pipefail
cd "$(dirname "$0")/.."

if ! command -v docker >/dev/null || ! docker compose version >/dev/null; then
    echo 'Docker Engine and the Compose plugin are required.' >&2
    exit 1
fi
if ! docker info >/dev/null 2>&1; then
    echo 'Cannot access Docker. Run this script with sudo or with an account permitted to use Docker.' >&2
    exit 1
fi
if [[ "${1:-}" != '' && "${1:-}" != '--create-owner' ]]; then
    echo 'Usage: bash scripts/dev-up.sh [--create-owner]' >&2
    exit 1
fi

export LOCAL_UID="${SUDO_UID:-$(id -u)}"
export LOCAL_GID="${SUDO_GID:-$(id -g)}"
if [[ ! -f .env ]]; then
    cp .env.example .env
    if [[ -n "${SUDO_UID:-}" ]]; then
        chown "$LOCAL_UID:$LOCAL_GID" .env
    fi
fi

docker compose config --quiet
docker compose build app
docker compose up --detach --wait postgres redis
docker compose run --rm --no-deps -T app composer install --prefer-dist --no-interaction
if ! grep -Eq '^APP_KEY=.+$' .env; then
    docker compose run --rm --no-deps -T app php artisan key:generate
fi
docker compose run --rm --no-deps -T app php artisan migrate --force
docker compose run --rm --no-deps -T app php artisan oneshop:initialize
docker compose run --rm --no-deps -T app php artisan storage:link --relative
docker compose run --rm --no-deps -T app npm ci --ignore-scripts
docker compose run --rm --no-deps -T app npm run build
docker compose up --detach --wait app nginx
docker compose run --rm --no-deps -T app php artisan oneshop:check-runtime --target=docker

if [[ "${1:-}" == '--create-owner' ]]; then
    docker compose run --rm --no-deps -T app php artisan oneshop:create-admin --super-admin \
        --generate-password --name='OneShop Super Admin' --email=admin@oneshop.local --no-interaction
fi
printf '\nAdmin URL: http://localhost:%s/admin\n' "${ONESHOP_HTTP_PORT:-8080}"
echo 'The port is bound to VM localhost. From another machine, use an SSH tunnel to this VM.'
