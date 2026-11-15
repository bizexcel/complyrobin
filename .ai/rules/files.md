---
paths:
  - app/Core/Files/**
  - config/filesystems.php
  - config/media-library.php
---

# Files and disks

## Private by default
Every file is private unless it is meant for the public: rotation evidence, reports and uploads waiting to be imported. Store them on `App\Core\Files\PrivateDisk::name()`: the default disk, unless `MEDIA_DISK` names another private one. Media library follows it automatically. Paths start with `org/{organisation id}/` (`TenantPathGenerator` for media). Hand them out only through short-lived signed links (`getTemporaryUrl()` / `Storage::temporaryUrl()`), after an authorisation check.

## Laravel Cloud Object Storage
Cloud Object Storage (Cloudflare R2) sets visibility per bucket, never per file, and each attached bucket replaces the disk of the same name at runtime (`LARAVEL_CLOUD_DISK_CONFIG`, after the config loads). So:
- **Private bucket:** attached as the environment's default disk. Every private file lands there.
- **Public bucket:** created only when something public ships (an organisation logo, say), attached with the disk name `public`, and used through `Storage::disk('public')`. Never put an organisation's records or evidence there.
- **Extra buckets:** when a feature needs its own storage (other retention, very large exports), attach another bucket under its own disk name and read that name from config set by an env variable, like `MEDIA_DISK`. Never hard-code bucket details.

Never set a per-file visibility (`visibility: 'public'`, `storePublicly()`, Filament `->visibility()`): R2 rejects per-object ACLs. Environment filesystems are ephemeral and not shared between replicas, so nothing persistent lives on a local disk in production. Enforced by `FilesTest`.
