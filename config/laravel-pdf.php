<?php

// Merged over spatie/laravel-pdf's own config. Laravel Cloud has no Chromium, so PDFs render with DOMPDF; the
// Cloudflare driver can replace it later without code changes.
return [
    'driver' => env('LARAVEL_PDF_DRIVER', 'dompdf'),
];
