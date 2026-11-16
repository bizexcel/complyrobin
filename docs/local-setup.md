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
| `app_tenant` | `tenant` | the app (`DB_USERNAME`); row-level security applies |
| `app_platform` | `platform` | staff work across organisations (`DB_PLATFORM_*`) |
| `app_migrator` | `migrator` | owns the tables; migrations and seeders (`DB_MIGRATOR_*`) |

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

The seeder adds the permissions, the vendor catalogue and a test user.

## 3. Run it
```bash
composer dev
```

This starts the web server (http://localhost:8000), a queue listener, a log viewer and Vite.

The queue matters: evidence reports, CSV imports and reminder delivery run as queued jobs.

- **Sign in** at http://localhost:8000/login as `test@example.com`, password `password`.
- **Codes and links:** every sign-in needs a second factor. The test user gets an email code, and locally mail goes to the log (`MAIL_MAILER=log`). Find the code in `storage/logs/laravel.log`, or in the log pane of `composer dev`. Invitation and verification emails land there too.
- **First sign-in:** sign up or sign in, then create an organisation. You'll land on `/{your-slug}`.
- **Staff panel:** it is at http://localhost:8000/admin and needs `platform_role = admin`. Give it to yourself with:

```bash
php artisan tinker --execute 'App\Core\Identity\Models\User::where("email", "test@example.com")->first()->forceFill(["platform_role" => "admin"])->save();'
```

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

## Tests and checks
Tests run against a real Postgres database, `complyrobin_test`, never SQLite. What they protect only exists in Postgres:
- the row-level security that keeps organisations apart;
- the three logins;
- foreign keys that include the organisation;
- the computed due-date column;
- the "send once" key on reminders.

The tests use their own database, so your local data is never touched.

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
composer test       # Pest, against complyrobin_test
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
- **"permission denied for schema public"** when migrating: add `--database=pgsql_migrator`.
- **"Unable to locate file in Vite manifest":** run `npm run build`, or keep `composer dev` running.
- **The roles are missing** (an old Postgres volume): re-apply them with:

```bash
docker compose exec -T pgsql psql -U postgres -d complyrobin -v migrator_password=migrator -v tenant_password=tenant -v platform_password=platform -f /sql/roles.sql
```

  Then do the same for `complyrobin_test`.
