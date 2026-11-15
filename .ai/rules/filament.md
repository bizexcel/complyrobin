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
Attach files through spatie medialibrary (`HasMedia` on the model, `SpatieMediaLibraryFileUpload` in forms): `App\Core\Files\TenantPathGenerator` stores them under `org/{organisation_id}/{uuid}/` on the private `MEDIA_DISK`, and the `media` table is tenant-scoped. Serve files through temporary signed URLs only. Show the `reference`, never the uuid.

## In-app notifications
Both panels show database notifications. They belong to a user across organisations, so send only short text and deep links, never sensitive content; row-level security lets each user read only their own.

## Tenant middleware runs on Livewire requests
Register tenant middleware with `isPersistent: true` so Livewire update requests carry the tenant.
