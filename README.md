# OneShop

Managed, repeatable storefront application. Each customer receives an independent installation and domain.

## Current status

For current preview links, executed checks and the morning review scope, see [the development status](docs/STATUS.md).

For a practical Croatian walkthrough of products, variants and a test purchase, see [the administrator guide](docs/ADMIN_GUIDE.md).

Verified Laravel 13 / Filament 5 foundation with an independent catalog admin, public storefront, variable demo products, guest cart, configurable shipping and a development checkout/payment simulator. This is development code. Real Stripe/PayPal settlement, production stock reservations, customer accounts and invoicing remain pending. See [development checkout and its limits](docs/DEVELOPMENT_CHECKOUT.md).

- PHP 8.5, Laravel ^13.39, Filament ^5.11 and Livewire 4.
- Lunar core ^1.5 only; no legacy Lunar administration package.
- Our independent Filament panel at /admin; ordinary administrators require MFA, explicitly provisioned super administrators have all abilities and optional MFA.
- Docker with PostgreSQL 18 / Redis 8, plus a native cPanel/FastPanel profile with MariaDB 10.11+ and database-backed jobs/sessions/cache.
- Real PostgreSQL and MariaDB integration tests, dependency audit, Pint and Larastan in CI.
- Exact installed dependencies are committed in composer.lock and package-lock.json.

Docker, cPanel and FastPanel are required deployment targets. See [deployment instructions and verification limits](docs/DEPLOYMENT.md), [the work plan](docs/PLAN.md) and [the review decisions](docs/CLAUDE_REVIEW.md).

## Development

Docker Engine and the Compose plugin are required for the Docker profile. For cPanel/FastPanel use the native release described in [deployment instructions](docs/DEPLOYMENT.md).

For a fresh Docker checkout, `sudo bash scripts/dev-up.sh --create-owner` performs setup and prints a generated superadmin password once. The account is `admin@oneshop.local`, has all application abilities and does not require 2FA. This is explicit provisioning; no fixed password or default user is shipped. For manual setup, run the following from the repository root.

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

Install the committed dependency versions:

```sh
docker compose run --rm app composer install
```

Prepare the development application and build frontend assets:

```sh
docker compose run --rm app php artisan key:generate
docker compose run --rm app php artisan migrate
docker compose run --rm app php artisan oneshop:initialize
docker compose run --rm app php artisan storage:link --relative
docker compose run --rm app npm ci --ignore-scripts
docker compose run --rm app npm run build
docker compose up -d app nginx
```

Open http://localhost:8080. There are no default administrator credentials. Provision a new administrator interactively:

```sh
docker compose run --rm app php artisan oneshop:create-admin
```

Sign in at /admin and enroll an authenticator. Customer authentication screens will be added in their own milestone.

`oneshop:initialize` creates missing configured languages, EUR, a storefront channel and a retail customer group. It can be repeated without replacing merchant settings. Conflicting defaults stop the transaction for explicit resolution. It creates no administrator, sample products or tax rules. Configure `config/oneshop.php` before running it.

Unfinished Fortify routes are disabled. Customer and Google authentication must use a separate session guard before they are enabled, to preserve administrator MFA. Horizon is a development dependency and admits enrolled administrators or explicitly provisioned super administrators. Production native releases boot without Horizon.

## Catalog administration (phase 3, in progress)

After initialization and administrator MFA enrollment, open `/admin/products`. Create a catalog type and a draft product, enter names/descriptions for the explicitly enabled locales, then add SKUs with base EUR prices, stock, a tax class and physical-delivery requirements. Tax classes can be named here; tax rates and zones must be configured separately. Price labels reflect `LUNAR_STORE_INCLUSIVE_OF_TAX` (false by default).

SKU identifiers use uppercase ASCII letters, numbers, dots, hyphens and underscores. They are unique even across archived variants. The migration refuses existing case/whitespace duplicates instead of changing identifiers. Prices accept a comma or dot with up to two decimal places and are stored as integer cents. Stale stock edits are refused with a field error; reopen the variant to load current stock. Existing pack quantities require a later editor and are not silently repriced.

The SKU table shows the stored unit base EUR price with the same inclusive/exclusive tax label as the editor. Tier/group/other-currency prices are excluded. Missing or ambiguous base prices, unsupported currency precision and special pack quantities display a review message. Reading the table does not recalculate or change prices.

Prepare reusable options (for example, color and size) at `/admin/options`, with translated values and a preferred dropdown/radio presentation. Attach and order options on the product, then select one value per option when adding a SKU. Duplicate combinations are refused even with a different SKU. An option already used by SKUs cannot be removed, and adding options to a published product with SKUs is refused. Drafts may temporarily have incomplete combinations while their catalog is being prepared; publication validation will cover that transition.

The product's Fotografije tab manages public JPEG, PNG and WebP images, translated alternative text, ordering and deletion. The first image is the cover. Each image is limited to 5 MB and 4096 × 4096 pixels, with up to 10 images per product. Originals receive generated filenames; thumbnails use GD and the configured queue. Run the worker/scheduler to process conversions; originals remain available while queued thumbnails are pending. Images are public even for drafts. Paid downloads belong on the separate private disk and cannot be managed through this gallery.

Storefront cards use generated 500/800 px responsive images, and gallery thumbnails use the smaller conversion. The detail/gallery links retain the original. Pending conversions fall back to the public original; conversions stored on a private disk are excluded. Cards use the saved primary-language alternative text when provided.

Prepare manual category trees at `/admin/categories`. Create a named group and categories with translated names/descriptions, then select them on products. Categories can move within their original group; self/descendant parents and parents from other groups are rejected. Existing automatic collections and category product positions are preserved by product membership edits. Automatic category rules, category deletion, menu design and storefront visibility are later work.

Placed orders can be inspected at `/admin/orders`, with reference/status filters, stored addresses/totals, historical SKU descriptions and transaction records. Draft carts are excluded. Amounts are displayed from raw integer snapshots using the order currency and exact decimal arithmetic; unknown currencies are identified as minor units rather than silently using the store default. The screens expose no editing, capture, refund or deletion controls, and do not render arbitrary payment metadata. Operational changes and reconciliation belong to the checkout/payment milestone.

The payment history at `/admin/payments` filters successful/unsuccessful attempts using the stored outcome flag. Demo status labels are explicitly marked as test outcomes; other processors retain their recorded status. Payment detail and order history share the same labels, and a payment links directly to its placed order.

Private digital assets can be prepared at `/admin/digital-assets` for SKUs without physical shipping. Uploads are limited to 10 MB, stored outside `public`, assigned a SHA-256 checksum and kept as separate immutable records. Archive/restore retains their bytes; new uploads never replace existing assets. Administrators download through an authorized action as an attachment after an integrity check. Upload/archive operations are audited without logging private paths. This prepares files for the later paid-access system; customers receive no download entitlement or public file URL from an upload.

Use Objava u katalogu on a saved product to inspect readiness and explicitly choose channels and customer groups. Publication requires the primary-language name, at least one SKU, one base EUR price per SKU, supported unit quantities and complete unique option combinations. Digital SKUs also need an active private file present in storage. Multi-SKU products need distinguishing options. Selected audiences become available immediately; other existing audience pivots are disabled and retained. Vrati u nacrt withdraws the product without changing stock, prices or history. Both operations are audited. This changes catalog availability; the production storefront, checkout and paid download entitlements remain later milestones.

SKU archive/restore is available in the variant table while the product is a draft. The archive filter exposes stored variants; restoration refuses a combination already used by an active SKU. Soft archiving preserves prices, option values, image associations, private assets and order history. Archive the affected SKUs before removing their used product options; archived values remain intact.

The product list shows the first public gallery image as its small cover, with the saved primary-language alternative text and the prepared demo illustration as a fallback. Private media are excluded. It also has a read-only **Provjera** action showing saved publication prerequisites. It searches primary-language names and active SKUs, including drafts. SQL wildcard characters are treated literally. A product visible through the storefront channel/group scope has an **Otvori u trgovini** action on its edit page. Administrator avatars are served locally and do not depend on an external avatar service.

Finer staff permissions and the confirmed reference/import scope remain phase 3 work. Unchecking physical delivery alone does not provide a downloadable file. The editor preserves existing publication status, unmanaged attributes, disabled-language translations and tier prices.

The current VM preview uses the fixed address [http://192.168.250.2:8080/admin/login](http://192.168.250.2:8080/admin/login). This is specific to the development VM; the Docker instructions above retain their loopback defaults. Preview reachability has been checked from the VM, not independently from Windows.

## Tests

Feature tests use `RefreshDatabase` and rebuild the configured test database. Run them only in a disposable test installation with credentials restricted to `oneshop_test`. The test base class refuses cached configuration, a non-testing environment, another database name and the known VM preview socket. These checks are safeguards, not general database-server isolation. A fresh development PostgreSQL volume creates both databases; use a separate Compose project for tests when a working preview already uses that volume. Never point test overrides at the preview socket, and do not delete an existing development volume to create a test database.

```sh
docker compose run --rm -e APP_ENV=testing -e DB_DATABASE=oneshop_test app php scripts/assert-test-database.php
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

### Razvojna trgovina

Javni katalog: `http://192.168.250.2:8080/`; administracija: `http://192.168.250.2:8080/admin/login`. Katalog i gostujuca kosarica koriste istu bazu s vlastitim adminom. Za izricito popunjavanje prazne local/testing baze: `php artisan oneshop:demo-catalog` (3 proizvoda, jedan varijabilan, testni porez; ne prepisuje postojeci katalog). Produkcijska naplata ostaje neukljucena. Nastavljeni [Astra pregled](docs/PAYMENT_REVIEW.md) ispravlja potvrdu kupnje i konkurentno citanje kataloga, uz izvrsne provjere na obje baze.

Administracija **Dostava** omogucava rucne tarife po drzavama s poreznom klasom. `php artisan oneshop:demo-shipping` dodaje izricitu razvojnu HR tarifu nakon demo kataloga. Porez i dostava racunaju se u Lunar coreu; stvarne kurirske integracije nisu ukljucene.

Razvojni checkout (adresa, pregled, testna narudzba, simulator placanja) ukljucuje se izricito s `ONESHOP_DEMO_CHECKOUT=true` samo u local/testing okolini. Production ga uvijek odbija. Administracija **Placanja** prikazuje testne transakcije bez privatnih payloadova. Potpune upute i odgodjeni dijelovi: [docs/DEVELOPMENT_CHECKOUT.md](docs/DEVELOPMENT_CHECKOUT.md).

A separate immutable Docker image profile is available in `compose.production.yaml`, with locked application/public images, persistent data volumes and explicitly supplied installation secrets. See [deployment and acceptance checks](docs/DEPLOYMENT.md). Image packaging does not enable real payments or certify the target host.

The Ubuntu preview now runs under the project-only user services `oneshop-preview-database`, `oneshop-preview-http` and `oneshop-preview-worker`. Their controlled restart preserved the session and shop data. `php artisan oneshop:probe-queue` explicitly verifies a real background worker; the standard runtime check remains read-only. See [deployment details](docs/DEPLOYMENT.md).

The development storefront includes live name/SKU search, availability filtering, paginated results with empty-page recovery and a standard GET fallback without JavaScript. Input/page bounds, literal LIKE escaping and request throttling are implemented. This basic search is not acceptance of full-text search or large-catalog performance. A real Chromium journey in the Ubuntu VM passed desktop, mobile emulation and no-JavaScript checkout/payment simulation.

The repeatable browser journey is available as `npm run test:browser` with an explicit demo URL. It creates test orders and changes demo stock. The manually triggered **Browser journey** workflow runs it in a disposable Docker installation and retains synthetic screenshots. See [browser verification](docs/BROWSER_TESTING.md).

`npm run test:browser:cart` separately verifies two variants of one product in the same guest cart, their distinct accessible controls, independent quantity changes and removal. It changes its own demo cart without placing an order. The same workflow runs it across the prepared themes/engines; use only an isolated demo installation.

Four prepared storefront layouts are available through `ONESHOP_THEME`: `everyday`, `atelier`, `studio` and `market`. They share all commerce behaviour. See [theme selection and verification](docs/THEMES.md).

Croatian framework validation, authentication and pagination messages are supplied locally, including readable names for checkout/catalog fields. Additional storefront languages remain an explicit later choice.

A separate `npm run test:browser:admin` journey creates a product/SKU/photo through the actual admin UI, buys it as an independent guest and checks historical records, PDF download and stale-stock rejection. It requires explicit write acknowledgement and disposable credentials; see [browser verification](docs/BROWSER_TESTING.md).
