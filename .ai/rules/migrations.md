---
paths:
  - database/migrations/**
---

# Migrations

## Use uuid primary keys
Every table uses `$table->uuid('id')->primary()` and `foreignUuid()` for references. Exceptions are Laravel's own `jobs`, `job_batches`, `failed_jobs`, `cache`, `cache_locks`, `sessions`, `password_reset_tokens` and spatie's `roles`/`permissions`. Enforced by `SchemaConventionsTest` ("uses uuid for every id column").

## Create tenant tables with Schema::tenantTable()
Any table holding one organisation's data is created with `Schema::tenantTable('name', fn (Blueprint $table) => …)`. It adds `id`, `organisation_id`, `unique(organisation_id, id)`, ENABLE + FORCE row level security and the `{table}_tenant_isolation` policy. Don't add `id`/`organisation_id` yourself. Enforced by `SchemaConventionsTest`.

## Reference other tenant tables with tenantForeign()
A foreign key from one tenant table to another uses `$table->uuid('department_id'); $table->tenantForeign('department_id', 'departments');` so the database rejects cross-tenant links. Never `set null` on a composite key (it would null `organisation_id`); use `restrict` or `cascade`.

## Self-references after the primary key
Laravel adds a column's `->primary()` after explicit foreign keys, so a table that references itself (and isn't a tenant table) adds that foreign key in a following `Schema::table()` call.

## Types
Use `timestampsTz()`, `softDeletesTz()`, `timestampTz()`. Store enums as strings and cast them to PHP enums; never database enums. Morphs that point at our models use `uuidMorphs()` / `nullableUuidMorphs()`.

## Run as the owner
Migrations run as `app_migrator`: `php artisan migrate --database=pgsql_migrator`. The app role cannot create tables.
