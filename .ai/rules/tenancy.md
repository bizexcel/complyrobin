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
`Tenancy::bypass('reason', fn () => …)` is allowed for console commands and for staff-panel requests: `App\Filament\Admin\Middleware\RunAsPlatform` (persistent auth middleware) runs each signed-in staff member's request inside a bypass and allows it only for that request. It switches to the `pgsql_platform` connection, logs the reason, and refuses to run inside an open tenant transaction. `Tenancy::run()` inside a bypass leaves the bypass and runs scoped — use that to loop over organisations. Never bypass to "make a query work".

## The database follows PHP
`Tenancy::set()` writes the database first, then PHP; a transaction rollback or reconnect re-applies what PHP holds. The signed-in user is mirrored the same way into `app.user_id` (set from Laravel's `Authenticated` event, cleared on `Logout`), so policies can let users read their own rows, such as their memberships in `organisation_user`. Don't call `set_config(...)` anywhere else.

## Requests run inside the organisation
In the customer panel, Filament identifies the organisation from the slug and checks membership (404 otherwise), then the persistent tenant middleware `App\Filament\App\Tenancy\SetCurrentOrganisation` wraps the request in `Tenancy::run()`. Don't set the tenant from controllers or components.

## Jobs and schedules carry the tenant
`Tenancy::set()` puts the organisation in Laravel's hidden `Context`, so every job dispatched inside an organisation runs as it on the worker, and it is cleared after each job. Dispatch as a statement (`Job::dispatch();`), not as an arrow function's return value: a `PendingDispatch` only dispatches when it is destroyed, by which time `Tenancy::run()` may have ended. Scheduled work uses `Tenancy::each(fn (Organisation $organisation) => …)`, which runs the callback inside each organisation in turn.

## Models never pin a connection
Every app model uses `UsesTenancyConnection` (tenant models get it through `BelongsToOrganisation`), so the connection follows `Tenancy`: the tenant role normally, the platform role inside `Tenancy::bypass()`. Never use `Model::on()` or `setConnection()` to reach other organisations. Enforced by `ArchTest`.

## Organisations change only from inside or from the platform
Row-level security would silently ignore a change to another organisation, so `Organisation` refuses updates from outside it, and refuses deletes except inside `Tenancy::bypass()`. Audit entries written during a bypass go to the trail of the organisation the record belongs to.

## Tests prove isolation
Any change here keeps `tests/Feature/Tenancy` green; add a case for every new way data can cross tenants.
