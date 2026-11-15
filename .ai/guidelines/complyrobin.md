# ComplyRobin project guideline

ComplyRobin is a multi-tenant SaaS by Bizexcel. It starts as a SaaS credentials register (which tokens and keys a team relies on, who owns them, and when they are due for rotation, with reminders), then adds ISO management systems on the same data: BCMS (ISO 22301) and ISMS (ISO 27001:2022), with ERM/GHG later. Credentials are information only: never store a secret value. Stack: PHP 8.5, Laravel 13, Filament 5, Postgres 17 (Laravel Cloud Serverless Postgres in production), Pest 5. Design: `docs/superpowers/specs/2026-11-15-complyrobin-design.md`.

## Layers
- `app/Core` — tenancy, organisations, identity, people, entitlements, files, PDF, audit, notifications.
- `app/Grc` — the shared GRC engine (documents, approvals, objectives, context, risk, evaluations, audits, reviews, incidents, non-conformities, tasks), parameterised by framework. Never depends on `app/Modules`.
- `app/Modules/{Credentials,Bcms,Isms}` — product modules: the credentials register, and framework-only features (BIA, strategies, plans, exercises / assets, threats, controls, treatment, SoA).
- `app/Billing` — Airwallex billing that mirrors Laravel Cashier's API and file layout.
- `app/Filament/{App,Admin}` — the only place Filament code lives. The customer panel is at `/{organisation-slug}`, the staff panel at `/admin`.

## Non-negotiables
- **Tenant isolation.** Every tenant-owned table is created with `Schema::tenantTable()` and every tenant model uses `BelongsToOrganisation`. Work for an organisation runs inside `Tenancy::run($organisation, fn () => …)`; cross-tenant platform work uses `Tenancy::bypass('reason', fn () => …)`. Never query another organisation's rows, never trust a posted id without scoping it to the current organisation.
- **Actions own writes.** Business rules and writes live in `Actions` classes; Filament resources call them. Domain code never imports `Filament\*` (only `User` implements Filament's `FilamentUser`).
- **Ids.** UUIDv7 primary keys (`HasUuids`), never auto-increment, never shown to users — show the human `reference` (e.g. `INC-2026-0007`).
- **Database roles.** The app runs as `app_tenant` (row-level security enforced). Migrations run as the owner: `php artisan migrate --database=pgsql_migrator`.
- **Billing.** Write billing code exactly as Laravel Cashier would, with `stripe` replaced by `airwallex`.

## Project rules
Before planning or editing a file, read `.ai/rules/index.md` and follow the rule file whose globs match it. Change a convention → update its rule in the same change.

## Commands
`composer lint` (Pint) · `composer analyse` (Larastan) · `composer refactor` (Rector dry run) · `composer test` (Pest, against the `complyrobin_test` database).
