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
- `POST {api}/runner/{slug}/claims` and `POST {api}/runner/{slug}/reports` (`runner.claims`, `runner.reports`): rotation runners, the organisation's own programs. `{api}` is `config('app.api')`: `API_DOMAIN` (e.g. `api.complyrobin.com`) with the prefix `v1`, or the app's own address with `api/v1`. They run without the `web` middleware (no session, cookies or CSRF), always answer JSON with `Cache-Control: no-store`, and authenticate with a bearer token (`crr_…`) of which only the sha-256 is stored. Anything wrong with the slug or the token is the same 401. Throttled per address and per token (`runner-api`). The slug may be a previous one. They never accept or return a secret value: every string a runner reports passes `NotASecret`.
- `POST /airwallex/webhook` (`cashier.webhook`, registered by `App\Billing\BillingServiceProvider` as Cashier registers `/stripe/webhook`): Airwallex's webhook calls. It runs without the `web` middleware (no session, cookies or CSRF) and outside any organisation; every call must carry a valid `x-signature`, and without a webhook secret every call is refused (see `billing.md`).
- `POST /api/enquiries` (`enquiries.store`, `routes/api.php`, no session) for the Astro site's contact form: the Turnstile token in `cf-turnstile-response` is checked with Cloudflare by `App\Core\Enquiries\Rules\Turnstile`, which fails closed (no `TURNSTILE_SECRET_KEY`, or Cloudflare unreachable, means refused); throttled per address (`enquiries` in `AppServiceProvider`: 5 a minute, 30 a day); CORS in `config/cors.php` allows only `POST` to this path from `ENQUIRY_ALLOWED_ORIGINS` (default `https://www.complyrobin.com`), without credentials. `SubmitEnquiry` validates and stores it (`EnquiryApiTest`).
