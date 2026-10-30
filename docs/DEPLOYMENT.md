# OneShop deployment targets

Docker, cPanel and FastPanel are mandatory deployment targets (user requirement, 29 October 2026). The same application and committed dependency locks are used across targets. A passing application test does not certify a particular hosting account, DNS, SSL, cron configuration or provider permissions.

A separate manual [recovery rehearsal](RECOVERY_REHEARSAL.md) exercises synthetic database/media/key restoration on both database engines. It does not install a production backup schedule or establish the merchant's RPO/RTO.

## Supported profiles

| Target | PHP / database | Sessions, cache and jobs | Verification |
| --- | --- | --- | --- |
| Docker development | PHP 8.5, PostgreSQL 18, Redis 8 | Redis; optional development Horizon | Compose application tests, build, audit and migration CI |
| cPanel | PHP 8.5 CLI and web PHP, MariaDB 10.11+ (or reachable PostgreSQL 18+) | Database queue/cache/sessions; once-per-minute cron | Both SQL engines tested; actual account deployment remains an acceptance gate |
| FastPanel | Same native application requirements | Same; supervised queue workers are also possible | Same; actual server deployment remains an acceptance gate |

PHP needs BCMath, cURL, DOM/XML, Exif, Fileinfo, GD with JPEG/PNG/WebP support, Intl, Mbstring, OpenSSL, PDO and the selected database's PDO driver. Enable uploads with `upload_max_filesize` at least 10M and `post_max_size` at least 12M; configure the HTTP server/proxy to accept the same request size. Docker already uses 64M/72M. Use 512M PHP memory for catalog image processing. Match these settings in the actual web PHP profile as well as CLI; a CLI check alone cannot verify a hosting panel's web PHP configuration. `composer check-platform-reqs --no-dev` checks the locked production dependency requirements. PHP 8.5 must be selected for both HTTP and CLI/cron, using the host's actual binary path. Do not ignore Composer platform requirements to force installation.

FastPanel documents PHP 8.5 availability in [its application catalog](https://kb.fastpanel.direct/applications/) and a [Laravel deployment procedure](https://kb.fastpanel.direct/sites/installing-laravel/). Availability on the selected server still needs checking. cPanel's [cron documentation](https://docs.cpanel.net/cpanel/advanced/cron-jobs/) explains that the provider controls access to cron jobs and that commands need absolute paths.

Redis, Horizon, a Node daemon and Docker are not prerequisites for native hosting. Horizon is a development dependency; the production release boots without it and without mandatory PCNTL/POSIX Composer requirements. The standard Laravel database queue is used for the native profile. PostgreSQL search extensions are opt-in (`ONESHOP_SEARCH_EXTENSIONS=false` by default); current catalog screens do not require extension-creation privileges. Search engine parity will be tested when storefront search is implemented.

## Docker: first run

On the Ubuntu machine that actually hosts the checkout:

```sh
cd /home/ubuntu/code/oneshop
sudo bash scripts/dev-up.sh --create-owner
```

The script builds the image, starts isolated data services, installs the locks, applies migrations, initializes base shop records, builds assets and checks runtime prerequisites. `--create-owner` explicitly creates `admin@oneshop.local`, grants all application abilities, exempts that account from required MFA, and prints a newly generated password once. It never promotes or overwrites an existing account. Repeat without `--create-owner` to start/update the development instance without changing credentials. Existing APP_KEY and `.env` are preserved.

The admin URL is `http://localhost:8080/admin` on that Ubuntu host. Docker publishes it only on host loopback. From Windows, use an SSH tunnel to the verified Ubuntu host, for example:

```powershell
ssh -N -L 8080:127.0.0.1:8080 ubuntu@VERIFIED_UBUNTU_IP
```

Then open `http://localhost:8080/admin` in the Windows browser. The tunnel does not expose or mount Windows directories. If 8080 is occupied, use another local tunnel port, e.g. `-L 8081:127.0.0.1:8080`, and browse that port. Do not replace the verified host with an assumed SSH alias.

The default Compose image is for development. The separate immutable release profile below supplies locked images, secrets and workers. Actual TLS/proxy setup and backup/restore acceptance still belong to the chosen host; a development Compose success alone is not a production deployment.

## Native release

Build on a machine with Git, PHP 8.5, Composer and Node 24:

```sh
bash scripts/build-release.sh
```

This packages the committed HEAD (commit changes before building), installs production Composer dependencies, builds frontend assets and exercises cached routes/config/views without development packages. Generated configuration and views are removed again before packaging. The result is `build/oneshop-<commit>.tar.gz`, also produced by the MariaDB CI job. It contains `vendor` and built assets, so Node and Composer are not needed at HTTP runtime. It contains no installation `.env`, application key, database, administrator credentials or private downloads.

The artifact also includes `oneshop-<commit>.tar.gz.sha256`. Keep both downloaded files together and run `sha256sum --check oneshop-<commit>.tar.gz.sha256` before extracting. The archive's `release.json` records its full source revision, UTC build time and both dependency-lock hashes. These identify the packaged code and verify downloaded bytes; installation secrets remain separate.

[CI 40973879255](https://github.com/eniofrimer/oneshop/actions/runs/40973879255), commit `b53f772`, passed both engines and built this package. The 22,469,928-byte archive was downloaded and its sidecar, full revision and both lock hashes were independently verified. Croatian language files and the favicon are present; installation `.env`, tests, Node modules, Horizon and Faker are absent. This confirms packaging, with actual panel installation still pending.

For cPanel or FastPanel:

1. Extract the release to the intended application directory as the site's own user. Keep the application root outside the public document root; set the site's document root to its `public` directory. Enable PHP 8.5 and the required extensions for both web requests and CLI.
2. Create an empty MariaDB database and a dedicated database user. Copy `deploy/hosting.env.example` to `.env`, fill in the real domain and database credentials, then configure mail. For PostgreSQL use `DB_CONNECTION=pgsql`, the server's host/port and a PostgreSQL user/database instead.
3. Run the following with the **actual PHP 8.5 binary** as the site user. Generate a key only for a new installation; preserve the existing key for an update.

```sh
/path/to/php85 artisan key:generate
/path/to/php85 artisan migrate --force
/path/to/php85 artisan oneshop:initialize
/path/to/php85 artisan storage:link --relative
/path/to/php85 artisan oneshop:check-runtime --target=cpanel --production
/path/to/php85 artisan optimize
/path/to/php85 artisan oneshop:create-admin --super-admin
```

Use `--target=fastpanel` on FastPanel. Storage and `bootstrap/cache` must be writable by the site user; no world-writable permissions are required. The public symlink exposes only the public media disk. Paid downloads stay outside `public`.

4. Add a cron entry with the correct PHP binary and application path:

```cron
* * * * * /path/to/php85 /absolute/path/to/oneshop/artisan schedule:run >> /absolute/path/to/oneshop/storage/logs/scheduler.log 2>&1
```

The native example enables `ONESHOP_QUEUE_VIA_SCHEDULER=true`. The scheduler drains the database queue, stops when empty and avoids overlapping scheduled workers. If a supervised `queue:work database` service is configured instead, set that option to false. The main CI also executes the scheduled database worker on PostgreSQL and MariaDB: test jobs must wait behind its overlap mutex, then execute in the separate scheduled process and leave no lock or failed job. This is a runtime check, not proof that a real panel cron is installed. Jobs must have bounded external-request timeouts; shared-hosting cron limits and real job execution need checking before live checkout.

5. Verify the actual HTTPS login, cookie settings, public assets, private-file protection, scheduled jobs, mail, backup and restore on the target host. `oneshop:check-runtime` performs read-only application prerequisite checks, returns failure for missing prerequisites and does not claim to inspect a hosting panel or prove that cron/web PHP is configured.

A host limited to older PHP, MySQL-only infrastructure without the tested MariaDB/PostgreSQL engines, or no executable scheduled work does not yet meet this application's deployment requirements. Moving existing merchant data between database engines is a separate migration project; this work tests fresh installs, not cross-engine data conversion.

## Current execution environments

The agent execution environment reports hostname `codex`, fixed IP `192.168.250.2/24` on `eth1`, and contains this repository. Internet access retains its default route through `eth0`; neither shared networking nor firewall settings were changed by the project. It has PHP 8.4 globally and a project-private PHP 8.5 runtime under ignored `storage/app/private/runtime`; it has no Docker daemon. It is not independently verified as the requested SSH host alias `codex-ubuntu`.

The user terminal reports hostname `WEB-DEV`, IP `172.23.228.190`, and a working Docker CE 29.9.2 / Compose 5.6.1 after the user's update. The user's directory test showed no `/home/ubuntu/code/oneshop` on WEB-DEV. Direct SSH from the agent environment to that address timed out. Docker installation on WEB-DEV is user-confirmed; application deployment and a real owner account there are not yet verified.

## Latest verification and development preview

[CI 40918117207](https://github.com/eniofrimer/oneshop/actions/runs/40918117207), commit `a25b208`, passed both Docker-based jobs: PostgreSQL 18 and MariaDB 10.11 each ran 48 tests / 289 assertions, Pint, Larastan, audit, frontend build, migrations and both native target checks. The MariaDB job built and smoke-tested the no-dev native release, saved as `native-release-a25b208ad5a8ce196b07104360d95b0f98f3a27a`. This verifies the application profiles; no claim is made that an actual cPanel/FastPanel account has been installed or tested.

At the user's request, the active preview runs in `codex` using MariaDB, at [http://192.168.250.2:8080/admin/login](http://192.168.250.2:8080/admin/login). HTTP listens on the fixed VM address. `APP_URL` matches it; no custom domain was configured. `/admin` and `/admin/products` redirect to that same login URL, which returns HTTP 200; `/up` returns 200 and the disabled `/login` returns 404. These checks were performed from the VM only; Windows reachability is not independently verified. The previous forwarded localhost URLs should not be reused.

The real owner account `admin@oneshop.local` was provisioned, and its password login plus authenticated dashboard/catalog HTTP requests were previously verified without an MFA redirect. Credentials were handed over in chat and are not included here. Preview data is in the ignored `storage/app/private/preview-database`; test resets use the separate `oneshop_test` database. MariaDB listens only on this project's Unix socket, independently of system/other-project databases. Runtime and database files now survive `/tmp` cleanup; the development HTTP/database/queue processes now run as enabled user systemd services (see the preview service section below). This is a development preview, not a production hosting installation.

## Immutable Docker release profile

`Dockerfile` now has `production-app` and `production-web` targets, selected by `compose.production.yaml`. Application source, locked production dependencies and built assets are part of the images. No source bind mount, Node runtime, Composer executable, development dependencies, database content, `.env`, default administrator or installation key is shipped. The web image contains public assets only and mounts only public media, read-only; private downloads belong to a separate volume shared only with application processes.

The profile uses MariaDB, database sessions/cache/jobs, a standard queue worker and scheduler. Code runs with a read-only root filesystem; runtime cache/view directories use temporary filesystems, while database, public media and private files persist in three named volumes. It binds HTTP to host loopback `127.0.0.1:8088` by default. TLS termination, a verified trusted-proxy setup and the real hostname are explicit deployment work; do not expose this loopback endpoint as an unverified live shop.

First installation on the intended **Ubuntu** host:

1. Copy `deploy/docker.production.env.example` to the ignored root `.env.production`. Set the final HTTPS `APP_URL`, immutable commit/image tag and intended loopback port. This file holds ordinary deployment configuration; secrets are separate.
2. Create three persistent secret files under the ignored `deploy/secrets` directory: `app_key` (`base64:` followed by 32 random bytes in Base64), `database_password`, and `database_root_password`. For a new empty installation only:

```sh
umask 077
mkdir -p deploy/secrets
printf 'base64:' > deploy/secrets/app_key
openssl rand -base64 32 >> deploy/secrets/app_key
openssl rand -hex 32 > deploy/secrets/database_password
openssl rand -hex 32 > deploy/secrets/database_root_password
chmod 700 deploy/secrets
chmod 444 deploy/secrets/app_key deploy/secrets/database_password deploy/secrets/database_root_password
```

The parent directory is private on the host; Compose grants each service only its declared secret files. File readability allows the app's non-root UID 1000 to read its bind-mounted secrets. Do not print the secret contents or send them to Git. **Do not repeat key/password generation on an existing installation.** Preserve these files with the database and private-file backups. Changing the secret file does not automatically change an existing MariaDB account's password.

3. Build, initialize and start this specific Compose project:

```sh
docker compose --env-file .env.production -f compose.production.yaml config --quiet
docker compose --env-file .env.production -f compose.production.yaml build app web
docker compose --env-file .env.production -f compose.production.yaml up --detach --wait database
docker compose --env-file .env.production -f compose.production.yaml run --rm app php artisan migrate --force
docker compose --env-file .env.production -f compose.production.yaml run --rm app php artisan oneshop:initialize
docker compose --env-file .env.production -f compose.production.yaml run --rm app php artisan oneshop:check-runtime --target=docker --production
docker compose --env-file .env.production -f compose.production.yaml run --rm app php artisan oneshop:create-admin --super-admin
docker compose --env-file .env.production -f compose.production.yaml up --detach --wait app web worker scheduler
```

No installation/migration/key generation runs automatically in the application entrypoint. Updates require a backup and the same persisted key/data; building an image does not migrate a live database. Do not use `down --volumes` against a real installation: it destroys the project's named data volumes. The cleanup in CI applies only to its isolated disposable project.

The new image workflow builds both targets and checks a fresh isolated deployment, HTTP routes, public/private media separation, no default user, disabled production simulator and absence of Node/Composer/Horizon. This VM has no Docker daemon, so local YAML/shell validation is not an executed container verification. Record a successful workflow before accepting the image profile; actual host/TLS/mail/backup acceptance remains separate. The application still has a development payment simulator, not a live PSP integration.

Implementation references: [Docker multi-stage builds](https://docs.docker.com/build/building/multi-stage/), [persistent volumes](https://docs.docker.com/engine/storage/volumes/) and [Compose secrets](https://docs.docker.com/compose/how-tos/use-secrets/).

### Executed immutable-image verification

[Production image smoke test 40953742866](https://github.com/eniofrimer/oneshop/actions/runs/40953742866), commit `36f5499`, passed in full: both images built, fresh MariaDB initialized, production settings/readiness checked, source read-only, no default user/Horizon/Node/Composer, isolated public/private volumes, web/worker/scheduler started, and real HTTP checks passed for storefront/login/health, disabled simulator and blocked private/PHP-upload paths. [Application CI 40953742964](https://github.com/eniofrimer/oneshop/actions/runs/40953742964) is also fully green on PostgreSQL and MariaDB, including the no-dev native release. These are executed CI container checks; TLS termination, a real cPanel/FastPanel account, mail and backup restoration are not claimed as tested.

## Supervised preview in this Ubuntu VM

Only this project is managed by the three user services `oneshop-preview-database`, `oneshop-preview-http` and `oneshop-preview-worker`. Source unit files are versioned in `deploy/systemd`; installed copies are under `/home/ubuntu/.config/systemd/user`. The existing user manager and `Linger=yes` were verified, not newly enabled by the agent. The services are active and enabled for the user's default target, use the durable project-private PHP/database paths and retain `http://192.168.250.2:8080/`. They do not change shared networking/firewall configuration or other projects.

```sh
systemctl --user status oneshop-preview-database oneshop-preview-http oneshop-preview-worker
systemctl --user restart oneshop-preview-http
systemctl --user restart oneshop-preview-worker
```

Database restarts use its own `oneshop-preview-database` unit. A controlled HTTP/database restart was actually performed: the same cart session survived, catalog/order/transaction/stock data were preserved, and the home/login/health routes returned 200. A full VM reboot was not performed. The PHP built-in server remains a development preview; production hosting uses the immutable Docker or native web server profile.

`php artisan oneshop:probe-queue --wait=30` is an **explicit writing probe** separate from the read-only readiness command. It dispatches one job without merchant data and waits for a real asynchronous worker to place a short-lived marker in shared cache, then removes the marker. Sync queues are rejected. It succeeds in this VM with the actual supervised database worker. For a native once-per-minute cron worker, use `--wait=90` while testing the configured cron. A timeout is a failure and identifies queue/worker/shared-cache configuration for investigation; it does not certify execution. The probe does not send email, contact payment providers or change orders.

The real target's HTTPS/proxy addresses remain a deployment acceptance step. Configure only the verified proxy and headers as described in [Laravel's trusted-proxy documentation](https://laravel.com/framework/docs/13.x/requests#configuring-trusted-proxies); no global trust-all setting was enabled for this project. The preview and target-host checks must not be described as a Windows installation or filesystem-isolation test.

Docker CLI note: `docker compose run ... app php artisan ...` runs the secret-loading entrypoint. A plain `docker compose exec app php artisan ...` bypasses that entrypoint and does not inherit secrets exported by the already running FPM process. For commands inside an existing container, use `docker compose ... exec -T app oneshop-production-entrypoint php artisan ...`. The extended queue smoke test exposed this distinction; its invocation now uses the same entrypoint as web and workers.

The extended immutable-image test passed [run 40958016174](https://github.com/eniofrimer/oneshop/actions/runs/40958016174), commit `7c27ee3`: a real queue probe ran before and after a controlled container restart, and both public/private volume probes survived. Internal order PDF rendering uses `storage/framework/cache/dompdf` as its font/temp workspace, so no write to source/vendor is required; the image workflow also exercises that renderer after this increment.
