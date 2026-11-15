---
paths:
  - routes/**
  - app/Http/**
---

# HTTP and routing

## Organisation slugs
The customer panel lives at `/{organisation-slug}`. A non-member requesting a slug gets 404, not 403. Slugs are 3–40 chars of `[a-z0-9-]` and never one of the reserved words (admin, api, app, www, login, logout, register, billing, settings, livewire, filament, storage, up, health, airwallex, two-factor, password, email, invitations, docs, help, support, status). No lookups by uuid or number in URLs.

## Public API
The only public endpoint is `POST /api/enquiries` for the Astro site: Turnstile-verified, rate limited, CORS limited to www.complyrobin.com.
