---
paths:
  - app/**/Models/**
---

# Models

## Keys and tenancy
Every model uses `HasUuids`. A model stored in a tenant table also uses `BelongsToOrganisation`, which scopes queries to the current organisation (throwing when none is set) and stamps `organisation_id` on create. Enforced by `ArchTest` and `SchemaConventionsTest`.

## organisation_id is never mass assignable
Never list `organisation_id` in `#[Fillable]`; the trait sets it, and refuses updates or deletes of rows from another organisation and any change of `organisation_id`. Likewise never make privilege or ownership columns mass assignable (`platform_role`, `parent_id`/`root_id` on organisations once billing reads them). Use `#[Fillable([...])]`, `#[Hidden]` and `#[UseFactory(...)]` attributes.

## Casts and references
Cast enums to backed PHP enums and annotate enum-cast properties with `@property` for Larastan. Human-facing numbers (`reference`) come from `App\Core\Numbering\Numbering::next($series, $prefix, restartsYearly: …)`, never from the id: one atomic upsert per number into `counters`, so references never repeat, and yearly series restart in the organisation's time zone. Store the result in a `reference` column with `unique(organisation_id, reference)`.

## Every tenant model is audited
Tenant models also use `App\Core\Audit\Concerns\LogsTenantActivity`, which writes each create, update and delete (who, and each attribute's old and new value) to the organisation's `activities` trail. Hidden and `encrypted` attributes are never written, so mark secrets one or the other. The trail is append-only for the app; `audit:prune` (scheduled daily) removes entries after `activitylog.clean_after_days` (730). Enforced by `ArchTest`.

## No business logic
Models hold relations, casts and small accessors. Rules and writes with meaning live in `Actions`.
