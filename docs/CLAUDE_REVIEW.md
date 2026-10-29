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
