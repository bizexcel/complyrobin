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

## Every statement carries the tenant
`Tenancy::set()` only changes PHP. The tenant connection (`pgsql`, class `App\Core\Tenancy\TenantConnection`) starts every statement it sends with `TenantContext::statementPrefix()`: `set_config('app.organisation_id', …, true)` and `set_config('app.user_id', …, true)`, transaction-local, always sent even when empty. The signed-in user comes from Laravel's `Authenticated` event and is cleared on `Logout` (and with the request, see below), so policies can let users read their own rows, such as their memberships in `organisation_user`. Nothing is kept in the database session, which is what keeps organisations apart through a transaction-mode pooler (Laravel Cloud's, with `DB_POOLING=true`), where consecutive statements can run on server sessions shared with other clients.
- The prefix needs emulated prepares on the tenant connection (both its pooled and `::direct` variants); native prepares fail closed ("cannot insert multiple commands").
- Never keep anything in a database session: no `set_config(…, false)` or `SET` (role settings such as `search_path` go in `database/sql/roles.sql`), no advisory locks, `LISTEN`, temporary tables or `PDO::ATTR_PREFETCH`, and no raw `getPdo()` queries on the tenant connection (they skip the prefix and see nothing). Only `TenantContext` writes `set_config(` (`DatabaseConnectionsTest`).
- `StatementPrefixTest` pins how the driver behaves with the prefix; `PooledConnectionTest` proves isolation through PgBouncer (see `testing.md`).

## Nothing outlives a request or job
The app runs on PHP-FPM, which starts every request clean, but queue workers already keep the app in memory between jobs, and Octane would between requests. So no service of ours may carry state from one to the next:
- **Scoped, never singleton.** `TenantContext` and `JobTenancyStack` are bound with `scoped()`, which Laravel forgets between jobs on a worker and Octane forgets between requests. Bind any new service with `scoped()` (or not at all); `singleton()` is not used in `app/`.
- **Ask the container each time.** Long-lived objects never keep a `TenantContext`: `TenantConnection` asks for the current one before every statement, the `Tenancy` facade isn't cached, and listeners registered in a service provider use `app()`, not the provider's `$this->app` (Octane runs each request in a copy of the app).
- **No static or global request state:** no static properties that change at runtime, no `once()` on per-request values.
- **Octane is installed but switched off.** Production runs on PHP-FPM; `laravel/octane` is there so the app can be proved safe under it. `TenancyServiceProvider` drops the tenancy state whenever Octane hands over a request, task or tick, so nothing survives from the worker's boot or from a request that ended badly, and `permission.register_octane_reset_listener` is on.

`LongRunningProcessTest` proves each point for queue workers. `OctaneTest` sends real requests one after another through an Octane worker running the app (Octane's own request handling; only the web server is faked): a guest after a signed-in user, two organisations' members alternating, a staff request followed by a customer's. Add a case there for every new kind of per-request state. Before switching Octane on in Laravel Cloud, load-test it in a preview (see `docs/laravel-cloud.md`).

## Requests run inside the organisation
In the customer panel, Filament identifies the organisation from the slug and checks membership (404 otherwise), then the persistent tenant middleware `App\Filament\App\Tenancy\SetCurrentOrganisation` wraps the request in `Tenancy::run()`. Don't set the tenant from controllers or components. A streamed download (`response()->streamDownload()`) is written after the request's `Tenancy::run()` has ended, so its callback runs inside `Tenancy::run()` again (see `ExportAuditTrail`).

## Jobs and schedules carry the tenant
`Tenancy::set()` puts the organisation in Laravel's hidden `Context`, so every job dispatched inside an organisation runs as it on the worker, and it is cleared after each job. Dispatch as a statement (`Job::dispatch();`), not as an arrow function's return value: a `PendingDispatch` only dispatches when it is destroyed, by which time `Tenancy::run()` may have ended. After a job the organisation from before it comes back (none on a worker; the surrounding one for a job run synchronously inside `Tenancy::run()`, via `JobTenancyStack`). Scheduled work uses `Tenancy::each(fn (Organisation $organisation) => …)`, which runs the callback inside each organisation in turn. A scheduled command may first find the organisations it needs with one `Tenancy::bypass()` query (as `credentials:send-reminders` does), then `Tenancy::run()` each one inside `rescue()` so one failure never stops the rest.

## Models never pin a connection
Every app model uses `UsesTenancyConnection` (tenant models get it through `BelongsToOrganisation`), so the connection follows `Tenancy`: the tenant role normally, the platform role inside `Tenancy::bypass()`. Never use `Model::on()` or `setConnection()` to reach other organisations. Enforced by `ArchTest`.

## Organisations change only from inside or from the platform
Row-level security would silently ignore a change to another organisation, so `Organisation` refuses updates from outside it, and refuses deletes except inside `Tenancy::bypass()`. Audit entries written during a bypass go to the trail of the organisation the record belongs to.

## Tests prove isolation
Any change here keeps `tests/Feature/Tenancy` green; add a case for every new way data can cross tenants.

## Database logins
The app's own connection (`pgsql`) logs in with `DB_TENANT_USERNAME`/`DB_TENANT_PASSWORD`, never `DB_USERNAME`/`DB_PASSWORD`, and has no URL. On Laravel Cloud those two hold the injected database **owner**, which bypasses row-level security. They're read only into `database.owner`, for `php artisan db:roles`, which applies `database/sql/roles.sql` as the owner on every deploy, so each environment and each preview creates its own logins. `php artisan db:verify-roles` then fails the deploy if `pgsql` could bypass row-level security or own the data, or if the platform or migrator login couldn't do its job. Both run first in `php artisan app:deploy`.

