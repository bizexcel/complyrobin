---
paths:
  - database/seeders/**
---

# Seeders

## Idempotent and offline
Seeders upsert by a stable natural key (e.g. control ref `A.5.15`, template key) so they can run on every deploy. They never call external APIs (Airwallex, mail, storage). CI runs `migrate:fresh --seed` as the migrator.
