---
paths:
  - app/Core/Access/**
  - database/seeders/PermissionSeeder.php
---

# Access: roles and permissions

## Abilities are code
Every permission is a case of `App\Core\Access\Enums\Ability` (`organisation.update`, `members.manage`, …). `PermissionSeeder` writes them; the tenant database role can't. Deploys run `php artisan db:seed --class=PermissionSeeder --database=pgsql_migrator --force` after migrating. The system roles and what they grant live in `SystemRole::abilities()`; `CreateOrganisation` creates them for each new organisation and makes the creator Owner.

## Check inside the organisation
Authorise with `Gate::forUser($actor)->authorize(Ability::X->value)` (or `$user->can(...)`) inside `Tenancy::run()`. Outside an organisation nobody has a role, so every check is denied. Owner passes every check through `Gate::before` in `AccessServiceProvider`; spatie's own gate hook is turned off in favour of that one.

## spatie in teams mode, made tenant-safe
The team is the organisation and follows `Tenancy` automatically (the `TenantChanged` event), including spatie's permission cache, which is kept per organisation (`org:{id}:permissions:{version}`). `User` uses `HasOrganisationRoles`, which drops roles loaded for a previous organisation; never use spatie's `HasRoles` directly. Give a role permissions with `$role->givePermissionTo()`: `Role::permissions()` stamps the organisation on `role_has_permissions`. Roles and all three pivots have row-level security, and the pivots reference roles through composite keys. `AccessServiceProvider` and `RolesTest` enforce this.

## Invitations
Members join through `InviteMember` (needs `members.manage`; only an Owner invites an Owner; one open invitation per email, re-inviting replaces it) and `AcceptInvitation`. The emailed link carries the organisation's slug and a random token; only its sha-256 is stored. A link is accepted only by a signed-in user whose **verified** email is the invited one, so signing up with someone else's address gets nothing.
