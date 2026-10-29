# Review of Claude's stack proposal

Date: 29 October 2026. Source reviewed: WEBSHOP-STACK.md supplied by the user.

## Decisions

| Proposal | Decision | Reason / implementation |
| --- | --- | --- |
| Current stable Laravel/Filament, independent client installations | Accept | One codebase, isolated configuration and data per installation; no tenancy layer. |
| Caret constraints and committed lock files | Accept | Stable-only resolution, minimum current framework versions, reviewed updates through CI. Locks are required before production. |
| Replace Lunar with a complete new commerce core | Reject for now | Stable Lunar core has no Filament/Livewire dependency. A full rewrite adds pricing, tax, discount, inventory and refund risk without an evidenced blocker. First run a bounded integration proof; do not patch vendor code. |
| Use current Stripe/PayPal SDKs | Accept | Own integration adapters around current stable SDKs; not Lunar's older Stripe driver. Adapter implementation follows the core proof. |
| filament/spatie-laravel-translatable-plugin ^5.10 | Reject | That package has no Filament 5 release. Use native locale tabs. Lunar owns catalog translations; Spatie Translatable is available for our own content models. |
| Media/settings plugins | Accept | Official Filament 5 integrations. Paid downloads use private storage separately from public galleries. |
| Activitylog 5 and no Scout | Amend | Lunar core 1.5 requires Activitylog 4 and Scout 10. Supported compatible branches do not force an older Laravel or Filament. |
| Spatie Sluggable plus separate historical slug table | Defer | Select one owner of localized slug generation and redirects; avoid two competing mechanisms. Sitemap will use approved database URLs, not crawling filters. |
| Unidentified private HR-payments package | Reject as a dependency | No package name, repository or version was supplied. Keep a local bank-transfer/HUB3 boundary; choose the renderer after the private package, if it exists, is inspected. |
| Business checkbox creates B2B price groups/net prices | Reject as assumed scope | The requested checkbox supplies invoice details. Wholesale prices, terms, tiers, Monri, COD and VIES are optional unapproved extensions. |
| VIES valid automatically means zero VAT | Reject | Tax depends on the actual transaction context, not just a valid identifier. Obtain the merchant/accountant's approved rules before adding cross-border automation. |
| Final amounts as integer minor units | Accept with precise intermediate calculations | Rounding and discount allocation require explicit policy and exact arithmetic at the chosen boundary; do not round net unit prices prematurely. The Lunar proof is not a certification of every rounding case. |
| Single generated search_vector using unaccent() | Reject as written | Generated expressions require IMMUTABLE functions; unaccent is STABLE. Use a rebuildable per-product/per-locale search projection and explicit update/rebuild handling. |
| Never ILIKE / GIN everywhere | Amend | Use bounded, parameterized, escaped queries and measured indexes. Exact/prefix SKU lookup is distinct from translated full-text search. |
| COLLATE C handles identifiers | Amend | Byte comparison is not normalization. Define SKU casing, slug normalization and unique scopes explicitly. |
| Every migration must be transactional | Amend | Transactions are the default; operations such as CREATE INDEX CONCURRENTLY require an explicit nontransactional migration. |
| Deduct/restore stock on pending order | Amend | Reservation, expiry and payment share a locking policy. A payment arriving after expiry needs re-reservation or a visible exception/refund workflow. |
| Webhook event key guarantees idempotency | Amend | Persist an inbox scoped to provider/account/environment; handle out-of-order events and make business operations idempotent. |
| document_requests ensures no remote duplicate invoices | Amend | A timed-out remote success is an unknown outcome. Use provider idempotency or reconcile by our operation reference before issuing again. |
| Docker nginx/PHP-FPM/PostgreSQL/Redis | Accept for development | One PHP image for app/workers; isolated test database, readiness checks, no published DB/Redis ports. Production needs a separate immutable image and deployment/recovery review. |
| Nightly dump/media backup alone | Reject as the final production policy | It permits almost 24 hours of lost orders. Agree RPO/RTO, encrypted offsite retention, restore testing and PITR/WAL where required. |

## First implementation milestone

Completed: official Laravel skeleton, own Filament admin and development runtime, dependency resolution, migrations and a real guest cart/order flow on PostgreSQL. Integration tests cover admin authorization, MFA enrollment, historical snapshots and a single-use coupon retry.

This does not yet certify stock concurrency, late-payment handling, all VAT/rounding cases, real gateway settlement or document-provider retries. Those are explicit acceptance gates before production checkout.

## Executed foundation proof

CI run [40909337424](https://github.com/eniofrimer/oneshop/actions/runs/40909337424) on `080c6a9` passed all 29 tests (155 assertions), PostgreSQL 18 migrations, dependency audit, frontend build and Larastan level 6. Pint formatting failures are corrected in the following change, with both CI-resolved lock files committed. Lunar core remains the implementation foundation; no vendor changes were needed for these scenarios. The locked install passed every check in [CI 40910199122](https://github.com/eniofrimer/oneshop/actions/runs/40910199122), commit `51b5b99`. Phase 2 is accepted.

## Updated user requirements

Docker, cPanel and FastPanel are mandatory deployment targets. The native profile supports MariaDB 10.11+ with database-backed jobs, cache and sessions; PostgreSQL 18 remains the Docker baseline. Horizon is a development dependency rather than a mandatory production extension/process requirement. CI now covers both SQL engines and a production dependency release; actual hosting accounts still need acceptance testing.

Explicitly provisioned super administrators have all application abilities and no required MFA, as requested by the user. Other administrators retain required MFA. There are no hardcoded credentials or automatic promotion of existing accounts. The MFA exemption runs in request middleware, so route caching does not freeze an authentication-dependent route configuration.

## Package policy

- minimum-stability stable; no unbounded latest/beta dependencies.
- No legacy Lunar admin or table-rate-shipping package.
- Current SDK candidates: Stripe ^22.0.1, PayPal Server SDK ^2.4.
- Activitylog ^4.12.3 and Scout ^10 remain under Lunar's constraints.
- Settings ^3.10, Translatable ^6.15, Model States ^2.14.2, Sitemap ^8.2, CSV ^9.28.
- Brick Money ^0.15.2; a caret on 0.15 does not authorize a breaking jump to 0.16.
- Framework and all transitive requirements must pass the joint Composer solve and platform checks; published constraints alone are not runtime proof.

## Primary references

- https://github.com/lunarphp/core/blob/1.5.0/composer.json
- https://packagist.org/packages/filament/spatie-laravel-translatable-plugin
- https://filamentphp.com/plugins/lara-zeus-spatie-translatable
- https://www.postgresql.org/docs/18/ddl-generated-columns.html
- https://github.com/postgres/postgres/blob/REL_18_STABLE/contrib/unaccent/unaccent--1.1.sql
- https://www.postgresql.org/docs/18/sql-createindex.html
- https://www.postgresql.org/docs/18/pgtrgm.html
- https://github.com/brick/money
- https://docs.stripe.com/webhooks
- https://docs.stripe.com/api/idempotent_requests
- https://europa.eu/youreurope/business/finance-and-tax/vat/cross-border-vat/
- https://www.postgresql.org/docs/18/continuous-archiving.html

The expanded proof passed [CI 40918117207](https://github.com/eniofrimer/oneshop/actions/runs/40918117207): 48 tests / 289 assertions on each database, all checks green, native no-dev release built and cached routes/config/views verified without Horizon. Actual preview superadmin password login was separately verified over HTTP/Livewire, and the user confirmed the login page opens.

## Catalog increment verification

Translated reusable options and exact SKU combinations passed both database profiles in [CI 40938704955](https://github.com/eniofrimer/oneshop/actions/runs/40938704955), commit `ea18ca4`. The product row is locked during variant writes and option assignment; duplicate combinations and values from other options are refused. Drafts can be incomplete during preparation; publishing validation remains a separate task. Dropdown/radio metadata is a presentation preference, not a delivered storefront or multi-select add-on system.

Public gallery operations are scoped to the product's image collection on the public disk, with authorization in the service as well as the UI. Paid/private collections are excluded. Image count, file size, decoded dimensions and MIME types are bounded; uploaded filenames are generated. Nine MariaDB integration tests exercise uploads, actual GD conversions, cover ordering, deletion, metadata preservation, forged IDs and rejected files. This is not an implementation of protected paid downloads.

The public gallery passed both database jobs, formatting/static analysis and native release checks in [CI 40939734291](https://github.com/eniofrimer/oneshop/actions/runs/40939734291), commit `555c4d8`.

Manual category trees serialize nested-set changes using their group row, reject cycles and cross-group moves, and preserve automatic collection membership and existing pivot positions during product edits. Tests exercise the real group-creation modal, category/product forms and tree consistency. A narrowly scoped PHPStan stub supplies the missing generic model parameter of Lunar's installed nested-set query builder; it changes analysis metadata only, not vendor/runtime behavior.

Category administration passed both SQL engines and native release checks in [CI 40941110110](https://github.com/eniofrimer/oneshop/actions/runs/40941110110), commit `2c095d8` (79 local MariaDB tests / 506 assertions).

Placed-order administration is read-only. It shows stored order addresses/line descriptions and raw integer monetary snapshots, formats amounts with exact decimal arithmetic and the order currency, and never falls back silently to the current shop currency. Unknown currencies are shown explicitly as minor units. Draft carts, payment payload metadata and mutation actions are excluded. HTTP/Livewire tests cover history preservation after catalog/profile changes, transaction scope, MFA/customer denial, revoked permissions, invalid record IDs and absent write routes. No payment settlement or refund behavior is certified by these screens.

Read-only orders passed [CI 40942470845](https://github.com/eniofrimer/oneshop/actions/runs/40942470845), commit `9bfa272`, on both database profiles (87 local tests / 570 assertions).

Private digital asset preparation now uses immutable per-upload records, a restricted private path, content hashes, archive/restore and authorized administrator attachment downloads with integrity checks. It does not grant customer entitlements. Auditing omits private paths and hashes. Tests cover real Filament transfer, changed/missing content, compensation after a failed insert, immutable identity, permissions and archived SKU history. The hosting prerequisite check now includes PHP upload limits and the public-only storage link; web PHP still needs separate acceptance on the actual panel.

An incremental preview migration exposed Lunar's optional legacy brand-file read: its missing-file handling assumes the default local disk returns null. Only that local disk was restored to Laravel's nonthrowing default; the separate private paid-file disk remains strict. Regression tests cover repeated initialized-shop migrations, a real legacy import and strict missing-file behavior on the private disk. No vendor code was changed and the preview database was not reset.

CI `40944308033` passed all application tests on both engines but failed the hosting check because Laravel relative symlinks require `symfony/filesystem`. The missing production dependency is now explicitly required and locked. A real relative storage link and native runtime check pass locally; no existing dependency versions were changed. The full CI must be repeated before accepting this increment.

Controlled catalog publication validates saved product/SKU data and requires explicit channel/customer-group selections. It rejects ambiguous/missing base prices, unsupported pack quantities, missing/duplicate option combinations and digital SKUs without an active stored asset. Selected audience pivots are enabled immediately; other existing pivots are disabled and retained. Withdrawal preserves catalog prices, stock and historical records. Tests cover the actual Filament publish/withdraw actions, visible rejection notification, authorization, digital prerequisites and audience scope. This is not acceptance of a live checkout or customer download entitlement flow.

[CI 40945240774](https://github.com/eniofrimer/oneshop/actions/runs/40945240774), commit `ba627d8`, is fully green on both databases with the explicit filesystem dependency and native release checks. [CI 40946211341](https://github.com/eniofrimer/oneshop/actions/runs/40946211341), commit `b842f9c`, also passed controlled publication (115 local tests / 740 assertions).

SKU soft archive/restore retains prices, options, image associations, private files and historical order lines. Only drafts can change the archive; restore refuses an already active option combination. Tests exercise the actual table/archive filter and prove that archived options remain available when removing the product's active option assignments.

## Javni katalog i gostujuca kosarica (29. 10. navecer)

Provjereni su razvojni seed bez prepisivanja zaliha, varijabilna ponuda, dodavanje/promjena/uklanjanje, cijene iz baze, odvojeni administratorski identitet, odbijanje tudjih stavki i povucenih/nescheduled/skrivenih proizvoda, granice kolicina i nedostatak cijene. Popravljen je i `purchasable` pivot pri objavi ne-zadanih grupa. Javni prikaz koristi escaped Blade vrijednosti i samo javne fotografije; demo SVG-ovi su staticki resursi. Za jutro ostaje Astra pregled produkcijske naplate/zaliha; razvojni MVP ne oznacavati live-ready.

## Dostava (29. 10. navecer)

Uvedena je vlastita tablica/servis i Filament resurs s konfigurabilnim drzavama, cijelim EUR iznosom i poreznom klasom. Odabrana metoda ponovno se razrjesava iz baze; request manifest uklanja samo prethodne OneShop opcije kako stara drzava, iskljucena metoda ili drugi cart ne bi naslijedili ponudu. Lunar pipeline cuva povijesnu dostavnu stavku. Nema integracije s kurirskom sluzbom ni potvrdjenih produkcijskih tarifa. Demo dostava 5 EUR s testnim porezom je local/testing fixture.

## Razvojni checkout i centralna povijest placanja

Simulator je namjerno zaseban local/testing tok iza `ONESHOP_DEMO_CHECKOUT`, sa server-side guardom i CSRF-om. Nema API poziva procesoru, stvarnog novca ni prava na digitalne datoteke. Testovi pokrivaju cijeli HTTP put, ponovljenu predaju/naplatu, odbijanje i retry, sesijsku pripadnost, promijenjene cijene/tarife, nedostatak zalihe, otkazivanje/istek te odbijanje production okoline i uz ukljucenu zastavicu. Uspjeh je transakcijski i jednom umanjuje testnu zalihu; ovo nije zamjena za produkcijsku rezervaciju i webhook poravnanje.

Centralna administracija Placanja je read-only i prikazuje samo transakcije predanih narudzbi. Ne prikazuje sirovi metadata payload, karticne podatke ni slobodne napomene procesora. Testne narudzbe su oznacene. Korisnik je izricito odgodio dijelove za koje je preporucena Astra; ne predstavljati ovu simulaciju kao provjerenu Stripe/PayPal integraciju ili live-ready trgovinu.

Lokalno: 146 testova / 1161 assertions, Pint i Larastan prolaze; Vite build i native runtime check prolaze. Stvarni VM HTTP tok s cookiejem/CSRF-om zavrsio je jednom demo transakcijom od 45 EUR i po jednim umanjenjem obaju SKU-ova. Test pregleda Placanja otkrio je Filamentovu injekciju parametra po imenu (`state`); callback je ispravljen i stvarne stranice ponovno prolaze.

CI `40952762310` za checkout prosao je sve provjere i 146 testova na MariaDB-u; PostgreSQL je prosao nove checkout/payment testove, ali otkrio nestabilan redoslijed postojeceg option_values mappiranja u SKU obrascu. Dodan je izriciti ORDER BY po opciji/vrijednosti za ponovljiv rezultat na oba enginea. Native no-dev paket izgradjen je u MariaDB jobu. Ponovna puna provjera slijedi na ispravku.
