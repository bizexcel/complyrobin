# ComplyRobin

**The bird that reminds you on time.** ComplyRobin keeps track of the SaaS tokens and keys your business runs on: who owns each one, where it is used, when it was last rotated and when it is due. Reminders arrive by email, in-app or your calendar, and, once switched on, Slack, Teams or Telegram, and every rotation is logged as evidence. The same register then feeds ISO management systems: **BCMS** (ISO 22301) and **ISMS** (ISO 27001:2022).

ComplyRobin stores information *about* credentials, never the secret values.

- **Stack:** PHP 8.5, Laravel 13, Filament 5, Postgres 17, Pest 5.
- **Hosting:** Laravel Cloud, Singapore. The app runs at app.complyrobin.com; the marketing site at complyrobin.com.
- **Design:** [docs/superpowers/specs/2026-11-15-complyrobin-design.md](docs/superpowers/specs/2026-11-15-complyrobin-design.md)

## Docs
- **[About ComplyRobin](docs/about.md):** what it does, its plans, and how it is built.
- **[Local setup](docs/local-setup.md):** install, sign in, run the queue and scheduler, and test.
- **[Deploying to Laravel Cloud](docs/laravel-cloud.md):** Postgres and its logins, Valkey, storage, queue, scheduler, mail, environment variables, and the build and deploy commands.

Quick start:

```bash
docker compose up -d pgsql && cp .env.example .env && composer install && php artisan key:generate
```

```bash
php artisan migrate --database=pgsql_migrator && php artisan db:seed --database=pgsql_migrator && npm install && npm run build
```

```bash
composer dev
```

Then sign in at http://localhost:8000/login as `test@example.com`, password `password`. The email code is in `storage/logs/laravel.log`.

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
