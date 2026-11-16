---
paths:
  - routes/**
  - app/Http/**
---

# HTTP and routing

## Organisation slugs
The customer panel lives at `/{organisation-slug}`. A non-member requesting a slug gets 404, not 403. Slugs are 3–40 chars of `[a-z0-9-]` and never a word in `App\Core\Organisations\ReservedSlugs`: a new top-level route path must be added there in the same change (including paths a package serves outside the router, such as Octane's `/octane/…`). Generate slugs with `OrganisationSlugs::generate()` and validate them with the `OrganisationSlug` rule; renames go through `RenameOrganisationSlug`, which keeps the old slug in `previous_slugs` so it redirects and can't be claimed. An old slug redirects members to the current one and is a 404 for everyone else (`App\Filament\App\Tenancy\ResolveOrganisation`). `AppPanelTenancyTest` fails if any route's first path segment could be a slug but isn't reserved. No lookups by uuid or number in URLs.

## Routes outside the panels
- `GET /invitations/{slug}/{token}` (`invitations.accept`, throttled): guests are sent to sign in and back; anything wrong with the link is a plain 404.
- `GET /calendar/{slug}/{token}.ics` (`calendar.feed`): a member's private calendar link, fetched by calendar apps. It runs without the `web` middleware (no session, cookies or CSRF) and is throttled per address and per link (`calendar-feed` in `AppServiceProvider`). The slug may be a previous one, so links survive a rename. Only a hash of the token is stored; the link is a 404 once it is replaced or switched off, or its member has left. The feed (`RenderCalendarFeed`) never carries the hint, `used_in` or notes.
- `POST /api/enquiries` for the Astro site: Turnstile-verified, rate limited, CORS limited to www.complyrobin.com.
