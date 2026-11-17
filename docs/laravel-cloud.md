# Deploying to Laravel Cloud

This guide covers what to create in Laravel Cloud for ComplyRobin, what to set, and in which order. It was checked against the [Laravel Cloud docs](https://laravel.com/cloud/docs) on 2026-11-16. Cloud changes often, so if a screen looks different, the docs win.

The deploy command has been rehearsed from nothing against an empty Postgres 17 whose owner is not a superuser, as on Cloud ([rehearse it yourself](#rehearse-the-deploy-locally-optional)). It has not yet run on Laravel Cloud itself: see [what the first deploy still has to confirm](#still-to-confirm-on-the-first-deploy-or-preview).

## What you need
| Resource | What to pick | Why ComplyRobin needs it |
|---|---|---|
| **Application** | Repository `bizexcel/complyrobin`, branch `main`, region **Singapore (ap-southeast-1)**, PHP **8.5** | The app. Create a `production` environment, and a `staging` one to try changes first. |
| **App cluster** | Web compute, with the **Scheduler** toggle **on** | Serves `/{organisation}` and `/admin`, and runs the scheduled work (below). |
| **Serverless Postgres** | Version **17**, region Singapore, **one cluster per environment** | All data. Pick 17 to match local and CI (Cloud offers 18 by default). Moving to 18 means updating `compose.yaml` and CI first. |
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
   - **Give every environment its own database cluster.** Logins belong to a cluster, not to a database inside it. If staging shared production's cluster, they would share the three logins, each deploy would reset the other's passwords, and staging's app could connect to production's data. Previews already get a cluster each.

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

**The owner must itself be allowed to bypass row-level security.** Postgres only lets a login hand out what it has.
- **What to expect:** Laravel Serverless Postgres runs on Neon, whose docs say its administrator logins can create roles and bypass row-level security. A rehearsal with an owner like that passes.
- **If it isn't so on Cloud,** the first deploy stops at `db:roles` with "Only roles with the BYPASSRLS attribute may create roles with the BYPASSRLS attribute". Nothing has been migrated at that point. Don't work around it by running the app as the owner: ask Laravel Cloud support.

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
| `AIRWALLEX_ENV` | `production` (`demo` on staging) | Billing. Every Airwallex variable is explained in [Setting up Airwallex billing](airwallex.md). |
| `AIRWALLEX_CLIENT_ID` | from Airwallex | |
| `AIRWALLEX_API_KEY` | secret | |
| `AIRWALLEX_WEBHOOK_SECRET` | secret | Without it, every webhook is refused. |
| `AIRWALLEX_PRICE_PRO_MYR_MONTHLY`, `…_PRO_MYR_YEARLY`, `…_PRO_USD_MONTHLY`, `…_PRO_USD_YEARLY`, and the same four for `TEAM` | `pri_…` ids | Ringgit for Malaysia, US dollars elsewhere. Empty means the plan isn't offered in that currency. |
| `BILLING_TRIAL_DAYS` | `14` | Optional; 14 is the default. |

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
2. **Deploy**, and read the deploy log. A good first deploy shows, in this order:
   - "Database logins are up to date."
   - "Every connection logs in with the right role."
   - the migrations, each marked DONE;
   - `PermissionSeeder` and `VendorSeeder`, each marked DONE;
   - "Synced system roles for 0 organisations."

   If it stops, the log says "Deploy stopped: … failed." under the reason. See [When a deploy stops](#when-a-deploy-stops).
3. **Sign up** at `https://app.complyrobin.com/register`, verify your email, and set up multi-factor authentication.
4. **Make yourself the first Admin** from **Commands → Open console**, with the email you signed up with, exactly as you typed it:

   ```bash
   php artisan staff:grant you@example.com
   ```

   It only works once that account has verified its email and set up multi-factor authentication (step 3). Your email is never stored in the code or the settings: only in the database, by this command.
5. **Open `/admin`**, the staff panel.
   - **Organisations → Grant module:** gives an organisation Pro, Team or Enterprise without paying, for example a friend or a pilot customer.
   - **Staff:** add other staff by email, change their role or remove them. Each change is listed there with who made it and when.

   There are two staff roles:

   | Role | Can |
   |---|---|
   | **Admin** | everything in the staff panel, including granting plans and managing staff |
   | **Support** | look at organisations and users, but not change them |

   Nobody can change their own role, and ComplyRobin always keeps at least one Admin. The console has the same two commands, `php artisan staff:grant you@example.com --role=support` and `php artisan staff:revoke you@example.com`, for when no Admin can sign in.

## When a deploy stops
`php artisan app:deploy` stops at the first problem, before anything later runs. The message says which step:

| Message in the deploy log | What it means | What to do |
|---|---|---|
| "Set DB_USERNAME and DB_PASSWORD to the database owner" | No database is attached, or those two were cleared. | Attach the database, and remove any custom `DB_USERNAME`/`DB_PASSWORD`. |
| "Set DB_TENANT_PASSWORD…: every database login needs a password." | One of the three `*_PASSWORD` variables is missing or empty. | Add it (step 3) and redeploy. |
| "Only roles with the BYPASSRLS attribute may create roles with the BYPASSRLS attribute" | The owner may not bypass row-level security (step 2). | Ask Laravel Cloud support. Don't run the app as the owner. |
| "pgsql logs in as …, which bypasses row-level security" | `DB_TENANT_USERNAME` points at the owner or another privileged login. | Set it to `app_tenant`. |
| "password authentication failed for user "app_…"" | A login's password variable was changed without a redeploy, or two environments share a cluster. | Redeploy; give each environment its own cluster. |
| "Set PREVIEW_PASSWORD (12 characters or more) and PREVIEW_MFA_SECRET…" | A preview is missing its demo-account settings. | Add them to the preview automation. |

Fixing the variable and redeploying is always safe: every step can be repeated.

## Rehearse the deploy locally (optional)
You can run the same deploy command against an empty Postgres whose owner is not a superuser, which is what a new Cloud database looks like. It needs Docker and `psql` on your machine, and a working [local setup](local-setup.md).

```bash
docker run -d --name cloud-like -e POSTGRES_PASSWORD=postgres -p 5544:5432 postgres:17
```

```bash
docker exec cloud-like psql -U postgres -c "CREATE ROLE cloud_owner LOGIN CREATEROLE CREATEDB BYPASSRLS PASSWORD 'owner'" -c "CREATE DATABASE main OWNER cloud_owner"
```

```bash
APP_ENV=production DB_PORT=5544 DB_DATABASE=main DB_USERNAME=cloud_owner DB_PASSWORD=owner CACHE_STORE=array php artisan app:deploy
```

Run the last command twice: the second run should change nothing. CI does the same on every push, and then deploys once more as a preview. Then remove the container:

```bash
docker rm -f cloud-like
```

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
- **Octane:** leave **Use Octane as runtime** off for launch. ComplyRobin runs on PHP-FPM, Cloud's default.
  - **Ready when wanted:** `laravel/octane` is installed and the app keeps nothing in memory between requests. The tests send real requests through an Octane worker and check that organisations and users never carry over.
  - **Before switching it on:** turn the toggle on in a **preview** first and click through both panels, then load-test it. Watch memory per worker, and whether the database still scales to zero (Octane keeps its database connections open).
  - **Switching it off again** is the same toggle and a redeploy.

## Still to confirm on the first deploy or preview
These are part of the Phase 3 deploy spike. The first preview is a safe place to find out:
- Does `db:roles` pass, that is, may Cloud's owner create logins that bypass row-level security (step 2)? Locally it passes with an owner like Neon describes.
- Can the three logins sign in through the pooler host?
- Do migrations go to the direct host with `DB_POOLING=true` (`php artisan migrate:status --database=pgsql_migrator`), and does the full app work pooled?
- Do the queued jobs (reports, imports, reminder delivery) run on the managed queue within the Flex limits?
