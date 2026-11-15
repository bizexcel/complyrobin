---
paths:
  - tests/**
---

# Testing

## Pest against real Postgres
Tests use Pest and the `complyrobin_test` database: migrations and truncation run as `app_migrator`, the app runs as `app_tenant` so row-level security is exercised. Never switch tests to SQLite, and don't use `RefreshDatabase`/`LazilyRefreshDatabase`: `Tests\TestCase` uses `DatabaseTruncation` because the tenant and platform connections are separate sessions that must see each other's committed rows.

## Create data inside a tenant
Factories for tenant models run inside `Tenancy::run($organisation, fn () => Model::factory()->create())`. Organisations come from `Organisation::factory()`, or from `CreateOrganisation` when the test needs an owner who is a member. Memberships are only visible to the signed-in user (row-level security on `app.user_id`), so call `$this->actingAs($user)` before reading `$user->organisations()`.

## Isolation is tested for every tenant model
New tenant models are picked up automatically by `SchemaConventionsTest`; add read/write cross-tenant cases for new resources. Billing tests use recorded Airwallex fixtures with `Http::preventStrayRequests()`.

## Arch tests discover classes from the classmap
After adding classes, `composer dump-autoload` before running arch tests.
