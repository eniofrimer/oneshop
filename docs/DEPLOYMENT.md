# OneShop deployment targets

Docker, cPanel and FastPanel are mandatory deployment targets (user requirement, 29 October 2026). The same application and committed dependency locks are used across targets. A passing application test does not certify a particular hosting account, DNS, SSL, cron configuration or provider permissions.

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

This Compose image is for development. Production Docker delivery still needs an immutable image, TLS termination, secrets, worker supervision and backup/restore acceptance; a development Compose success alone is not a production deployment.

## Native release

Build on a machine with Git, PHP 8.5, Composer and Node 24:

```sh
bash scripts/build-release.sh
```

This packages the committed HEAD (commit changes before building), installs production Composer dependencies, builds frontend assets and exercises cached routes/config/views without development packages. Generated configuration and views are removed again before packaging. The result is `build/oneshop-<commit>.tar.gz`, also produced by the MariaDB CI job. It contains `vendor` and built assets, so Node and Composer are not needed at HTTP runtime. It contains no installation `.env`, application key, database, administrator credentials or private downloads.

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

The native example enables `ONESHOP_QUEUE_VIA_SCHEDULER=true`. The scheduler drains the database queue, stops when empty and avoids overlapping scheduled workers. If a supervised `queue:work database` service is configured instead, set that option to false. Jobs must have bounded external-request timeouts; shared-hosting cron limits and real job execution need checking before live checkout.

5. Verify the actual HTTPS login, cookie settings, public assets, private-file protection, scheduled jobs, mail, backup and restore on the target host. `oneshop:check-runtime` performs read-only application prerequisite checks, returns failure for missing prerequisites and does not claim to inspect a hosting panel or prove that cron/web PHP is configured.

A host limited to older PHP, MySQL-only infrastructure without the tested MariaDB/PostgreSQL engines, or no executable scheduled work does not yet meet this application's deployment requirements. Moving existing merchant data between database engines is a separate migration project; this work tests fresh installs, not cross-engine data conversion.

## Current execution environments

The agent execution environment reports hostname `codex`, fixed IP `192.168.250.2/24` on `eth1`, and contains this repository. Internet access retains its default route through `eth0`; neither shared networking nor firewall settings were changed by the project. It has PHP 8.4 globally and a project-private PHP 8.5 runtime under ignored `storage/app/private/runtime`; it has no Docker daemon. It is not independently verified as the requested SSH host alias `codex-ubuntu`.

The user terminal reports hostname `WEB-DEV`, IP `172.23.228.190`, and a working Docker CE 29.9.2 / Compose 5.6.1 after the user's update. The user's directory test showed no `/home/ubuntu/code/oneshop` on WEB-DEV. Direct SSH from the agent environment to that address timed out. Docker installation on WEB-DEV is user-confirmed; application deployment and a real owner account there are not yet verified.

## Latest verification and development preview

[CI 40918117207](https://github.com/eniofrimer/oneshop/actions/runs/40918117207), commit `a25b208`, passed both Docker-based jobs: PostgreSQL 18 and MariaDB 10.11 each ran 48 tests / 289 assertions, Pint, Larastan, audit, frontend build, migrations and both native target checks. The MariaDB job built and smoke-tested the no-dev native release, saved as `native-release-a25b208ad5a8ce196b07104360d95b0f98f3a27a`. This verifies the application profiles; no claim is made that an actual cPanel/FastPanel account has been installed or tested.

At the user's request, the active preview runs in `codex` using MariaDB, at [http://192.168.250.2:8080/admin/login](http://192.168.250.2:8080/admin/login). HTTP listens on the fixed VM address. `APP_URL` matches it; no custom domain was configured. `/admin` and `/admin/products` redirect to that same login URL, which returns HTTP 200; `/up` returns 200 and the disabled `/login` returns 404. These checks were performed from the VM only; Windows reachability is not independently verified. The previous forwarded localhost URLs should not be reused.

The real owner account `admin@oneshop.local` was provisioned, and its password login plus authenticated dashboard/catalog HTTP requests were previously verified without an MFA redirect. Credentials were handed over in chat and are not included here. Preview data is in the ignored `storage/app/private/preview-database`; test resets use the separate `oneshop_test` database. MariaDB listens only on this project's Unix socket, independently of system/other-project databases. Runtime and database files now survive `/tmp` cleanup; the development HTTP/database processes have not been installed as automatically restarting OS services. This is a development preview, not a production hosting installation.
