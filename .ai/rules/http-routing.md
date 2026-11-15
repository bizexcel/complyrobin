---
paths:
  - routes/**
  - app/Http/**
---

# HTTP and routing

## Organisation slugs
The customer panel lives at `/{organisation-slug}`. A non-member requesting a slug gets 404, not 403. Slugs are 3–40 chars of `[a-z0-9-]` and never a word in `App\Core\Organisations\ReservedSlugs`: a new top-level route path must be added there in the same change. Generate slugs with `OrganisationSlugs::generate()` and validate them with the `OrganisationSlug` rule; renames go through `RenameOrganisationSlug`, which keeps the old slug in `previous_slugs` so it redirects and can't be claimed. No lookups by uuid or number in URLs.

## Public API
The only public endpoint is `POST /api/enquiries` for the Astro site: Turnstile-verified, rate limited, CORS limited to www.complyrobin.com.
