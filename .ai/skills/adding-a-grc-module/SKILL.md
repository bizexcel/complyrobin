---
name: adding-a-grc-module
description: Add a new management-system framework module (e.g. ERM ISO 31000 or GHG ISO 14064) on top of the shared GRC engine without copying BCMS/ISMS code.
---

# Adding a GRC module

## When to use this skill
Use it to add a framework alongside BCMS and ISMS.

## Steps
1. Add a case to the `Framework` enum and a `ModuleDefinition` in `app/Modules/<Key>/<Key>Module.php` (key, catalogue and template seeders, cluster, default risk matrix, module-only resources). Register it in the `ModuleRegistry`.
2. Seed the standard's clauses/controls into `framework_requirements` and its templates, idempotently by stable key.
3. Run `php artisan grc:framework <key>` to generate the thin subclasses of the shared resources (policy, objectives, context, risk, evaluations, audits, reviews, incidents, non-conformities). Do not copy resource code.
4. Put framework-only features in `app/Modules/<Key>`; `app/Grc` must not depend on them (arch test).
5. Billing: the module key is the subscription type — `newSubscription('<key>', $price)`; add its plan/price and run `airwallex:sync-prices`.
6. Run the shared resource test dataset for the new framework plus module-specific tests.
