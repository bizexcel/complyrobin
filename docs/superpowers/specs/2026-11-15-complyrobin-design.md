# ComplyRobin — design
A Bizexcel SaaS. It starts as a **credentials register**: which SaaS tokens and keys a team relies on, who owns them, and when they are due for rotation, with reminders. It then adds ISO management systems on the same data: **BCMS** (ISO 22301) and **ISMS** (ISO 27001:2022), with ERM and GHG later as modules.

Laravel 13 · Filament 5 · PHP 8.5 · Laravel Cloud · Postgres

## Decisions
- One shared Postgres database with layered tenant isolation (no separate tenancy package).
- Filament for the UI, with one shared GRC core so BCMS and ISMS never duplicate code.
- Airwallex for billing, written in Laravel Cashier's style.
- The marketing site is a separate Astro site at complyrobin.com. Everything else lives in this app at app.complyrobin.com.
- Credentials are **information only** at launch; an encrypted vault comes later as an add-on.
- Buyers: small-business tech teams first, then compliance teams.
- Reminder channels at launch: email, Slack/Teams, WhatsApp/Telegram, in-app, and a calendar feed.
- Rotation is marked manually first; vendor integrations come later.

## Credentials register (first module, launch MVP)
**Why:** teams lose track of the API tokens their business runs on (Laravel Cloud, GitHub, AWS, Airwallex, Postmark…): who owns each one, where it's used, when it was last rotated, and when it's due. Auditors (ISO 27001, SOC 2, FedRAMP) ask for exactly that:
- an inventory of secrets with their owners and where they're used;
- a rotation schedule for each;
- proof of rotation over the last 90 days.

**Competitive landscape (researched 2026-11-15):**
- **Small-business expiry trackers** (ExpiryPulse, TokenTimer, credOclock, Lapsewise, Expiration Reminder at US$49+/mo) track *expiry dates* and send alerts.
- **Developer secrets managers** (Infisical, Doppler, 1Password, Vault, AWS Secrets Manager) store secrets and can auto-rotate a few cloud targets.
- **Enterprise machine-identity platforms** (Oasis, Clutch, Token Security, GitGuardian; Astrix bought by Cisco, Entro by SailPoint) automatically discover and rotate keys, at enterprise prices.
- **SaaS management platforms** (Torii, Zluri, Zylo) cover app inventory and renewals.
- **Compliance tools** (Vanta, Drata) only flag AWS keys older than 90 days.

**Our differentiation:**
- *Rotation schedules* (not just expiry), with an owner and a backup owner, escalation, and an evidence log.
- Small-business-friendly and self-serve, priced for Southeast Asia.
- The register feeds ISO 27001 (A.5.9 inventory of assets, A.5.17 authentication information, A.8.24 cryptography) and BCMS dependencies later.

**Scope v1:**
- **Applications** (tenant table `applications`): name, criticality (low/medium/high/critical), owner and backup owner (people), console URL, notes. Exact columns are in section 2a.
  - A global, seeded **vendor catalogue** (`vendors`) of common SaaS with a "where to rotate" link and suggested rotation interval: Laravel Cloud, GitHub, AWS, Google Workspace, Microsoft 365, Stripe, Airwallex, Postmark/Resend, Slack, Cloudflare, OpenAI/Anthropic.
- **Credentials** (tenant table `credentials`):
  - `reference` (e.g. `CRD-0042`) and `application_id` (composite FK);
  - name, `type` enum: api_token, oauth_client, ssh_key, certificate, password, webhook_secret, service_account, other;
  - environment (production/staging/development), owner and backup owner, and `used_in` (where it's configured, e.g. "Laravel Cloud prod env: STRIPE_SECRET");
  - `hint`: an optional key prefix or last 4 characters, **never the secret**;
  - `last_rotated_at`, `rotation_days`, `next_rotation_at` (stored and recomputed), optional vendor `expires_at`. Exact columns are in section 2a;
  - status (active / due soon / overdue / revoked) is derived from the dates, never stored.
- **Rotation log** (`credential_rotations`, tenant, append-only): rotated_at, rotated_by_id, notes, evidence attachment (media library); on-time vs late is calculated. A `RecordRotation` Action writes it and moves `next_rotation_at`.
- **Reminders:**
  - Per-organisation reminder policy, default 14, 7 and 1 days before due, then overdue on day 0, 3 and 7.
  - Overdue reminders escalate to the backup owner and then org admins.
  - A scheduled command runs per tenant (`Tenancy::run`) and uses the Phase 1 tasks/notifications engine. Each send is logged so nothing is duplicated.
- **Channels:**
  - Built on Laravel's notification system. Each channel is a small in-house class on the Http client, so no extra packages: Slack incoming webhook, Microsoft Teams workflow webhook, Telegram bot, and WhatsApp through the Meta WhatsApp Cloud API (a platform-level Business account, paid per message).
  - Email via the mail provider; in-app via Filament database notifications.
  - **Calendar:** a signed per-user `.ics` feed of upcoming rotations, generated in-house in about 40 lines.
  - Each organisation configures its channels; each user chooses their own.
- **Dashboard and reports:**
  - Overdue, due in 30 days, rotation compliance %, credentials without an owner.
  - "Rotation evidence report (last 90 days)" as PDF/CSV for auditors.
  - CSV import of an existing spreadsheet (Filament Importer).
- **Later, not in v1:**
  - An **encrypted vault add-on**: per-tenant envelope encryption, reveal-with-MFA, access logged. Its columns are added when it ships.
  - **Read-only vendor integrations** that read token age automatically: GitHub, Laravel Cloud, AWS. (Automatic rotation itself is the runner below.)
  - SaaS renewals and spend.
- **Bridge to ISO later:** in the ISMS phase, `applications` and `credentials` appear in the asset register and can be linked to risks and controls. In the BCMS phase, `applications` are selectable as BIA dependencies.

### Automatic rotation (customer-side runner)
**Decision (2026-11-17):** keys are rotated by a **rotation runner**, a small program the customer runs in their own GitHub account or Laravel app, which holds their admin tokens (an OpenAI admin key, a Laravel Cloud API token). ComplyRobin stores no admin token and no key: it says what is due, and records what the runner reports as rotation evidence. Hosted rotation, where ComplyRobin holds the admin tokens, is deferred to Phase 7 with the vault. The runner itself lives in its own public repository.

- **Runners** (`runners`, tenant): a named runner and the sha-256 of its token (`crr_` + 60 characters), shown once. Revoked, never deleted.
- **Automatic rotations** (`automatic_rotations`, tenant, one per credential): which runner, the source (OpenAI: project and a service account dedicated to this credential) and the destinations (Laravel Cloud: secret id, name, environments, whether to redeploy). Ids only; every field refuses anything that looks like a key.
- **Rotation runs** (`rotation_runs`, tenant): each time a runner claimed a credential, and how it went. A success writes the same `credential_rotations` row as "Record rotation" and links to it. At most one open run per credential; a run not reported within an hour is given up on and the owner is told. Upcoming reminders are skipped for automatically rotated credentials; overdue reminders stay as the alarm.
- A Team feature (`Feature::AutomaticRotation`).

**The runner contract**

Both endpoints: no session, cookies or CSRF. `{organisation}` is the organisation's slug (a previous slug also works). Any problem with the slug or the token (unknown, revoked, another organisation's) is `401 {"message":"Unauthenticated."}`. A plan without the feature is `200 {"jobs":[]}` for claims and `403` for reports.

**Claim** — `POST /api/runner/{organisation}/claims`, empty body. Response `200`:

```json
{
  "jobs": [
    {
      "run": "0193…uuid",
      "credential": {"reference": "CRD-0042", "name": "OpenAI production key"},
      "source": {"type": "openai", "project_id": "proj_abc", "service_account_id": "svc_acct_abc", "key_lifetime_days": null},
      "destinations": [
        {"type": "laravel_cloud", "secret_id": "sec-1", "secret_name": "OPENAI_API_KEY", "environment_ids": ["env-1"], "redeploy": true}
      ],
      "grace_hours": 48
    }
  ]
}
```

What the runner does with a job (Plan 2, recorded here so both sides agree): mint a new key for the service account; seal it and update each destination secret; redeploy the listed environments when `redeploy` is true; set every *other* key of that service account to expire in `grace_hours`; report. The service account is dedicated to one credential, which is what makes "every other key" safe and cleans up keys left by a half-finished run.

**Report** — `POST /api/runner/{organisation}/reports`:

```json
{"run": "0193…", "outcome": "succeeded", "rotated_at": "2026-11-17T10:00:00Z",
 "new_key": {"id": "key_abc", "hint": "AB12", "expires_at": null},
 "old_keys_expire_at": "2026-11-19T10:00:00Z", "deployments": ["dep-1"]}
```

```json
{"run": "0193…", "outcome": "failed", "step": "push", "message": "Laravel Cloud answered 422."}
```

`step` is one of `mint`, `push`, `deploy`, `retire`. Responses: `200 {"recorded":true}`; `404` when the run isn't this runner's; `409 {"message":"This run has already been reported."}` when the run is finished with a different outcome; `422` with Laravel's validation errors. Reporting the same outcome twice is `200` and writes nothing new.

## Packages (versions checked on Packagist, Nov 2026)

### `require`
| Package | Version | Purpose |
|---|---|---|
| php | ^8.5 | The default runtime on Laravel Cloud. |
| laravel/framework | ^13.42 | Supports PHP 8.3 to 8.5. |
| filament/filament | ^5.11 | Two panels, tenancy, built-in MFA (authenticator app with recovery codes, or email code), import/export actions, notifications. |
| spatie/laravel-permission | ^8.5 | Roles per organisation, using teams mode keyed on `organisation_id`. |
| bezhansalleh/filament-shield | ^4.3 | Permission UI and policy generation. Supports Filament 5 and permission 8. |
| spatie/laravel-medialibrary | ^11.23 | Evidence, attachments and images on Object Storage. |
| filament/spatie-laravel-media-library-plugin | ^5.11 | Filament upload fields for media library. |
| spatie/laravel-activitylog | ^5.1 | Audit trail. Gets an `organisation_id` column. |
| spatie/laravel-pdf | ^2.14 | PDFs through a swappable driver. **DOMPDF** for now, because Laravel Cloud has no Chromium; the Cloudflare driver can replace it later without code changes. |
| league/flysystem-aws-s3-v3 | ^3.35 | Laravel Object Storage is S3-compatible. |
| aws/aws-sdk-php | ^3.403 | A direct requirement of Laravel Cloud's managed queues. |
| resend/resend-laravel | ^1.7 | Mail through Laravel Cloud's Resend integration. |
| propaganistas/laravel-phone | ^6.1 | Phone validation for +60 numbers. |
| laravel/nightwatch | ^1.30 | First-party monitoring, with PII scrubbing configured. |
| laravel/octane | ^2.24 | Installed so the app is tested under Octane's request handling; the Octane runtime itself stays off at launch. |

### `require-dev`
| Package | Version | Purpose |
|---|---|---|
| laravel/boost | ^2.10 | MCP server, guidelines and skills for AI-assisted development. Run `php artisan boost:install`. |
| pestphp/pest | ^5.3 | Includes arch tests. |
| pestphp/pest-plugin-laravel | ^5.0 | |
| pestphp/pest-plugin-livewire | ^5.0 | |
| larastan/larastan | ^3.15 | |
| laravel/pint | ^1.32 | |
| rector/rector | ^2.8 | |
| laravel/pail | ^1.3 | |
| fakerphp/faker, mockery/mockery, nunomaduro/collision | skeleton defaults | |

### No package needed
- **IDs:** UUIDv7 through `HasUuids`. This replaces hashids, and IDs are never treated as a security control.
- **2FA:** Filament's built-in MFA, made **required**.
- **Excel import/export:** Filament's Importer and Exporter.
- **Sorting:** Filament's reorderable tables.
- **Workflow states:** PHP enums.
- **Organisation settings:** a typed JSON column.
- **Impersonation:** built in-house, about 50 lines, audited and shown with a banner.
- **Airwallex:** an in-house billing layer that copies **Laravel Cashier's structure, names and style**, backed by Laravel's Http client (section 5). The Packagist options are all unofficial and barely used.
- **Turnstile:** a single HTTP check.

### Supply chain
- Commit `composer.lock`.
- Use `minimum-stability: stable`.
- Run `composer audit` in CI.

## Hosting (Laravel Cloud, Singapore `ap-southeast-1`)
- **Compute:** PHP 8.5 on PHP-FPM; no Octane at launch. The tenancy state is per-request (`scoped`) and `laravel/octane` is installed (2026-11-16): `OctaneTest` sends real requests through an Octane worker. Octane stays switched off until it has been load-tested in a preview.
- **Database:** Serverless Postgres (Neon), production minimum CU set, point-in-time recovery 7–14 days.
  - The app and workers go through Cloud's **pooler** (`DB_POOLING=true`); migrations use the **direct** host automatically. Section 3 explains how tenancy stays safe behind a pooler.
- **Cache and sessions:** Laravel Valkey for cache, sessions and locks.
- **Files:** Laravel Object Storage buckets, whose visibility is set per bucket. A **private** bucket is the default disk for every private file (evidence, reports, imports), served only through temporary signed URLs. A **public** bucket named `public` is added when something public ships, and **extra buckets** under their own disk names when a feature needs them (decided 2026-11-15). Rule: `.ai/rules/files.md`.
- **Queues:** managed queues for PDFs, imports/exports, notifications, reminders and webhooks.
- **Scheduler:** reminders and recurring evaluations.
- **Mail:** Resend, through Laravel Cloud's Resend integration (chosen 2026-11-16 over Cloudflare Email Sending, which is still beta).

## 1. Structure
One app at app.complyrobin.com:
- **Customer panel:** `/{org-slug}/…`.
  - Clusters `/{org}/bcms/…` and `/{org}/isms/…`, plus Organisation and Shared groups.
  - The approvals inbox.
  - Tasks.
- **Admin panel:** `/admin`, for platform staff only.
- **API:** `POST /api/enquiries` for the Astro site, with CORS, Turnstile and a rate limit.

Folder layout:
```
app/
  Core/      Tenancy, Organisations, Identity (users, MFA, login log), Access (roles), People
             (people, departments, locations, offerings), Entitlements, Billing/Airwallex, Files, Pdf, Audit, Notifications
  Grc/       Shared engine: Documents (policies, versions, templates, distribution), Approvals, Objectives,
             Context, Risk, Evaluations, Audits, Reviews, Incidents, NonConformities, Tasks
  Modules/
    Bcms/    Bia, Strategies, Plans, Exercises, BcmsModule.php
    Isms/    Assets, Threats, Controls, Treatment, Soa, IsmsModule.php
  Filament/App (customer panel: Clusters/{Bcms,Isms}), Filament/Admin
```
Every area has the same subfolders: `Models/`, `Actions/`, `Enums/`, `Data/`, `Policies/`, `Filament/`.

**Rules, enforced by Pest arch tests:**
- **Actions own every write.** Examples: `SubmitForApproval`, `DecideApproval`, `PublishDocumentVersion`, `ScoreRisk`, `RaiseNonConformity`, `GenerateSoa`. Each one authorises, runs in a transaction and writes the audit entry. Filament only calls Actions.
- **Dependencies point one way:** `Grc` never depends on `Modules`, and domain code never depends on Filament.

**No more copy-paste between BCMS and ISMS:**
- Each organisation has a `management_systems` row per framework (bcms, isms, later erm). Every shared record points at one.
- Each module ships a `ModuleDefinition` declaring its key, catalogue and template seeders, cluster, default risk matrix and module-only resources. The panel builds itself from a `ModuleRegistry`.
- Each shared Filament resource is written **once** as a base class with form and table schema classes. Each framework gets a thin subclass of about three lines that sets the framework and cluster.
  - A generator command, `grc:framework {key}`, creates the subclasses, so adding ERM later is a new module folder plus that command.

## 2. Data model (key tables)
**Conventions: identifiers**
- **Primary keys are UUIDv7, stored in Postgres's native `uuid` type (16 bytes).**
  - Laravel's `HasUuids` generates v7 by default since Laravel 12.
  - Migrations use `$table->uuid('id')->primary()` and `foreignUuid(...)`.
  - This applies to organisations, users, memberships, people, departments, locations, offerings, every GRC, BCMS and ISMS record, templates, frameworks and requirements, plans and prices, subscriptions, approvals, tasks, enquiries and webhook calls.
  - **Why not bigint:** sequential ids can be counted and guessed (and hiding them with hashids is not security), and they collide when a tenant is copied to its own database.
  - **Why not ULID:** Laravel stores ULIDs as `char(26)` on Postgres. A native UUIDv7 is time-ordered like a ULID, but smaller and faster to index.
  - **No second "public id" column:** one id everywhere keeps things simple.
- **Exceptions that keep their package defaults:**
  - Laravel's `jobs`, `job_batches`, `cache` and `sessions`.
  - spatie permission `roles` and `permissions` (internal ids, never shown in URLs). The team key `organisation_id` is still uuid.
  - `activity_log`: append-only and never routed by id.

  Any **morph column pointing at our models** uses `uuidMorphs`/`nullableUuidMorphs`. That covers `model_has_roles.model_id` (set `permission.column_names.model_morph_key`), `media.model_id`, `notifications.notifiable_id`, `activity_log.subject_id`/`causer_id`, and Filament's `imports`/`exports.user_id`. Getting this wrong breaks joins silently, so a schema test asserts these columns are uuid.
- **Tenant key:** every tenant table has `organisation_id uuid not null` and `unique(organisation_id, id)`.
- **Composite foreign keys:** every foreign key between tenant tables is **composite**, `(organisation_id, x_id)`, so the database rejects links across tenants.
- **Human reference numbers are separate from ids.** Records people talk about get a per-organisation `reference`:
  - Shape: `{PREFIX}-{YYYY}-{0001}`, for example `INC-2026-0007`, `NC-2026-0012`, `IA-2026-0003`, `MR-2026-0001`, `RSK-0042`, `AST-0108`.
  - Generated by a `Numbering` service backed by `counters(organisation_id, name, year, value)`, locked with `SELECT … FOR UPDATE`.
  - Prefixes are editable in organisation settings.
  - `unique(organisation_id, reference)`.

**Conventions: organisation slug (the tenant segment in URLs)**
- **URLs: path-based, `app.complyrobin.com/{slug}/…`**, not `{slug}.complyrobin.com` (decided 2026-11-15). Reasons:
  - **One login and one cookie on one host.** People who belong to several organisations (consultants, parent companies) switch instantly. Subdomains would need a session cookie shared across `*.complyrobin.com`, which is harder to secure.
  - **One TLS certificate and one domain** on Laravel Cloud, with no wildcard DNS or wildcard certificates to manage.
  - **No subdomain trouble:** CSRF and Livewire requests behave normally. Local development and tests need no hosts-file entries.
  - **It's the common B2B pattern:** GitHub (`github.com/org`), Linear (`linear.app/team`) and Vercel do the same.
  - Branded `{slug}.complyrobin.com` or customer domains can be added later as a premium extra that redirects into the same app.
- The slug is unique across the platform, because sub-organisations are tenants too, e.g. `acme-berhad-penang`.
- **Generated at signup** from the name: `Str::slug($name)`, 3–40 characters, `[a-z0-9-]`.
  - On a collision, add `-2`, `-3`, and so on.
  - The owner may edit it in settings. Validation covers the same format, uniqueness, and a **reserved list**: admin, api, app, www, login, logout, register, billing, settings, livewire, filament, storage, up, health, airwallex, two-factor, password, email, invitations, docs, help, support, status.
- **Renaming:** a renamed slug keeps the old one in `previous_slugs` (jsonb). Old one redirects to the new one, so links in old emails keep working. Old slugs cannot be claimed by another organisation.
- **Not a security control:** a non-member requesting an organisation's URL gets **404** (not 403), so the URL doesn't confirm the organisation exists. Numeric or uuid lookups by URL are not supported.

**Global tables**
- `users`: includes MFA and `platform_role`.
- `login_attempts`.
- `frameworks` and `framework_requirements`:
  - ISO 22301 clauses, ISO 27001:2022 clauses, and Annex A's **93 controls**.
  - The 2022 attributes are stored as jsonb.
- Platform-level `plans`/`prices` holding the Airwallex ids.
- `enquiries`, `sent_emails`.

**Core tenant tables**
- `organisations`:
  - Columns: `parent_id`, `root_id` (sub-organisations; billing and entitlements resolve through the root), `slug`, `timezone`, `settings` jsonb (numbering prefixes, defaults).
- `organisation_user` (the membership pivot): plus spatie roles per organisation.
  - System roles: Owner, Admin, Editor, Approver and Viewer. Organisations can add custom roles.
  - Module access is itself a permission, e.g. `module.isms`.
- `people` (may be linked to a user) and `departments`. `locations` and `products` come with BCMS/ISMS.
- **Billing tables:** Cashier-shaped (section 5).
  - Columns on `organisations`: `airwallex_id`, `trial_ends_at`.
  - `subscriptions` and `subscription_items`.
  - `billing_webhook_calls`: the webhook inbox, with a unique `event_id`.
- `module_grants`: root organisation, module, `granted_by`, `starts_at`, `ends_at`, note. These are manual enterprise or invoice grants, kept apart from Airwallex-synced rows.
- **The module gate:** `Entitlements::has($org, 'isms')` returns `$org->root->subscribed('isms') || active module_grant`. It is cached under `org:{root}:entitlements`, and webhooks and grant changes clear that key.
- `management_systems`: framework, scope statement, boundaries, exclusions.
- `tasks`: polymorphic, with assignee, due date, a recurrence rule and reminders. Corrective actions, treatment-plan actions and monitoring schedules are all tasks.
- `activity_log` and `media`, both carrying `organisation_id`.

**Documents and approvals**
- `templates`: a null `organisation_id` means global.
- `documents` (policy, procedure, BC plan, report) with `document_versions`. Each version has a status (draft, in review, approved, published or superseded), sanitised HTML, the PDF path and a checksum.
- `document_distributions`: the "broadcast to staff" feature, recording when each person acknowledged.
- `approvals`: the `DecideApproval` action requires an approver who is in the same organisation, holds `documents.approve`, and is not the requester.

**Shared GRC tables**
- `objectives`, `context_items`, `evaluations`.
- `internal_audits`, plus activities, findings (major, minor, observation, opportunity for improvement) and reports.
- `management_reviews`, plus attendees, agenda (9.3.2 inputs) and outputs (9.3.3).
- `incidents` (severity, category, timeline).
- `nonconformities` (source, correction, root cause, actions, effectiveness check).

**Unified risk register**
- `risks`:
  - Links: `source` (context item, BIA activity, asset or manual), owner, `matrix_version_id`.
  - Method: `method` (`lxi` or `atv`) and an `inputs` jsonb.
  - Scores: inherent score and level, treatment, effectiveness, residual score and level, and a `normalised_score` (0 to 1) for heatmaps that span frameworks.
- `risk_controls`: maps a risk to an Annex A control or an organisation control, with effectiveness. This table **feeds the SoA**.

**Risk matrices**
- `risk_matrices`, with immutable `risk_matrix_versions`. Each version holds the axes, level thresholds and colours, the effectiveness scale, and which treatments the factor applies to.
- Default matrices:
  - **"ISO 31000 5×5"** for context and BCMS: high at 13 or above, moderate at 6 or above; effectiveness factors 0.2, 0.5, 0.8 and 1.0.
  - **"ISO 27005 asset-based 4×4×4"** for ISMS: the score is asset value × threat × vulnerability. High at 25 or above, medium at 12 or above. Effectiveness applies only to share and transfer treatments.
- `ScoreRisk` is a pure function, unit-tested against worked examples.

**BCMS tables**
- `bia_activities`: department, location, owner, MTPD, RTO and RPO in hours, a prioritised flag, MBCO.
- `bia_impacts`: one rating per category at D1, D3, D5 and D10.
- `bia_dependencies`: resource type by time horizon, from 4 hours to 20 days.
- `bc_strategies`, linked to activities.
- BC plans: stored as documents, plus `plan_teams` and `plan_steps`.
- `exercises`: participants, objectives, findings and results.

**ISMS tables**
- `assets`: confidentiality, integrity and availability rated 1 to 4, with `value` as a generated column equal to the highest of the three.
- `threat_catalog`: global and organisation entries.
- `soa_entries`: one row is pre-created per Annex A control (93). Each row records whether it is applicable, the justification (risk, legal, contractual or best practice), implementation status and evidence. Rows backed by a risk-linked `risk_controls` entry are generated automatically. Every version needs approval.

## 2a. Migrations (Phase 0 done; Phase 1–2 planned)
**Legend**
- **Tenant tables** are created with `Schema::tenantTable()`, which auto-adds `id uuid PK`, `organisation_id uuid FK→organisations (cascade)`, `unique(organisation_id,id)` and forced RLS. Those columns aren't repeated below.
- `→ tenant FK` means `tenantForeign()`: a composite `(organisation_id, x_id)` foreign key.
- Timestamps are `timestamptz` (`timestampsTz`/`softDeletesTz`).
- Enums are `string` columns cast to PHP enums.

**Phase 0: already in the repo**
| Migration | Table | Columns |
|---|---|---|
| `0001_01_01_000000_create_users_table` | users | id uuid PK · name string · email string unique · email_verified_at tz null · password string · platform_role string null · remember_token string(100) null · timestamps |
| | password_reset_tokens | email string PK · token string · created_at tz null |
| | sessions | id string PK · user_id uuid null idx · ip_address string(45) null · user_agent text null · payload longText · last_activity int idx |
| `…_create_cache_table` | cache / cache_locks | key string PK · value mediumText / owner string · expiration bigint idx |
| `…_create_jobs_table` | jobs / job_batches / failed_jobs | Laravel defaults: `jobs.id` and `failed_jobs.id` bigint, `job_batches.id` string |
| `2026_11_14_000001_create_organisations_table` | organisations (**not** a tenant table; own RLS: read all, update only own, no delete) | id uuid PK · name string · slug string(40) unique · parent_id uuid null FK self · root_id uuid null FK self · settings jsonb default `{}` · timestamps · deleted_at |
| `2026_11_14_000002_create_departments_table` | departments (tenant) | name string · description text null · parent_id uuid null → tenant FK departments · timestamps · deleted_at |

**Naming conventions (Laravel standard, enforced by a schema test and `migrations.md`)**
- **Table names:** plural snake_case (`credentials`, `people`). Pivot tables are singular and alphabetical (`organisation_user`).
- **Foreign keys** are `{relation}_id`, matching the Eloquent relation name: `owner()` → `owner_id`, `rotatedBy()` → `rotated_by_id`, `vendor()` → `vendor_id`. Every `*_id` column is uuid, except the package exemptions below.
- **Times:** `*_at` is `timestamptz`; plain dates are `*_on`.
- **Booleans** start with `is_` or `has_`.
- **Enums** are `string` columns cast to PHP enums.
- **No derivable columns:** if a value can be calculated (e.g. "was it on time"), it's an accessor, not a column.
- **No "future" columns:** columns are added when the feature ships (YAGNI).
- **Who changed what** comes from the activity log (below), so tables don't carry `created_by`/`updated_by`. Business actors are real columns where the product needs them (`rotated_by_id`, `invited_by_id`).

**Auditing (applies to all phases)**
- `spatie/laravel-activitylog` is on **every tenant model**. Each create, update, delete and restore records: who (the causer, a user uuid), which record (the subject, a uuid morph), old and new values (`properties`), organisation_id, and a batch uuid for multi-row actions.
- **Append-only:** the app role has no UPDATE or DELETE on `activity_log` (revoked in a migration). Retention is 2 years, configurable.
- Each record's page has a **History** tab, and auditors get an export.
- **Login attempts** are logged separately (below).
- **Rotations** are the product's own business audit trail (`credential_rotations`).
- **Secrets are never logged:** encrypted columns (channel config) are excluded from the activity log properties.

**Phase 1: base platform**
| Migration | Table | Columns |
|---|---|---|
| `add_two_factor_columns_to_users_table` | users | Filament 5 MFA columns. Use the exact names from Filament's docs at implementation, e.g. `app_authentication_secret` text null (encrypted), `app_authentication_recovery_codes` text null (encrypted), `has_email_authentication` bool default false. |
| `add_timezone_to_organisations_table` | organisations | timezone string default `Asia/Kuala_Lumpur` · previous_slugs jsonb default `[]` |
| `create_organisation_user_table` | **organisation_user** (pivot: which users belong to which organisation; one user can belong to several) | id uuid PK · organisation_id uuid FK · user_id uuid FK users · timestamps · unique(organisation_id, user_id). Not tenant-scoped. RLS: a user sees their own rows (for the organisation switcher), and admins see the current organisation's rows. Roles come from spatie, per organisation. |
| `create_invitations_table` | invitations (tenant) | email string · role string · token string(64) unique (sha-256 hash) · invited_by_id uuid FK users · expires_at · accepted_at null · timestamps |
| `create_permission_tables` (spatie, teams mode) | roles, permissions, model_has_roles, model_has_permissions, role_has_permissions | Package **bigint** ids for roles and permissions. `organisation_id` uuid is the team key; `model_id` uuid points at users. |
| `create_people_table` | people (tenant: staff and contacts, who may or may not have a login) | user_id uuid null FK users · department_id null → tenant FK · name string · email string null · phone string(20) null · job_title string null · timestamps · deleted_at |
| `create_counters_table` | counters (tenant: drives the `CRD-0001` style references) | name string (e.g. `credential`) · year smallint null · value int default 0 · unique(organisation_id, name, year) |
| `create_login_attempts_table` | login_attempts (global) | id uuid PK · user_id uuid null FK users · email string · ip_address string(45) · user_agent text null · status string (succeeded/failed/mfa_failed) · created_at |
| `create_module_grants_table` | module_grants (tenant, on the root organisation) | module string · granted_by_id uuid FK users · starts_at · ends_at null · note text null · timestamps |
| `create_tasks_table` | tasks (tenant) | taskable uuid morph null · title string · notes text null · assignee_id uuid null → tenant FK people · due_at null · status string (open/done/cancelled) · completed_at null · completed_by_id uuid null FK users · timestamps |
- **Deferred to the BCMS/ISMS phases (not needed for the Credentials MVP):** `locations`, `products` (renamed from "offerings"), and task recurrence.
| `create_notifications_table` (Laravel) | notifications | id uuid PK · type string · notifiable uuid morph · data jsonb · read_at tz null · timestamps |
| `create_activity_log_table` (spatie) | activity_log | Package **bigint** id · log_name · description · subject uuid morph null · causer uuid morph null · properties jsonb · event · batch_uuid uuid null · **organisation_id uuid null** with RLS |
| `create_media_table` (spatie) | media | Package **bigint** id plus its own `uuid` column · model uuid morph · **organisation_id uuid** with RLS · file fields as the package defines |
| Filament `imports` / `exports` / `failed_import_rows` | | Package **bigint** ids · **user_id uuid** · **organisation_id uuid** with RLS |

**Phase 2: Credentials register.** There are five tables, and each answers one question:
- `vendors`: what SaaS exists?
- `applications`: what does *this* organisation use?
- `credentials`: which tokens does it have?
- `credential_rotations`: when was each rotated, and by whom?
- `reminders`: what did we send, so nothing is sent twice?

Plus `notification_channels` and `calendar_feeds`.

| Migration | Table | Columns |
|---|---|---|
| `create_vendors_table` | **vendors** (global, seeded catalogue: Laravel Cloud, GitHub, AWS, Stripe, Airwallex…) | id uuid PK · slug string unique (e.g. `laravel-cloud`) · name string · category string · website_url string · rotation_url string null (deep link to "where to rotate") · rotation_days smallint (suggested interval) · timestamps |
| `create_applications_table` | **applications** (tenant: a SaaS this organisation uses; model `Application`) | vendor_id uuid null FK vendors (null = custom application) · name string · criticality string (low/medium/high/critical) · owner_id null → tenant FK people · backup_owner_id null → tenant FK people · console_url string null · notes text null · timestamps · deleted_at |
| `create_credentials_table` | **credentials** (tenant) | reference string (`CRD-0001`), unique(organisation_id, reference) · application_id → tenant FK applications · name string · type string (api_token/oauth_client/ssh_key/certificate/password/webhook_secret/service_account/other) · environment string (production/staging/development) · owner_id null → tenant FK people · backup_owner_id null → tenant FK people · used_in text null (where it's configured) · hint string(32) null (key prefix or last 4 characters, **never the secret**) · rotation_days smallint · last_rotated_at null · next_rotation_at null, index(organisation_id, next_rotation_at) · expires_at null (vendor expiry) · revoked_at null (status derived: active / due soon / overdue / revoked) · timestamps · deleted_at |
| `create_credential_rotations_table` | **credential_rotations** (tenant, append-only: the app role gets INSERT only) | credential_id → tenant FK credentials (cascade) · rotated_by_id uuid FK users · rotated_at · due_at null (what it was due at, so "on time" is calculated) · notes text null · created_at. Evidence files are attached via media. |
| `create_notification_channels_table` | **notification_channels** (tenant: shared team channels when `user_id` is null, personal ones when it's set) | user_id uuid null FK users · type string (email/slack/teams/telegram/whatsapp) · name string · settings text (**encrypted** JSON: webhook URL, chat id or phone; never logged) · is_enabled bool default true · timestamps |
| `create_reminders_table` | **reminders** (tenant: one row per reminder sent; the unique key guarantees "exactly once") | credential_id → tenant FK credentials (cascade) · notification_channel_id null → tenant FK notification_channels · user_id uuid null FK users (for email/in-app) · type string (upcoming/overdue/escalation) · days smallint (e.g. 14 before, or 3 after) · due_at (the rotation it's about) · sent_at · unique(credential_id, due_at, type, days, notification_channel_id, user_id) |
| `create_calendar_feeds_table` | **calendar_feeds** (tenant: a private `.ics` link per user) | user_id uuid FK users · token string(64) unique (sha-256 hash) · last_used_at null · revoked_at null · timestamps |

**Notes**
- **Reminder policy:** the defaults (14/7/1 days before; 0/3/7 days overdue) live in `organisations.settings`.
- **Schema-test exemptions** (non-uuid `id` allowed):
  - jobs, failed_jobs, job_batches, cache, cache_locks, sessions, password_reset_tokens, migrations;
  - roles, permissions, activity_log, media, imports, exports, failed_import_rows.
- **Schema convention test:**
  - every `*_id` column is uuid (with the same exemptions);
  - every `*_at` column is `timestamp with time zone`;
  - every boolean starts with `is_` or `has_`;
  - no table is singular, except pivots.

## 3. Tenant isolation
**1. Composite foreign keys** in the schema (described in section 2).

**2. `BelongsToOrganisation` trait**
- Adds a global scope from `TenantContext`.
- **Throws** if no tenant is set.
- Fills `organisation_id` on create, and refuses a mismatched value.
- The only way out is `TenantContext::bypass($reason, fn)`, allowed only for the platform guard or the console, and every use is logged.

**3. Filament tenancy**
- `User::getTenants()` and `canAccessTenant()` read memberships, and also allow group admins of a parent organisation.
- Tenant middleware sets the tenant context, the spatie team id and the module entitlement check. It is configured with `isPersistent: true` so it also runs on Livewire update requests.

**4. Postgres row-level security**
- **Three database roles:**
  - `app_migrator`: owns the tables and bypasses RLS. Used for migrations and seeds.
  - `app_tenant`: cannot bypass RLS. Used by the customer panel and tenant jobs.
  - `app_platform`: bypasses RLS. Used by the admin panel and system jobs.
- **Policy:** on every tenant table, `organisation_id = nullif(current_setting('app.organisation_id', true), '')::uuid`, applied to both reads and writes, with ENABLE and FORCE.
  - An unset tenant matches nothing, so it fails closed.
  - Templates and the threat catalogue also allow global rows to be read.
- **Applying the tenant id:**
  - Every statement on the tenant connection starts with `set_config('app.organisation_id', …, true)` (and `app.user_id`), transaction-local, so nothing is kept in the database session (decided 2026-11-16).
  - That makes it safe behind Laravel Cloud's transaction-mode pooler, where a session-level setting would leak to other clients sharing the server connection. CI proves it through PgBouncer with one shared server connection.
  - Migrations and other DDL use the direct host automatically. It also works unchanged with Octane.
- **Role creation:**
  - `app_tenant` must be created with SQL, not the console, because console roles join `neon_superuser`, which can bypass RLS.
  - Whether Laravel Cloud allows this is confirmed in a **Phase 0 spike**.
  - If it doesn't, layers 1–3 and 5–7 ship, and RLS follows once a role is available (or via Laravel Cloud's RDS Postgres option).

**5. Queued jobs**
- The tenant id travels in Laravel `Context`, so it is serialised into every job, including Filament's own import and export jobs.
- `Context::hydrated` restores the tenant on the worker, and a listener clears it afterwards.
- Scheduled work loops with `TenantContext::each()`.

**6. Validation**
- Every posted id uses `scopedExists()`/`scopedUnique()` or `Rule::exists()->where('organisation_id', …)`, because validation rules bypass Eloquent scopes.

**7. Files and cache**
- Files live at `org/{id}/{module}/{model}/{uuid}/…`. Upload directories are closures with private visibility.
- The `livewire-tmp/` folder uses random names and a lifecycle rule.
- Cache keys are prefixed `org:{id}:`.

**8. Tests**
- An auto-discovered Pest dataset runs every `BelongsToOrganisation` model and every Filament resource as organisation B against organisation A's records. It covers listing, viewing, editing, deleting and foreign-key injection.
- A `pg_catalog` test asserts that every table with `organisation_id` has forced RLS, a policy and composite foreign keys.
- Cross-tenant attack scenarios are replayed (organisation B against A):
  - peeking at another organisation's dashboard;
  - an SoA that lists another organisation's assets;
  - approving another organisation's document (IDOR);
  - taking over another organisation's settings.

## 4. Roadmap
Every phase must pass Pest, Larastan, Pint and arch tests, and is deployed to a Laravel Cloud preview environment.

**Phase 0: Scaffold and spikes**
- `laravel new` on Laravel 13 with Pest and Postgres, and a PHP 8.5 dev container.
- Filament 5 with two panels; Boost, Larastan, Pint, Rector.
- **Project rules, guideline and skills written** (section 6), together with the arch and schema tests that enforce them.
- GitHub Actions running Postgres 17: pint, larastan, pest, composer audit.
- Laravel Cloud staging environment.
- **Spike 1:** Neon roles, RLS and the direct host.
- **Spike 2:** a half-day Airwallex sandbox test of checkout plus a webhook.
- *Exit:* CI is green, and a raw-SQL test proves `app_tenant` cannot read another organisation's row.

**Phase 1: Base platform**
- Organisations and sub-organisations, invitations and `organisation_user` membership, roles with Shield, required MFA, login attempts, the activity-log audit trail.
- People and departments, with a queued spreadsheet import.
- Entitlements with manual grants.
- A **minimal admin panel**: organisations, users, grants, impersonation. *Staff management shipped 2026-11-16:* the `staff:grant`/`staff:revoke` commands for the first Admin, a Staff page for Admins, Support as a view-only role, and an append-only `staff_role_changes` log.
- Tasks and reminders, notifications, media on Object Storage.
- *Exit:* the isolation suite and the `pg_catalog` test pass.

**Status:** Phases 0 and 1 are done. Phase 2 is built (see its status below).

**Phase 2: Credentials register (first product, launch MVP)**
- Vendor catalogue (seeded), applications, credentials, rotation log with evidence, the `RecordRotation` Action.
- The reminder scheduler with escalation and a send log.
- Channels: email, in-app, Slack, Teams, Telegram, plus a private `.ics` feed. WhatsApp (Cloud API) comes later.
- Dashboard, the 90-day evidence report (PDF/CSV), CSV import, and a module entitlement `credentials`.
- *Exit:*
  - Tests show that `next_rotation_at` follows `rotation_days` and the last rotation.
  - Reminders fire exactly once per offset per credential and escalate when overdue.
  - Every channel is tested with `Http::fake`, and the `.ics` feed is signature-protected.
  - No secret value is stored: a schema test asserts there is no column like `secret`/`token_value`.
  - Cross-tenant isolation tests cover the new tables.
- **Status (2026-11-15): built.** All 17 steps shipped, each test-first with its safeguards mutation-checked:
  - the register: vendors, applications, credentials, rotations with evidence, the dashboard;
  - reminders, sent exactly once on the organisation's days, with escalation and weekly repeats on Team;
  - channels: email and in-app; Slack and Teams, proven by a test message; Telegram, proven by a code typed back; a private calendar link;
  - organisation settings and the reminder policy; the Members page with seats and invitations;
  - on Team: the rotation evidence report (PDF and CSV), the audit trail CSV export, and CSV import.
  - Every exit test above holds. The calendar link is protected by a random token stored only as a hash, rather than a signature. CSV import is in-house, not Filament's importer, because Filament's failed-rows download binds its record outside the organisation.

**Phase 2b: Automatic rotation** (the ComplyRobin side; plan `docs/superpowers/plans/2026-11-17-automatic-rotation-runner-api.md`)
- Runners, automatic rotation settings per credential, rotation runs, the two runner endpoints, the screens.
- *Exit:* a report that carries a key is refused and nothing is stored; a repeated or late report never records twice; a credential is never handed out twice while a run is open, nor within 6 hours of a failure; a downgrade, revocation or deletion stops new work; another organisation's token or run is refused; the isolation suite covers the three tables.

**Phase 3: Billing, back office and MVP launch** (formerly Phases 5–6, brought forward so the MVP can be sold)
- The Cashier-style Airwallex layer (section 5), with plans for the `credentials` module (e.g. a free tier capped by credential count, then paid tiers).
- The minimal admin panel grows: plans, catalogue editing, enquiries, the email log.
- Launch hardening for the MVP: CSP, rate limits, a restore drill, an external pen test on the credentials surface, then production cut-over at `app.complyrobin.com`.
- *Exit:* billing webhook tests (replay, bad signature, transitions) and the restore drill pass.

**Phase 4: Shared GRC core**
- Catalogues (ISO 22301; ISO 27001:2022 with 93 controls) and templates.
- Documents and versions with the approval inbox and distribution; the PDF pipeline (queued, table-only Blade layouts).
- Risk engine and matrices; context and scope; objectives; evaluations; audits; reviews; incidents; NCs.
- Built in the BCMS cluster first, then the ISMS cluster is **generated** to prove the code is generic.
- *Exit:* the shared resource tests run once per framework. Approval IDOR and self-approval tests pass. Risk numbers match the worked examples. The flow "audit → finding → NC → corrective action → approval closes the NC" works.

**Phase 5: BCMS**
- BIA with the impact grid and dependency grid (custom Livewire pages). Applications from the Credentials register can be picked as dependencies.
- BIA risks feeding the register; the prioritised activities view.
- **BC strategies** and **BC plans** (new); exercises; register PDFs.
- *Exit:* tests for RTO ≤ MTPD and RPO validation and the prioritisation rules; a BIA report and a BC plan PDF are produced.

**Phase 6: ISMS**
- Assets, the threat catalogue, asset-based risk, controls per risk, treatment plans, and the **generated SoA**.
- Applications and credentials join the asset register. Rotation evidence becomes evidence for A.5.17 and A.8.24.
- *Exit:* MoR threshold tests. A threat treated by modification appears in the SoA as "included – risk". Excluding a control requires a justification. The SoA has 93 rows.

**Phase 7: GRC hardening and add-ons**
- Dashboards: risk heatmap, open NCs, overdue tasks. HTML sanitising for documents.
- Per-tenant export (also the tool for moving a large tenant to its own database later). Neon connection load test.
- **Credentials add-ons:** the encrypted vault (MFA reveal, access log) and vendor integrations (GitHub, Laravel Cloud, AWS token age).
- *Exit:* no high pen-test findings on the new GRC and vault surfaces.

**Ordering notes**
- Entitlements and the minimal admin panel come in Phase 1 because module gating and support depend on them.
- The Credentials register is the first sellable product. BCMS and ISMS are sold as upgrades to the same organisations, through the same billing.

## 5. Billing: Airwallex layer in Laravel Cashier's style
**Goal:** anyone who knows Cashier can read and use this code without new concepts. Cashier itself is Stripe-only, so it isn't installed. Instead, its file layout, class names, method names, return types, docblock wording ("Determine if the subscription is on trial.") and configuration conventions are mirrored under `App\Billing`. Where Cashier says `stripe`, this says `airwallex`.

**Files (each mirrors its Cashier counterpart):**
```
app/Billing/
  Cashier.php                  static helpers: airwallex(), useCustomerModel(), useSubscriptionModel(),
                               useSubscriptionItemModel(), findBillable(), formatAmount(), ignoreRoutes(),
                               keepPastDueSubscriptionsActive()
  Billable.php                 trait: uses the Concerns below
  Concerns/
    ManagesCustomer.php        airwallexId(), hasAirwallexId(), createAsAirwallexCustomer(), createOrGetAirwallexCustomer(),
                               updateAirwallexCustomer(), asAirwallexCustomer(), syncAirwallexCustomerDetails(),
                               airwallexName()/airwallexEmail()/airwallexPhone()/airwallexAddress()
    ManagesSubscriptions.php   newSubscription($type, $prices), subscribed($type), subscription($type), subscriptions(),
                               subscribedToProduct(), subscribedToPrice(), onTrial(), onGenericTrial(), trialEndsAt(),
                               hasExpiredTrial()
    ManagesInvoices.php        invoices(), findInvoice(), downloadInvoice()
  SubscriptionBuilder.php      trialDays(), trialUntil(), skipTrial(), quantity(), price(), withMetadata(), checkout()
  Subscription.php             model: STATUS_* constants; scopes active/onTrial/notOnTrial/canceled/notCanceled/ended/
                               pastDue/recurring/onGracePeriod/notOnGracePeriod; valid(), active(), recurring(), onTrial(),
                               canceled(), onGracePeriod(), ended(), hasPrice(), hasProduct(), cancel(), cancelNow(),
                               resume(), swap(), updateQuantity(), items(), owner(), asAirwallexSubscription(),
                               syncAirwallexStatus()
  SubscriptionItem.php         model: airwallex_id, airwallex_product, airwallex_price, quantity; swap(), updateQuantity()
  Checkout.php                 wraps an Airwallex Billing Checkout; implements Responsable (redirects to the hosted URL); url()
  Invoice.php                  wrapper: date(), total(), status(), hostedUrl(), view(), download()
  Airwallex/
    AirwallexClient.php        Http-based client exposing resources like StripeClient:
                               ->customers, ->products, ->prices, ->subscriptions, ->billingCheckouts, ->invoices
    Resources/*.php            create/retrieve/update/list/cancel per resource
  Http/Controllers/WebhookController.php
  Http/Middleware/VerifyWebhookSignature.php
  Events/WebhookReceived.php, Events/WebhookHandled.php
  Exceptions/InvalidCustomer.php, CustomerAlreadyCreated.php, SubscriptionUpdateFailure.php,
             AirwallexApiError.php, InvalidWebhookSignature.php
  Console/WebhookCommand.php   `airwallex:webhook`: creates the endpoint through the API if Airwallex allows it; otherwise prints setup steps
  Console/SyncPricesCommand.php `airwallex:sync-prices`: pushes local plans and prices to Airwallex explicitly
                               (never from model observers, so seeding never calls the API)
  BillingServiceProvider.php   registers the config, the webhook route (named `billing.webhook`) and commands
config/billing.php             mirrors config/cashier.php: client_id, api_key, webhook.secret, webhook.tolerance,
                               env (demo|production), api_version, path ('airwallex'), currency ('myr'),
                               currency_locale ('en_MY'), logger
database/migrations/           Cashier's three migrations with airwallex_* columns:
                               customer columns on organisations, subscriptions, subscription_items
```

**Usage (reads exactly like Cashier):**
```php
return $organisation->newSubscription('isms', $price->airwallex_id)
    ->trialDays(14)
    ->checkout(['success_url' => …, 'back_url' => …]);   // redirects to Airwallex hosted checkout

$organisation->subscribed('bcms');                       // gate check
$organisation->subscription('isms')->onGracePeriod();
$organisation->subscription('isms')->cancel();
```

**Mapping to Cashier conventions:**
- **Billable model:** the **root Organisation**, not User. Billing sits on the parent of a group.
- **Subscription type:** the module key (`bcms`, `isms`). This is Cashier's "multiple subscriptions" pattern, so ERM later is simply `newSubscription('erm', …)`.
- **Status columns:** `airwallex_status` holds Airwallex statuses (`ACTIVE`, `IN_TRIAL`, `PAST_DUE`, `CANCELLED`, …), mapped to Cashier-style predicates. `Cashier::keepPastDueSubscriptionsActive()` behaves as it does in Cashier.

**Webhooks:**
- The controller verifies the signature, stores the call in `billing_webhook_calls`, and fires `WebhookReceived`.
- It then dispatches to `handle{StudlyEventName}` methods: `subscription.created` becomes `handleSubscriptionCreated`. Other handlers include `handleSubscriptionUpdated`, `handleSubscriptionCancelled`, `handleBillingCheckoutCompleted`, `handleInvoicePaid` and `handleInvoicePaymentFailed`.
- Afterwards it fires `WebhookHandled`.
- Unknown events return 200 through `missingMethod()`, as in Cashier.
- Our own listeners (clearing the entitlements cache, emailing the owner) listen to `WebhookHandled`.

**Airwallex specifics hidden inside the client:**
- **Authentication:** `POST /api/v1/authentication/login` with `x-client-id` and `x-api-key`. The bearer token is cached in Valkey until `expires_at` minus 60 seconds.
- **Requests:** a pinned `x-api-version` (at least 2025-06-16, which the Billing webhooks need). A `request_id` UUID on every create for idempotency. Retries with backoff. Errors are mapped to `AirwallexApiError`.
- **Signatures:** HMAC-SHA256 of `x-timestamp` (milliseconds) followed by the raw body, using the endpoint secret. The result is compared to `x-signature` with `hash_equals`, inside a 5-minute tolerance.
- **Base URL:** demo or production, from `AIRWALLEX_ENV`.
- **Environment variables:** `AIRWALLEX_CLIENT_ID`, `AIRWALLEX_API_KEY`, `AIRWALLEX_WEBHOOK_SECRET`, `AIRWALLEX_ENV`, `BILLING_CURRENCY=myr`.

**For the Phase 0 Airwallex spike to confirm:**
- Whether cancelling at period end is supported. Airwallex cancellation is final, so `cancel()` may need to become "cancel at period end", with `resume()` only allowed before that date.
- Whether Airwallex exposes invoice PDFs or hosted invoice URLs; otherwise `downloadInvoice()` renders through spatie/laravel-pdf.
- Whether webhook endpoints can be created through the API.
- Whether Malaysian payment methods (FPX, cards) are available in Billing Checkout.
- SST handling.

**Tests (Cashier's own guidance, adapted):**
- Unit and feature tests use `Http::fake()` with JSON fixtures recorded from the Airwallex demo environment.
- A separate `@group airwallex` suite runs against the real demo environment, both in CI on main and manually.
- Covered cases: subscription status predicates and scopes, the signature middleware (valid, bad, stale), duplicate and replayed events, and checkout to webhook to `subscribed()` returning true.

## 6. Project rules (Laravel Boost style)
Every convention in this plan is written down where AI agents and new developers will see it, and backed by an automated check wherever one is possible. **Rules tell agents what to do; tests prove it was done.** Boost has three places for this:

**Always-loaded guideline: `.ai/guidelines/complyrobin.md`** (kept short)
- What the product is.
- The layers: Core / Grc / Modules / Filament / Billing.
- The non-negotiables: tenant isolation, Actions own writes, no Filament in domain code, UUIDv7 ids, Cashier parity for billing.
- A pointer to `.ai/rules/index.md`.

`boost:install` / `boost:update` merges it into `CLAUDE.md` and `AGENTS.md`.

**Path-scoped project rules: `.ai/rules/*.md`** (frontmatter `paths:` globs; Boost maintains `index.md`)
| Rule file | Applies to | Key rules | Enforced by |
|---|---|---|---|
| `migrations.md` | `database/migrations/**` | Use `uuid('id')->primary()`. Tenant tables use `Schema::tenantTable()`, which adds RLS, FORCE and `unique(organisation_id,id)`. Foreign keys between tenant tables are composite. Morphs use `uuidMorphs`. Use `timestampsTz`. Store enums as strings, never DB enums. | `pg_catalog` schema test |
| `models.md` | `app/**/Models/**` | `HasUuids`. Tenant models use `BelongsToOrganisation`. Enum casts. Explicit `$fillable`. References come from `Numbering`. No business logic. | Pest arch test (models with `organisation_id` must use the trait) |
| `actions.md` | `app/**/Actions/**` | One invokable `handle()` per use case, verb-noun names. Authorise, then `DB::transaction`, then activity log. Never trust posted ids. | Arch test (Actions don't depend on Filament) |
| `filament.md` | `app/Filament/**`, `app/**/Filament/**` | Resources call Actions only. Use `scopedExists`/`scopedUnique`. Shared resources use base class + framework subclass. No global search on base classes. Upload directory is a tenant closure, private visibility. Show `reference`, never uuid. | Livewire resource tests |
| `tenancy.md` | `app/Core/Tenancy/**`, `app/**/Jobs/**`, `app/Console/**` | The tenant travels in `Context`. Use `TenantContext::bypass()` only for platform or console work, always with a reason. Scheduled work uses `TenantContext::each()`. | Isolation suite |
| `billing.md` | `app/Billing/**`, `config/billing.php` | Mirror Laravel Cashier exactly: class, method and docblock wording, with `stripe` → `airwallex`. All API calls go through `AirwallexClient`. Webhook methods are named `handle{StudlyEvent}`. Seeders and observers never call Airwallex. | Billing tests, `Http::preventStrayRequests()` |
| `http-routing.md` | `routes/**`, `app/Http/**` | Non-members get 404 on organisation slugs. Enforce the reserved-slug list. The enquiry API uses Turnstile, a rate limit and CORS restricted to www. | Feature tests |
| `pdf.md` | `resources/views/pdf/**` | DOMPDF-safe: tables and inline CSS only, no flex or grid. Rendered in the queue. | PDF text assertions |
| `seeders.md` | `database/seeders/**` | Idempotent upserts by stable key (e.g. `A.5.15`). Never call external APIs. | CI runs `migrate:fresh --seed` |
| `testing.md` | `tests/**` | Pest. Factories always create inside an organisation. Every new tenant model joins the isolation dataset automatically. Airwallex calls use recorded fixtures. | CI |

**On-demand skills: `.ai/skills/{name}/SKILL.md`**
- `creating-a-tenant-model`: the checklist (migration, model, factory, policy, Filament resource, isolation-test entry, rule check).
- `adding-a-grc-module`: how to add ERM or GHG (a `ModuleDefinition`, the `grc:framework {key}` generator, catalogue seeder, default risk matrix, subscription type).
- `airwallex-billing`: Cashier-parity usage, adding a webhook handler, recording fixtures from the demo environment.

**Process**
- Rules are written in **Phase 0**, before feature code, and recorded with Boost's `record-rule` MCP tool so `index.md` stays correct. If the MCP server isn't reachable in a session, rule files are written by hand and `php artisan boost:update` is run to regenerate the index. The index is then checked for each file.
- Any PR that changes a convention updates its rule file in the same PR.
- `.ai/` is committed. `CLAUDE.md` and `AGENTS.md` are committed too, so cloud agent sessions get the guidelines even before `composer install`.

## 7. Known pitfalls to design around
- **Global search** would show results twice, once per framework subclass. Disable it on base classes and scope each subclass to its management system.
- **Filament's `imports`, `exports` and `failed_import_rows` tables** need `organisation_id` and RLS. Exports go to the tenant path.
- **Database notifications** are per user, across organisations. They carry only deep links, never sensitive content.
- **Migrations** must run on the direct host as `app_migrator`.
  - Advisory locks and prepared statements break through pgbouncer.
  - A `Schema::tenantTable()` helper adds the policy, FORCE, and `unique(organisation_id, id)`.
- **DOMPDF** has no flexbox or grid and uses a lot of memory on big registers. Render in the queue with simple table layouts.
- **The Filament 5 / Livewire 4 plugin ecosystem lags.** Keep plugins to the list above and build the bespoke grids in-house.
- **Neon scale-to-zero** adds cold-start latency, and always-on workers keep it awake. Set a production minimum CU.

## Verification
- **Automated tests:**
  - Pest tests for each Action.
  - Filament/Livewire tests for each resource: create, edit, delete and authorisation.
  - The isolation suite, the `pg_catalog` RLS test, and arch tests.
  - Webhook signature and replay tests.
- **CI:** Larastan, Pint and `composer audit`.
- **Browser walkthrough** (Playwright):
  - Seed two organisations, "Demo" and "Other".
  - Walk each module's create, analyse, approve and PDF path.
  - Re-run the cross-tenant attack scripts and confirm each is refused.
- **Laravel Cloud preview:**
  - Migrations run as the migrator.
  - A queued PDF lands in Object Storage.
  - The scheduler sends reminders.
  - An Airwallex sandbox checkout grants a module, and a cancel webhook revokes it.

## Defaults chosen (the builder may revisit)
- Mail provider: Resend (decided 2026-11-16).
- No Octane at launch.
- Plain-English role names (Owner, Admin, Editor, Approver, Viewer).
- Default risk matrices as above, and organisations can edit them.
