---
paths:
  - database/migrations/**
---

# Migrations

## Use uuid primary keys
Every table uses `$table->uuid('id')->primary()` and `foreignUuid()` for references; every `*_id` column is uuid. Only Laravel's own tables keep their id type: `jobs`, `job_batches`, `failed_jobs`, `cache`, `cache_locks`, `sessions`, `password_reset_tokens`, `migrations`. Filament's importer and exporter tables aren't used: spreadsheet imports are in-house (`StartCredentialImport` → `ImportCredentials`, each row through `CreateCredential`), because Filament's failed-rows download binds its `Import` record outside any organisation. If they are ever adopted, they become tenant tables with uuid ids. spatie's roles, permissions, activity log and media use uuid ids through our own models. Morph, `user_id` and `organisation_id` columns in those tables are still uuid. Enforced by `SchemaConventionsTest` ("uses uuid for every id and *_id column"); the list lives in `packageTables()` there.

## Name things the Laravel way
Enforced by `SchemaConventionsTest`.
- **Tables** are plural snake_case (`credentials`, `people`). Pivots are the two singular names in alphabetical order (`organisation_user`).
- **Foreign keys** are `{relation}_id`, named after the Eloquent relation: `owner()` → `owner_id`, `rotatedBy()` → `rotated_by_id`.
- **Timestamps** are `*_at` and `timestamptz`; **plain dates** are `*_on`. Only `jobs`/`job_batches` keep Laravel's Unix integers.
- **Booleans** start with `is_` or `has_`.
- **No derivable columns** (status computed from dates, "was it on time"), except a stored generated column (`->storedAs(...)`) when a query or index needs the value, as with `credentials.next_rotation_at`: the database computes it, so no write path can leave it stale. Its expression must be immutable (do date arithmetic `at time zone 'UTC'`) and **no columns for features that haven't shipped**. Don't add `created_by`/`updated_by`: the activity log records who changed what. Add a business actor column (`rotated_by_id`, `invited_by_id`) only when the product shows it.

## Create tenant tables with Schema::tenantTable()
Any table holding one organisation's data is created with `Schema::tenantTable('name', fn (Blueprint $table) => …)`. It adds `id`, `organisation_id`, `unique(organisation_id, id)`, ENABLE + FORCE row level security and the `{table}_tenant_isolation` policy. Don't add `id`/`organisation_id` yourself. Enforced by `SchemaConventionsTest`.

## Reference other tenant tables with tenantForeign()
A foreign key from one tenant table to another uses `$table->uuid('department_id'); $table->tenantForeign('department_id', 'departments');` so the database rejects cross-tenant links. Never `set null` on a composite key (it would null `organisation_id`); use `restrict` or `cascade`.

## Self-references after the primary key
Laravel adds a column's `->primary()` after explicit foreign keys, so a table that references itself (and isn't a tenant table) adds that foreign key in a following `Schema::table()` call.

## Types
Use `timestampsTz()`, `softDeletesTz()`, `timestampTz()`. Store enums as strings and cast them to PHP enums; never database enums. Morphs that point at our models use `uuidMorphs()` / `nullableUuidMorphs()`.

## Run as the owner
Migrations run as `app_migrator`: `php artisan migrate --database=pgsql_migrator`. The app role cannot create tables. The flag picks a Postgres login, not an engine: ComplyRobin is Postgres only, and `config/database.php` holds just the three role connections (`DatabaseConnectionsTest`).
