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
Cast enums to backed PHP enums and annotate enum-cast properties with `@property` for Larastan. Human-facing numbers (`reference`) come from the `Numbering` service, never from the id.

## No business logic
Models hold relations, casts and small accessors. Rules and writes with meaning live in `Actions`.
