# Local setup

## What you need
- **PHP 8.5** with the `pdo_pgsql`, `pgsql`, `intl`, `gd`, `zip`, `bcmath`, `exif` and `pcntl` extensions. [Herd](https://herd.laravel.com) works, or use the Docker image in `docker/php`.
- **Composer 2** and **Node.js 22** (for `npm` and Vite).
- **Docker**, for Postgres 17 and, optionally, PgBouncer.

ComplyRobin runs on Postgres only. There is no SQLite or MySQL option.

## 1. Start Postgres
```bash
docker compose up -d pgsql
```

On its first start, the container creates two databases:
- `complyrobin` for the app;
- `complyrobin_test` for the tests.

It also applies `database/sql/roles.sql`, which creates three logins (the passwords are for local use only):

| Login | Password | Used by |
|---|---|---|
| `app_tenant` | `tenant` | the app (`DB_TENANT_*`); row-level security applies |
| `app_platform` | `platform` | staff work across organisations (`DB_PLATFORM_*`) |
| `app_migrator` | `migrator` | owns the tables; migrations and seeders (`DB_MIGRATOR_*`) |

`DB_USERNAME`/`DB_PASSWORD` are the database owner (`postgres` locally). Only `php artisan db:roles` uses them, to create or update these logins, which the container has already done for you.

## 2. Install and migrate
```bash
cp .env.example .env
```

```bash
composer install
```

```bash
php artisan key:generate
```

```bash
php artisan migrate --database=pgsql_migrator
```

```bash
php artisan db:seed --database=pgsql_migrator
```

```bash
npm install && npm run build
```

`--database=pgsql_migrator` picks the **owner login**, not a different database: the app's own login can't create tables. If you forget the flag, the migration stops with "permission denied" and nothing changes.

The seeder adds the permissions, the vendor catalogue and, on your machine only (`APP_ENV=local`), a test user. It is safe to run again.

## 3. Run it
```bash
composer dev
```

This starts the web server (http://localhost:8000), a queue listener, a log viewer and Vite.

The queue matters: evidence reports, CSV imports and reminder delivery run as queued jobs.

- **Sign in** at http://localhost:8000/login as `test@example.com`, password `password`.
- **Codes and links:** every sign-in needs a second factor. The test user gets an email code, and locally mail goes to the log (`MAIL_MAILER=log`). Find the line "Your sign-in code is: …" in `storage/logs/laravel.log`, or in the log pane of `composer dev`. Type it in and the form continues by itself. Invitation and verification emails land there too.
- **The code is sent by a queued job,** so it only appears while the queue is running. `composer dev` runs it. If you start the app with `php artisan serve` alone, also run `php artisan queue:work` in a second terminal.
- **First sign-in:** after the code you are asked to create an organisation (`/new`). Give it a name and you land on its dashboard at `/{your-slug}`.
- **Staff panel:** it is at http://localhost:8000/admin and needs a staff role. Make the test user an Admin with:

```bash
php artisan staff:grant test@example.com
```

  Its **Staff** page adds other staff (Admin or Support). Support can look but not change anything.

- **Team features:** to try Team features locally, open the staff panel, go to Organisations, and use **Grant module** on your organisation: Credentials gives Team; pick Enterprise as the plan for Enterprise.

### Reminders and other scheduled work
`composer dev` doesn't run the scheduler. To run it:

```bash
php artisan schedule:work
```

Or send reminders once, straight away:

```bash
php artisan credentials:send-reminders
```

Reminders only go out between 09:00 and 18:00 in each organisation's time zone.

### Optional settings
| Setting | What it does |
|---|---|
| `CHAT_CHANNELS=slack,teams,telegram` | Offers team chat channels (empty by default: email, in-app and calendar only). |
| `TELEGRAM_BOT_TOKEN` | Needed as well for Telegram. |
| `MEDIA_DISK` | A separate private disk for files. Empty uses `FILESYSTEM_DISK`. |

Octane is installed but not needed locally: `composer dev` uses PHP's built-in server. See [Deploying to Laravel Cloud](laravel-cloud.md) for when it matters.

## Tests and checks
Tests run against a real Postgres database, `complyrobin_test`, never SQLite. What they protect only exists in Postgres:
- the row-level security that keeps organisations apart;
- the three logins;
- foreign keys that include the organisation;
- the computed due-date column;
- the "send once" key on reminders.

The tests use their own database, so your local data is never touched.

**How a run works.** You don't create anything by hand:
1. **The database:** the Postgres container made `complyrobin_test` and the three logins on its first start.
2. **The schema:** the first test in a run builds the tables as `app_migrator`.
3. **A clean start:** before every test, all tables are emptied and the permissions re-seeded. Each test creates the data it needs.
4. **Real isolation:** the app side runs as `app_tenant`, so row-level security is live during the tests.

**In parallel.** `composer test` runs the tests in parallel, one process per CPU core.
- Each process gets its own database (`complyrobin_test_test_1`, `_2`, …), created automatically. All three logins point at it.
- That takes the run from about 100 seconds to about 30.
- `app_migrator` needs permission to create those databases. New local containers get it automatically. On an older volume, grant it once:

```bash
docker compose exec pgsql psql -U postgres -c "ALTER ROLE app_migrator CREATEDB"
```

That permission is for local and CI only: never give it in production.

```bash
composer lint       # Pint
```

```bash
composer analyse    # Larastan
```

```bash
composer refactor   # Rector dry run
```

```bash
composer test       # Pest in parallel, against complyrobin_test_test_N
```

```bash
composer test:serial                                   # one process, against complyrobin_test (easier to debug)
```

```bash
vendor/bin/pest --dirty                                # only tests for files you changed
```

```bash
vendor/bin/pest tests/Feature/Credentials/RemindersTest.php   # one file
```

CI also runs the whole suite **through a connection pooler**: PgBouncer in transaction mode, as on Laravel Cloud with pooling switched on. To do the same locally:

```bash
docker compose up -d pgbouncer
```

```bash
DB_PORT=6432 DB_DIRECT_HOST=127.0.0.1 DB_DIRECT_PORT=5432 DB_EXPECT_POOLER=true composer test
```

Migrations still go direct to Postgres (`DB_DIRECT_*`). `PooledConnectionTest` only runs in this mode.

## Working with AI agents
- **Laravel Boost** is installed.
- **Rules:** project rules are in `.ai/rules`, indexed in `.ai/rules/index.md`.
- **Guideline and skills:** the guideline is in `.ai/guidelines` and skills are in `.ai/skills`.
- **After changing any of them**, run:

```bash
php artisan boost:index-rules
```

```bash
php artisan boost:update
```

## Troubleshooting
- **No sign-in code in the log:** the queue isn't running. Use `composer dev`, or run `php artisan queue:work` beside `php artisan serve`.
- **Port 5432 is already in use:** start Postgres on another port and tell the app:

```bash
FORWARD_DB_PORT=5433 docker compose up -d pgsql
```

  Then set `DB_PORT=5433` in `.env`.
- **"permission denied for schema public"** when migrating: add `--database=pgsql_migrator`.
- **"Unable to locate file in Vite manifest":** run `npm run build`, or keep `composer dev` running.
- **The roles are missing** (an old Postgres volume): re-apply them with:

```bash
docker compose exec -T pgsql psql -U postgres -d complyrobin -v migrator_password=migrator -v tenant_password=tenant -v platform_password=platform -f /sql/roles.sql
```

  Then do the same for `complyrobin_test`.
