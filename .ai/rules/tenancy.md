---
paths:
  - app/Core/Tenancy/**
  - app/**/Jobs/**
  - app/Console/**
  - routes/console.php
---

# Tenancy

## Use the Tenancy facade
`Tenancy::run($organisation, fn () => …)` for work on behalf of one organisation; `Tenancy::id()` to read it. The id is mirrored into the Postgres setting `app.organisation_id`, which row-level security checks.

## Bypass is rare and explained
`Tenancy::bypass('reason', fn () => …)` is allowed for console commands; the staff panel will be allowed via `Tenancy::allowBypassUsing()` when it is wired (Phase 1). It switches to the `pgsql_platform` connection, logs the reason, and refuses to run inside an open tenant transaction. `Tenancy::run()` inside a bypass leaves the bypass and runs scoped — use that to loop over organisations. Never bypass to "make a query work".

## The database follows PHP
`Tenancy::set()` writes the database first, then PHP; a transaction rollback re-applies the PHP tenant. Don't call `set_config('app.organisation_id', …)` anywhere else.

## Jobs and schedules carry the tenant
Queued jobs restore the tenant from Laravel `Context`. Scheduled work loops over organisations and runs each inside `Tenancy::run()`.

## Tests prove isolation
Any change here keeps `tests/Feature/Tenancy` green; add a case for every new way data can cross tenants.
