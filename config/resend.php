<?php

// Merged over resend/resend-laravel's own config. ComplyRobin sends mail through Resend (MAIL_MAILER=resend, set by
// Laravel Cloud's Resend integration) but doesn't handle Resend's webhooks yet. Without a signing secret the package's
// /resend/webhook route would accept unsigned requests, so it is off. Switch it on together with bounce handling, and
// only with RESEND_WEBHOOK_SECRET set.
return [
    'routes' => (bool) env('RESEND_ROUTES', false),
];
