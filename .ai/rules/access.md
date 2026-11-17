---
paths:
  - app/Core/Access/**
  - app/Core/Identity/Actions/**
  - app/Filament/Admin/**
  - app/Core/Entitlements/**
  - database/seeders/PermissionSeeder.php
---

# Access: roles and permissions

## Abilities are code
Every permission is a case of `App\Core\Access\Enums\Ability` (`organisation.update`, `members.manage`, …). `PermissionSeeder` writes them; the tenant database role can't. Deploys run `php artisan db:seed --class=PermissionSeeder --database=pgsql_migrator --force` after migrating, then `php artisan access:sync-system-roles`, which adds new abilities to every organisation's system roles (`SyncSystemRoles` only ever adds). The system roles and what they grant live in `SystemRole::abilities()`; `CreateOrganisation` creates them for each new organisation and makes the creator Owner.

## Check inside the organisation
Authorise with `Gate::forUser($actor)->authorize(Ability::X->value)` (or `$user->can(...)`) inside `Tenancy::run()`. Outside an organisation nobody has a role, so every check is denied. Owner passes every check through `Gate::before` in `AccessServiceProvider`; spatie's own gate hook is turned off in favour of that one. Because Owners skip policies, a policy says only *who* may act; rules about a record's state (revoked, closed, already accepted) belong in the Action, where no role bypasses them.

## spatie in teams mode, made tenant-safe
The team is the organisation and follows `Tenancy` automatically (the `TenantChanged` event), including spatie's permission cache, which is kept per organisation (`org:{id}:permissions:{version}`). `User` uses `HasOrganisationRoles`, which drops roles loaded for a previous organisation; never use spatie's `HasRoles` directly. Give a role permissions with `$role->givePermissionTo()`: `Role::permissions()` stamps the organisation on `role_has_permissions`. Roles and all three pivots have row-level security, and the pivots reference roles through composite keys. `AccessServiceProvider` and `RolesTest` enforce this.

## Invitations
Members join through `InviteMember` (needs `members.manage`; only an Owner invites an Owner; one open invitation per email, re-inviting replaces it) and `AcceptInvitation`. `RevokeInvitation` withdraws an open one. The Members page (`App\Filament\App\Pages\Members`) shows members, open invitations and seats; every member sees it, inviting and revoking need `members.manage`. The emailed link carries the organisation's slug and a random token; only its sha-256 is stored. A link is accepted only by a signed-in user whose **verified** email is the invited one, so signing up with someone else's address gets nothing.

## Signing in
Both panels require multi-factor authentication (Filament's authenticator app with recovery codes, or an email code): a user without it is sent to set it up first. `login_attempts` records every sign-in and failed sign-in from Laravel's `Login`/`Failed` events; the tenant role may insert into it but never read it.

## Platform staff
ComplyRobin staff have a `platform_role` on their user (`PlatformRole`): any role opens `/admin`, but only an **Admin** may change anything there (grant modules, manage staff); **Support** only looks. Check with `$user->platform_role === PlatformRole::Admin` in the Action (`GrantModule`), and hide the control with `App\Filament\Admin\Actor::isAdmin()`. Every staff role change goes through `ChangeStaffRole`, the only code that writes `platform_role`: from the Staff page (an Admin, never on their own account) or from `php artisan staff:grant`/`staff:revoke` (no actor; console only, which is how the first Admin is made). It needs a verified email and multi-factor authentication, matches the email exactly as sign-in does, locks every Admin row so ComplyRobin always keeps one, and writes `staff_role_changes`: global, append-only for the platform role, invisible to the tenant role. Never seed or hard-code a staff email (`ChangeStaffRoleTest`, `StaffPageTest`).

## Modules are entitlements
Check a module with `app(Entitlements::class)->has(Module::Credentials)` inside the organisation. Entitlements belong to the root organisation and its sub-organisations share them (a row-level security policy lets a sub-organisation read its root's grants). Platform Admins grant modules with `GrantModule`; the tenant role can't write `module_grants`. Results are cached under `org:{root}:entitlements` for five minutes and cleared when a grant changes. A valid subscription of the module's type counts too (see `.ai/rules/billing.md`). Billing needs `billing.manage` (Owners, and Admins through `SystemRole::abilities()`), and only a root organisation has a Billing page.

## Plans, features and limits
Every organisation is on a `Plan` (Free, Team, Enterprise): Enterprise if granted, Team with any module (an ISO module includes Team), otherwise Free. Ask the current organisation's plan with `Entitlements::allows(Feature::X)` and `Entitlements::limit(Limit::X)` (null means unlimited); never compare plan names in feature code. The numbers live in `config/plans.php` (Free: 3 members, 10 credentials, email/in-app/calendar only; Team: 10 members, unlimited credentials, every paid feature). Enforce a limit in the Action that adds the record (`InviteMember` counts seats with `App\Core\Organisations\Seats::taken()`: members plus unexpired open invitations; `AcceptInvitation` re-checks). Downgrading never deletes or locks existing records: it only stops new ones.
