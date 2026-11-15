---
paths:
  - app/Filament/**
  - app/Providers/Filament/**
---

# Filament

## Location
Filament code lives only in `app/Filament/App` (customer panel, tenant-aware) and `app/Filament/Admin` (staff panel). Domain namespaces never import Filament — the one exception is `App\Core\Identity\Models\User`, which must implement Filament's `FilamentUser` contract (enforced by `ArchTest`, which ignores only that class).

## Resources call Actions
Create/edit/delete handlers call `Actions`; don't put business rules in form or table closures.

## Validation is scoped
Use `scopedExists()` / `scopedUnique()` or `Rule::exists()->where('organisation_id', …)`. Plain `exists:` rules bypass Eloquent scopes.

## Shared GRC resources are written once
A shared resource is an abstract base with form/table schema classes; each framework (BCMS, ISMS) has a thin subclass that sets its framework and cluster. Disable global search on the base class and scope each subclass to its management system.

## Files and identifiers
Upload directories are closures that prefix `org/{organisation_id}/…`, with private visibility. Show the `reference`, never the uuid.

## Tenant middleware runs on Livewire requests
Register tenant middleware with `isPersistent: true` so Livewire update requests carry the tenant.
