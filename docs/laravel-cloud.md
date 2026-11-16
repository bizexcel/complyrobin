# Deploying to Laravel Cloud

This guide covers what to create in Laravel Cloud for ComplyRobin, what to set, and in which order. It was checked against the [Laravel Cloud docs](https://laravel.com/cloud/docs) on 2026-11-16. Cloud changes often, so if a screen looks different, the docs win.

## What you need
| Resource | What to pick | Why ComplyRobin needs it |
|---|---|---|
| **Application** | Repository `bizexcel/complyrobin`, branch `main`, region **Singapore (ap-southeast-1)**, PHP **8.5** | The app. Create a `production` environment, and a `staging` one to try changes first. |
| **App cluster** | Web compute, with the **Scheduler** toggle **on** | Serves `/{organisation}` and `/admin`, and runs the scheduled work (below). |
| **Serverless Postgres** | Version **17**, region Singapore | All data. Pick 17 to match local and CI. Moving to 18 means updating `compose.yaml` and CI first. |
| **Laravel Valkey** (Cache) | One cache | Cache, sessions, rate limits and locks. The scheduler's `onOneServer` locks need a shared cache. |
| **Object Storage** | One **private** bucket, attached as the **default disk** | Rotation evidence, evidence reports and spreadsheets waiting to be imported. A public bucket comes later, only when something public ships. |
| **Managed queue** | Flex to start | Evidence reports, CSV imports and reminder delivery run as queued jobs. |
| **Email** | Resend, with the verified domain `complyrobin.com` | Reminder emails, invitations, sign-in codes and email verification. |
| **Domain** | `app.complyrobin.com` | The app. The marketing site at `complyrobin.com` is separate. |
| **Nightwatch** (optional) | Your Nightwatch token | Monitoring. The Telegram bot token is already kept out of its logs. |

## 1. Create the application and Postgres
1. **Application:** create it from `bizexcel/complyrobin` in Singapore, with PHP 8.5 (General Settings → Runtime).
2. **Database:** add a **Serverless Postgres 17** database in Singapore and attach it to the environment.
   - Cloud injects `DB_HOST`, `DB_PORT`, `DB_DATABASE`, `DB_USERNAME` and `DB_PASSWORD`. That login is the database **owner**.
   - **Leave `DB_USERNAME` and `DB_PASSWORD` as Cloud injects them.** ComplyRobin never runs as the owner. It uses the owner only to create its own logins on each deploy (step 2), and reads its own login from `DB_TENANT_*`.

## 2. The three database logins (automatic on every deploy)
ComplyRobin never runs as the database owner, because the owner bypasses the row-level security that keeps organisations apart. It uses three logins instead:

| Login | Used for | Row-level security | Its variables |
|---|---|---|---|
| `app_tenant` | the app and its jobs | **applies** | `DB_TENANT_USERNAME`, `DB_TENANT_PASSWORD` |
| `app_platform` | staff work across organisations | bypassed | `DB_PLATFORM_USERNAME`, `DB_PLATFORM_PASSWORD` |
| `app_migrator` | migrations and seeders; owns the tables | bypassed | `DB_MIGRATOR_USERNAME`, `DB_MIGRATOR_PASSWORD` |

You don't create them by hand. Every deploy runs `php artisan app:deploy` (step 5), which starts with two commands:
- **`db:roles`** logs in as the owner (the injected `DB_USERNAME`/`DB_PASSWORD`) and applies `database/sql/roles.sql`:
  - it creates the three logins if they're missing and sets their passwords from the three `*_PASSWORD` variables;
  - the passwords reach `psql` only through its environment, never its command line;
  - it is safe to run every time, so a changed password is picked up on the next deploy.
- **`db:verify-roles`** stops the deploy if the app's login could bypass row-level security, or if the platform or migrator login couldn't do its job.

Before the first deploy, set the three passwords (step 3): strong ones from a password manager, kept in Secrets.

**If `CREATE ROLE … BYPASSRLS` is refused,** the deploy stops at `db:roles`. Cloud's docs don't yet say whether the owner may grant it, and the first deploy (or preview) answers that. Don't work around it by running the app as the owner.

## 3. Environment variables and secrets
- **Where:** set variables in the environment's General Settings, and put passwords in **Secrets** (Org Settings → Secrets, then link them to the environment).
- **Precedence:** a custom variable overrides a secret, which overrides an injected value. **Don't override `DB_USERNAME` or `DB_PASSWORD`**: they must stay the owner's, for `db:roles`.
- **Redeploy** after any change.

| Variable | Value | Notes |
|---|---|---|
| `APP_NAME` | `ComplyRobin` | |
| `APP_ENV` | `production` | |
| `APP_DEBUG` | `false` | |
| `APP_URL` | `https://app.complyrobin.com` | Used in reminder and invitation links. |
| `APP_KEY` | secret | If Cloud hasn't set one: run `php artisan key:generate --show` locally and store the result. Never change it later: it decrypts saved channel settings. |
| `DB_TENANT_USERNAME` | `app_tenant` | The app's own login. |
| `DB_TENANT_PASSWORD` | secret | |
| `DB_PLATFORM_USERNAME` | `app_platform` | |
| `DB_PLATFORM_PASSWORD` | secret | |
| `DB_MIGRATOR_USERNAME` | `app_migrator` | |
| `DB_MIGRATOR_PASSWORD` | secret | |
| `DB_SSLMODE` | `require` | |
| `DB_POOLING` | `true` | See [Connection pooling](#connection-pooling). |
| `SESSION_DRIVER` | `redis` | On Valkey. Cloud sets `CACHE_STORE` and `REDIS_*` itself, but not this. |
| `SESSION_SECURE_COOKIE` | `true` | |
| `MAIL_FROM_ADDRESS` | `hello@complyrobin.com` | Set by the Resend integration; check it matches. |
| `CHAT_CHANNELS` | *(empty)* | Launch is email, in-app and calendar only. Later: `slack,teams` and/or `telegram`. |
| `TELEGRAM_BOT_TOKEN` | secret, later | Only when Telegram is switched on. |

**Never set** `DB_DIRECT_HOST` or `DB_DIRECT_PORT` on Cloud: Cloud works out the direct host itself. Never set per-file visibility on storage either.

## 4. Cache, storage, queue, scheduler, mail
- **Cache:** attach a **Laravel Valkey** cache. Cloud injects `REDIS_*` and sets `CACHE_STORE`. Also set `SESSION_DRIVER=redis` (above).
- **Object Storage:**
  - Add a **private** Laravel Object Storage bucket, name its disk (e.g. `private`) and tick **default disk**. Cloud then sets `FILESYSTEM_DISK`, and ComplyRobin keeps every private file there.
  - Files are downloaded only through short-lived signed links.
  - Add a public bucket named `public` only when something public ships. See `.ai/rules/files.md`.
- **Queue:**
  - Add a **Managed queue** (Canvas → Add compute → Managed queue). Cloud sets `QUEUE_CONNECTION=cloud`.
  - It needs Laravel 13.19+ (we're on 13.42) and `aws/aws-sdk-php`, which is installed.
  - **Flex** workers stop a job after 90 seconds. Evidence reports and imports are small at launch. If a report or a 1,000-row import ever takes longer, switch the queue to **Pro**.
  - Managed queues don't support `queue:failed`/`queue:retry`. ComplyRobin's jobs record their own failures: reminders get `failed_at`, and the report lock is released.
- **Scheduler:** turn on the **Scheduler** toggle on the App cluster. It runs:
  - `credentials:send-reminders` hourly at :05. Reminders go out between 09:00 and 18:00 in each organisation's time zone.
  - `audit:prune` daily at 03:17 UTC.

  Both use `onOneServer`, so they run once, however many replicas there are.
- **Mail:**
  - Connect Resend to the Cloud organisation, verify `complyrobin.com` in Resend, then add **Emails** to the environment with sender `hello@complyrobin.com`.
  - The `resend/resend-laravel` package the integration needs is installed. ComplyRobin chose Resend (2026-11-16): it is generally available and has bounce webhooks, whereas Cloudflare Email Sending is still beta.
  - The package's webhook route (`/resend/webhook`) is **off**: without a signing secret it would accept unsigned requests. Switch it on with `RESEND_ROUTES=true` and `RESEND_WEBHOOK_SECRET` only when bounce handling is built.
  - **Switching to Cloudflare later** needs no code: Laravel 13 has a built-in `cloudflare` mailer. Set `MAIL_MAILER=cloudflare` and the account id and API token (`services.cloudflare`).
  - Resend's sending region closest to Singapore is Tokyo (`ap-northeast-1`).

## 5. Build and deploy commands
Set both under the environment's Deployments settings.

**Build commands:**
```bash
composer install --no-dev --no-interaction --prefer-dist --optimize-autoloader
```

```bash
npm ci --audit false
```

```bash
npm run build
```

```bash
php artisan optimize
```

```bash
php artisan filament:optimize
```

**Deploy command** (one, for production and every preview alike; safe to repeat):
```bash
php artisan app:deploy
```

It runs, in order, and stops at the first failure:
1. `db:roles`: the three logins (step 2).
2. `db:verify-roles`.
3. `migrate --database=pgsql_migrator --force`. Migrations run as `app_migrator`, the owner login; it is not a different database.
4. `PermissionSeeder` (the code-defined permissions) and `VendorSeeder` (the vendor catalogue).
5. `access:sync-system-roles`, which gives existing organisations' system roles any new permissions.
6. In a preview only, `PreviewSeeder` (the demo organisation).

- Don't add `queue:restart`, `optimize:clear` or `storage:link`. Cloud restarts workers itself, and deploy-time file changes aren't kept.

## 6. Domain, first deploy and first staff user
1. **Domain:** add `app.complyrobin.com` under Network settings, then add the DNS records Cloud shows. SSL is automatic.
2. **Deploy**, and check that the migrations and seeders ran.
3. **Sign up** at `https://app.complyrobin.com/register`, verify your email, and set up multi-factor authentication.
4. **Make yourself staff** from **Commands → Open console**:

   ```bash
   php artisan tinker --execute 'App\Core\Identity\Models\User::where("email", "you@example.com")->first()->forceFill(["platform_role" => "admin"])->save();'
   ```

   `/admin` is then the staff panel, where you grant Team or Enterprise (Organisations → **Grant module**) until billing ships.

## Preview environments (one per pull request)
- **What they are:** Laravel Cloud can create an isolated copy of the app for every pull request into `main`, with its own database, cache, bucket and queue. They scale to zero and are deleted when the pull request is merged or closed. A new database only has the owner login, so `app:deploy` creates the three logins in it, and previews need no manual setup.
- **Where to set it up:** in the **production** environment, under **Settings → Preview environments → New automation**:
  - **Rules:** auto-deploy on creation; delete on merge or close.
  - **Replication:** leave the defaults (new compute, database, cache and bucket, each scaling to zero).
  - **HTTP basic authentication:** on (Growth plan). It keeps preview links private and stops bots waking them.
- **Variables:** previews don't inherit production's variables. Cloud fills in `APP_KEY`, and injects each preview's own `DB_*` (owner), `REDIS_*`, storage settings and `APP_URL`; don't override those. Set these on the automation:

  | Variable | Value | Notes |
  |---|---|---|
  | `APP_ENV` | `preview` | Turns on the demo data. |
  | `APP_DEBUG` | `false` | |
  | `DB_TENANT_USERNAME` / `DB_PLATFORM_USERNAME` / `DB_MIGRATOR_USERNAME` | `app_tenant` / `app_platform` / `app_migrator` | |
  | `DB_TENANT_PASSWORD` / `DB_PLATFORM_PASSWORD` / `DB_MIGRATOR_PASSWORD` | secrets | **Different from production's.** All previews share them. |
  | `DB_SSLMODE` | `require` | |
  | `DB_POOLING` | `true` | |
  | `SESSION_DRIVER` | `redis` | |
  | `SESSION_SECURE_COOKIE` | `true` | |
  | `MAIL_MAILER` | `log` | Previews never send real email. |
  | `PREVIEW_PASSWORD` | secret, 12+ characters | The demo owner's password. |
  | `PREVIEW_MFA_SECRET` | secret | Generate with `php artisan preview:mfa-secret` and add it to your authenticator app by hand ("enter a setup key"). |
  | `PREVIEW_OWNER_EMAIL` | optional | Defaults to `demo@complyrobin.test`. |

  Leave out `CHAT_CHANNELS`, `TELEGRAM_BOT_TOKEN`, Nightwatch and, later, live billing keys.
- **Signing in to a preview:**
  1. Open the link Cloud posts on the pull request and pass the basic authentication.
  2. Sign in as `demo@complyrobin.test` with `PREVIEW_PASSWORD`, then the code from your authenticator app.
  3. The demo owner sees **Acme Preview** on Team, with people, applications and credentials that are overdue, due soon, rotated and without an owner. They are also staff, so `/admin` works.
- **Deploy commands:** previews run the same `php artisan app:deploy`. The demo organisation is only added when `APP_ENV=preview`, and only once.

## Connection pooling
- **Turning it on:** set `DB_POOLING=true` and keep the injected `DB_HOST` as it is. Laravel 13's Cloud integration then:
  - sends the app, the platform connection and the workers through Cloud's pooler (PgBouncer, transaction mode);
  - gives every connection a direct endpoint, which migrations use automatically.
- **Why it's safe:** ComplyRobin keeps nothing in the database session. The organisation travels with every SQL statement, so it can't leak between requests that share a pooled session. CI proves this by running the whole suite through PgBouncer.
- **Turning it off:** `DB_POOLING=false` sends everything direct.
- **Don't change `DB_HOST` by hand.** Cloud's docs describe enabling pooling by adding `-pooler` to `DB_HOST`. Without `DB_POOLING=true`, that would also send migrations through the pooler.

## Later
- **Chat channels:** set `CHAT_CHANNELS` (e.g. `slack,teams`) and redeploy. For Telegram, add `telegram` and a `TELEGRAM_BOT_TOKEN` from @BotFather.
- **Public files:** add a public bucket with the disk name `public`.
- **Billing:** Airwallex settings come with Phase 3.

## Still to confirm on the first deploy or preview
These are part of the Phase 3 deploy spike. The first preview is a safe place to find out:
- Can the owner create `app_migrator` and `app_platform` with `BYPASSRLS` (does `db:roles` pass), and can they log in through the pooler host?
- Do migrations go to the direct host with `DB_POOLING=true` (`php artisan migrate:status --database=pgsql_migrator`), and does the full app work pooled?
- Do the queued jobs (reports, imports, reminder delivery) run on the managed queue within the Flex limits?
