# ComplyRobin

**The bird that reminds you on time.** ComplyRobin keeps track of the SaaS tokens and keys your business runs on: who owns each one, where it is used, when it was last rotated and when it is due. Reminders arrive by email, in-app or your calendar, and, once switched on, Slack, Teams or Telegram, and every rotation is logged as evidence. The same register then feeds ISO management systems: **BCMS** (ISO 22301) and **ISMS** (ISO 27001:2022).

ComplyRobin stores information *about* credentials, never the secret values.

- **Stack:** PHP 8.5, Laravel 13, Filament 5, Postgres 17, Pest 5.
- **Hosting:** Laravel Cloud, Singapore. The app runs at app.complyrobin.com; the marketing site at complyrobin.com.
- **Design:** [docs/superpowers/specs/2026-11-15-complyrobin-design.md](docs/superpowers/specs/2026-11-15-complyrobin-design.md)

## Local setup
You need PHP 8.5 (for example via Herd, or `docker compose run --rm php …`), Composer and Docker.

```bash
docker compose up -d pgsql   # Postgres 17 with the complyrobin and complyrobin_test databases and the three roles
cp .env.example .env
composer install             # also publishes Filament assets
php artisan key:generate
php artisan migrate --database=pgsql_migrator
php artisan db:seed --database=pgsql_migrator
php artisan serve
```

- **Customer panel:** http://localhost:8000/login
- **Staff panel:** http://localhost:8000/admin. Your user needs `platform_role = admin`.

## Deploying
Reminders go by email (set a mail service, e.g. Resend through Laravel Cloud), in-app and the calendar link. Team chat channels stay off until you list them in `CHAT_CHANNELS` (e.g. `slack,teams`); Telegram also needs `TELEGRAM_BOT_TOKEN`.

**Database logins on Laravel Cloud.** Cloud injects the database owner's login as `DB_USERNAME`/`DB_PASSWORD`. Use it once to apply `database/sql/roles.sql` (it creates `app_migrator`, `app_tenant` and `app_platform`), then set `DB_USERNAME`/`DB_PASSWORD` to `app_tenant`, `DB_PLATFORM_USERNAME`/`DB_PLATFORM_PASSWORD` to `app_platform` and `DB_MIGRATOR_USERNAME`/`DB_MIGRATOR_PASSWORD` to `app_migrator`. Never run the app as the owner: it bypasses row-level security.

**Connection pooling.** Set `DB_POOLING=true`: the app and workers then go through Cloud's pooler, and migrations use the direct endpoint automatically. Tenancy keeps nothing in the database session, so pooling is safe (`PooledConnectionTest`). `DB_POOLING=false` sends everything direct. Never set `DB_DIRECT_HOST` on Cloud.

After migrating as the owner, seed the code-defined permissions and vendor catalogue, then sync system roles (all idempotent):

```bash
php artisan migrate --database=pgsql_migrator --force
php artisan db:seed --class=PermissionSeeder --database=pgsql_migrator --force
php artisan db:seed --class=VendorSeeder --database=pgsql_migrator --force
php artisan access:sync-system-roles
```

## Database roles
ComplyRobin runs on Postgres only. One database, three logins: `--database=pgsql_migrator` on migrate and seed picks the owner login, not a database engine. The app's own login can't create or change tables, which is what lets Postgres refuse to show one organisation's rows to another. Forgetting the flag fails safely with "permission denied".

`database/sql/roles.sql` is idempotent and is applied by the compose init script, by CI, and by deploys.

| Role | Used by | Row-level security |
|---|---|---|
| `app_migrator` | migrations and seeders (`--database=pgsql_migrator`) | owner, bypasses |
| `app_tenant` | the application (`pgsql`, the default connection) | **enforced** |
| `app_platform` | staff panel and `Tenancy::bypass()` (`pgsql_platform`) | bypasses |

Every tenant table is created with `Schema::tenantTable()`, which forces RLS on `organisation_id = current_setting('app.organisation_id')`. Wrap tenant work in `Tenancy::run($organisation, fn () => …)`.

## Quality gates (CI runs all of these)
```bash
composer lint       # Pint
composer analyse    # Larastan level 6
composer refactor   # Rector dry run
composer test       # Pest, against complyrobin_test
composer audit
```

## Working with AI agents
Laravel Boost is installed.
- Project rules are in `.ai/rules` (indexed in `.ai/rules/index.md`).
- The project guideline is in `.ai/guidelines`.
- Skills are in `.ai/skills`.
- After changing them, run `php artisan boost:index-rules` and `php artisan boost:update`.

© Bizexcel. All rights reserved.
