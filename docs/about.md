# About ComplyRobin

**The bird that reminds you on time.** ComplyRobin is a Bizexcel product for small and growing teams.

- **The register:** it keeps track of the SaaS tokens, keys and certificates a business runs on. For each one it records who owns it, where it is used, when it was last rotated and when it is due.
- **The reminders:** it reminds the right people before a credential is due and keeps proof of every rotation for auditors.
- **ISO management systems:** the same register later feeds BCMS (ISO 22301) and ISMS (ISO 27001:2022).

ComplyRobin stores information *about* credentials, **never the secret values**. Free-text fields refuse anything that looks like a key, and the database has no column that could hold one.

## What it does today
- **Applications and credentials.** Each credential records:
  - its application (picked from a vendor catalogue, or added by hand) and its type and environment;
  - an owner and a backup owner, and where it is used;
  - an optional short hint (the first or last few characters);
  - a rotation interval or an expiry date.

  ComplyRobin works out when each one is due, and whether it is due soon or overdue, in the organisation's own time zone.
- **Rotations.** Recording a rotation moves the due date and keeps an append-only log. On Team, evidence files can be attached.
- **Reminders.**
  - **When:** before the due date (by default 14, 7 and 1 days) and while overdue (0, 3 and 7 days, then weekly for up to 12 weeks). Each reminder is sent exactly once.
  - **How:** a single digest per person, sent between 09:00 and 18:00 organisation time.
  - **Channels:** email, the in-app bell and a private calendar link (`.ics`). Slack, Microsoft Teams and Telegram can be switched on platform-wide later (`CHAT_CHANNELS`).
- **Escalation (Team).** Overdue reminders also reach the backup owner from day 3, and Owners and Admins from day 7.
- **Proof for auditors (Team).**
  - A rotation evidence report as PDF and CSV.
  - An audit trail page with a CSV export.
  - A History tab on every credential.
- **Bulk import (Team).** A CSV import of up to 1,000 credentials.
- **The organisation.**
  - Members, roles (Owner, Admin, Editor, Approver, Viewer) and invitations.
  - Seats against the plan.
  - Organisation settings and the reminder policy.
  - A dashboard.

## Plans
| | Free | Pro | Team | Enterprise |
|---|---|---|---|---|
| Price a month | free | USD 29 (RM 99) | USD 99 (RM 349) | by quote |
| Members | 1 | 5 | 10 | custom |
| Credentials | 5 | 100 | unlimited | unlimited |
| Reminders | email, in-app, calendar | plus Slack, Teams and Telegram | plus WhatsApp and escalation | same |
| Proof | rotation log | plus CSV import | plus evidence files, evidence report and audit export | same |
| Automatic rotation | no | no | yes | yes |

Paying yearly gives two months free. The numbers live in `config/plans.php` and the prices in Airwallex (see [Airwallex](airwallex.md)). Customers subscribe to Pro or Team from Organisation → Billing; staff can also grant Pro, Team or Enterprise from the admin panel. Moving from Pro to Team is done by staff for now. Downgrading never deletes anything: paid features pause, and existing records stay usable.

## How it is built
- **Stack:** PHP 8.5, Laravel 13, Filament 5, Postgres 17, and Pest 5 for tests.
- **Hosting:** Laravel Cloud.
- **Panels:**
  - The customer panel lives at `/{organisation-slug}`, for example `app.complyrobin.com/acme`.
  - Staff use `/admin`: **Admins** grant plans and manage staff, **Support** can look but not change.
- **Code layers:**
  - `app/Core`: tenancy, organisations, identity, access, people, entitlements, files, audit, notifications.
  - `app/Grc`: the shared ISO engine (coming in Phase 4).
  - `app/Modules/Credentials`: the credentials register. BCMS and ISMS will join it as modules.
  - `app/Filament/{App,Admin}`: the screens. Business rules live in `Actions` classes that the screens call.
- **Tenant isolation**, the most important design rule:
  - **Row-level security.** Every organisation-owned table has Postgres row-level security keyed on the current organisation.
  - **Three database logins:**
    - `app_tenant`: the app, which is subject to row-level security;
    - `app_platform`: staff work across organisations;
    - `app_migrator`: owns the tables; migrations and seeders.
  - **Per-statement tenant.** The organisation travels with every SQL statement rather than sitting in the database session. That keeps tenants apart even behind a connection pooler.
  - **Linked records.** Links between organisation-owned records use composite keys, so the database itself refuses a cross-organisation link.
- **Files** are private by default, stored under `org/{organisation id}/`, and handed out only through short-lived signed links.

## Where to read more
- **[Local setup](local-setup.md):** run it on your machine.
- **[Deploying to Laravel Cloud](laravel-cloud.md):** what to create and set in Laravel Cloud.
- **[Design spec](superpowers/specs/2026-11-15-complyrobin-design.md):** the full design and roadmap.
- **`.ai/rules/`:** the coding rules, indexed in `.ai/rules/index.md`. They are written for developers and AI agents, and enforced by tests.
