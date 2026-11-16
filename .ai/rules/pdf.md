---
paths:
  - resources/views/pdf/**
---

# PDF views

## DOMPDF-safe markup
PDFs render with spatie/laravel-pdf's DOMPDF driver (Laravel Cloud has no Chromium), the default in `config/laravel-pdf.php` (`LARAVEL_PDF_DRIVER`); never pick a driver in code. Use tables and inline CSS only; no flexbox, grid or external assets. Images go inside the file as data URIs (the logo: `public/images/brand/complyrobin-logo.png`), never as URLs. Keep layouts driver-agnostic so the Cloudflare driver can replace DOMPDF later.

## Render in the queue
Generate PDFs in queued jobs and store them on the tenant's path; never render large registers inside a web request.
