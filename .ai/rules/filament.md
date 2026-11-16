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

## Outgoing webhooks
Which chat channel types ComplyRobin offers is a platform switch: `CHAT_CHANNELS` (`services.chat_channels.enabled`, e.g. `slack,teams,telegram`), empty by default so launch is email, in-app and calendar only. `ChannelType::isAvailable()` is the one check: the Chat channels pages are hidden when no type is available, actions refuse an unavailable type, and saved channels of a switched-off type keep their settings but get no reminders (checked when claiming and again when delivering). Team chat channels (`App\Core\Notifications`) post only to URLs that pass `AllowedWebhookUrl`: https, port 443, no user details, and a host from `config('services.chat_webhooks')` (exact for Slack, dot-prefixed suffix for Teams workflows). `ChatDelivery` re-checks the URL on every send, never follows redirects, uses short timeouts and never shows the remote reply. Webhook URLs are encrypted, hidden and write-only in forms (never filled back). A channel gets reminders only after `SendTestMessage` succeeds, and only while the plan allows it.

Telegram goes through ComplyRobin's bot (`services.telegram.bot_token`; the type is offered only when it is set), to a chat id the organisation enters. Anyone could name someone else's chat, so a Telegram channel is confirmed only by the code the bot sends being typed back (`VerificationCodes`: hashed, 10 minutes, 5 tries, 3 codes an hour), never by a test message. The token sits in the request URL: `ChatDelivery` never passes a transport exception on, and Nightwatch redacts `/bot…/` from outgoing request URLs (`AppServiceProvider`). Any future channel whose URL or headers hold a secret needs the same.
