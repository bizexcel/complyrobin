<?php

// Preview environments (APP_ENV=preview, one per pull request on Laravel Cloud) get a demo organisation to review,
// seeded by PreviewSeeder. Reviewers sign in as the demo owner with this password and an authenticator app set up
// with this secret (generate one with: php artisan preview:mfa-secret). Both are set on the preview automation.
return [
    'owner_email' => env('PREVIEW_OWNER_EMAIL', 'demo@complyrobin.test'),
    'password' => env('PREVIEW_PASSWORD'),
    'mfa_secret' => env('PREVIEW_MFA_SECRET'),
];
