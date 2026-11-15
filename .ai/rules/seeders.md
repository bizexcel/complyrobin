---
paths:
  - database/seeders/**
---

# Seeders

## Idempotent and offline
Seeders upsert by a stable natural key (e.g. control ref `A.5.15`, template key) so they can run on every deploy. They never call external APIs (Airwallex, mail, storage). CI runs `migrate:fresh --seed` as the migrator.

## Deploy seeders
Deploys run the code-defined catalogues as the migrator after migrating: `php artisan db:seed --class=PermissionSeeder --database=pgsql_migrator --force` and `php artisan db:seed --class=VendorSeeder --database=pgsql_migrator --force`. Global catalogue tables (`permissions`, `vendors`) revoke writes from `app_tenant`, so only these seeders change them.
