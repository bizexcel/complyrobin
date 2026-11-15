---
paths:
  - tests/**
---

# Testing

## Pest against real Postgres
Tests use Pest and the `complyrobin_test` database: migrations and truncation run as `app_migrator`, the app runs as `app_tenant` so row-level security is exercised. Never switch tests to SQLite, and don't use `RefreshDatabase`/`LazilyRefreshDatabase`: `Tests\TestCase` uses `DatabaseTruncation` because the tenant and platform connections are separate sessions that must see each other's committed rows.

## Create data inside a tenant
Factories for tenant models run inside `Tenancy::run($organisation, fn () => Model::factory()->create())`. Organisations come from `Organisation::factory()`, or from `CreateOrganisation` when the test needs an owner who is a member. Memberships are only visible to the signed-in user (row-level security on `app.user_id`), so call `$this->actingAs($user)` before reading `$user->organisations()`. `User::factory()` users have email MFA switched on, as every real user must; use `->withoutMultiFactorAuthentication()` to test the set-up redirect.

## Isolation is tested for every tenant table
`IsolationSuiteTest` checks every table with an `organisation_id`: another organisation sees none of its rows and can change or delete none, and nothing is visible without an organisation. Its coverage test fails until `populatedOrganisation()` puts a row in a new tenant table, so add one (through the real action where there is one) in the same change. Add cross-tenant cases for new resources and actions too. Billing tests use recorded Airwallex fixtures with `Http::preventStrayRequests()`.

## Arch tests discover classes from the classmap
After adding classes, `composer dump-autoload` before running arch tests.
