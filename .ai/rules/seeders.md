---
paths:
  - database/seeders/**
---

# Seeders

## Idempotent and offline
Seeders upsert by a stable natural key (e.g. control ref `A.5.15`, template key) so they can run on every deploy. They never call external APIs (Airwallex, mail, storage). CI runs `migrate:fresh --seed` as the migrator.

## The test user is local only
`DatabaseSeeder` runs the catalogue seeders anywhere, and adds `test@example.com` (whose password is the factory's public one) only when `APP_ENV=local`, and only once. Never seed a sign-in with a known password in any other environment (`DatabaseSeederTest`).

## Deploy seeders
Deploys run the code-defined catalogues as the migrator after migrating: `php artisan db:seed --class=PermissionSeeder --database=pgsql_migrator --force` and `php artisan db:seed --class=VendorSeeder --database=pgsql_migrator --force`. Global catalogue tables (`permissions`, `vendors`) revoke writes from `app_tenant`, so only these seeders change them.

## Preview demo data
`PreviewSeeder` builds the demo organisation for Laravel Cloud preview environments, and only when `APP_ENV=preview` (`app:deploy` runs it there). It goes through the real Actions on the app's own connection, as a sign-up does: an organisation created inside the migrator's transaction would be invisible to the app's session. It is idempotent (checked by the organisation's slug), and refuses to create the demo owner without `PREVIEW_PASSWORD` (12+ characters) and `PREVIEW_MFA_SECRET`.

