---
paths:
  - resources/views/pdf/**
---

# PDF views

## DOMPDF-safe markup
PDFs render with spatie/laravel-pdf's DOMPDF driver (Laravel Cloud has no Chromium). Use tables and inline CSS only; no flexbox, grid or external assets. Keep layouts driver-agnostic so the Cloudflare driver can replace DOMPDF later.

## Render in the queue
Generate PDFs in queued jobs and store them on the tenant's path; never render large registers inside a web request.
