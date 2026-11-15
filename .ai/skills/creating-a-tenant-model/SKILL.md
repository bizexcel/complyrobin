---
name: creating-a-tenant-model
description: Add a new organisation-owned table and model (e.g. assets, incidents, risks) with tenant isolation, factory, policy, Filament resource and tests.
---

# Creating a tenant model

## When to use this skill
Use it whenever you add data that belongs to one organisation.

## Checklist
1. **Migration** — `Schema::tenantTable('things', function (Blueprint $table) { … })`; reference other tenant tables with `$table->uuid('x_id'); $table->tenantForeign('x_id', 'xs');`. Use `timestampsTz()` and `softDeletesTz()` where records are archived.
2. **Model** in `app/<Area>/Models` — `use BelongsToOrganisation, HasFactory, HasUuids, LogsTenantActivity;` (the audit trail; mark secrets `#[Hidden]` or `encrypted` and they are never logged), `#[Fillable([...])]` without `organisation_id`, `#[UseFactory(ThingFactory::class)]`, enum casts with `@property` annotations.
3. **Factory** in `database/factories` — no `organisation_id`; tests create inside `Tenancy::run($org, fn () => Thing::factory()->create())`.
4. **Reference number** if users talk about it — via the `Numbering` service.
5. **Policy** and **Actions** for every write.
6. **Filament resource** under `app/Filament/App` calling the actions, with scoped validation.
7. **Tests** — feature tests for the actions and resource; cross-tenant read/write cases. `SchemaConventionsTest` checks RLS, the unique key and the trait automatically.
8. Run `composer dump-autoload && composer test && composer analyse && composer lint`.
