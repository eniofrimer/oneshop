# OneShop

Managed, repeatable storefront application. Each customer receives an independent installation and domain.

## Current status

Initial Laravel 13 / Filament 5 foundation and a bounded Lunar core integration proof. This is development code, not a production shop. The checkout, customer screens, invoicing, shipping adapters and themes are subsequent milestones.

- PHP 8.5, Laravel ^13.39, Filament ^5.11 and Livewire 4.
- Lunar core ^1.5 only; no legacy Lunar administration package.
- Our independent Filament panel at /admin with required native authenticator MFA.
- PostgreSQL 18, Redis 8 and optional Horizon/scheduler development services.
- Real PostgreSQL integration tests, dependency audit, Pint and Larastan in CI.
- Exact installed dependencies are recorded in lock files after the first successful resolution.

See [the work plan](docs/PLAN.md) and [the review decisions](docs/CLAUDE_REVIEW.md).

## Development

Docker Engine and Compose v2 are required. Run commands from the repository root.

Copy the example environment:

```sh
cp .env.example .env
```

On Linux, use your account's UID and GID for writable bind mounts:

```sh
export LOCAL_UID=$(id -u)
export LOCAL_GID=$(id -g)
```

Build PHP and start the isolated data services:

```sh
docker compose build app
docker compose up -d --wait postgres redis
```

Install locked dependencies. On the initial bootstrap, when composer.lock is not yet present, resolve once using composer update, review and commit the resulting lock file:

```sh
docker compose run --rm app composer install
```

Prepare the development application and build frontend assets:

```sh
docker compose run --rm app php artisan key:generate
docker compose run --rm app php artisan migrate
docker compose run --rm app npm install --ignore-scripts
docker compose run --rm app npm run build
docker compose up -d app nginx
```

Open http://localhost:8080. There are no default administrator credentials. Provision a new administrator interactively:

```sh
docker compose run --rm app php artisan oneshop:create-admin
```

Sign in at /admin and enroll an authenticator. Customer authentication screens will be added in their own milestone.

## Tests

Tests run against the separate oneshop_test database; they never use the development oneshop database. A fresh development PostgreSQL volume creates both databases. Do not delete an existing data volume to create the test database.

```sh
docker compose run --rm app php artisan test
docker compose run --rm app vendor/bin/pint --test
docker compose run --rm app vendor/bin/phpstan analyse --memory-limit=1G
```

For a pre-existing development volume, create the missing test database explicitly:

```sh
docker compose exec postgres createdb -U oneshop -O oneshop oneshop_test
```

Optional development workers:

```sh
docker compose --profile workers up -d horizon scheduler
```

The Compose file is for local development and CI. Production deployment, resource limits, external credentials, backup RPO/RTO and recovery procedures remain a separate milestone.

## Source and licensing

The application bootstrap is derived from laravel/laravel at commit 9ffef3bc4241e3cc333ef267258a404d0d95f194. Laravel's MIT notice is preserved in THIRD_PARTY_NOTICES.md. Third-party packages retain their own licenses; the application is not automatically licensed under Laravel's license.
