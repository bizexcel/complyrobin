<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Airwallex Keys
    |--------------------------------------------------------------------------
    |
    | The Airwallex client ID and API key give you access to Airwallex's
    | API. Both are exchanged for a short-lived bearer token, which is
    | cached until it expires, before any private endpoint is called.
    |
    */

    'client_id' => env('AIRWALLEX_CLIENT_ID'),

    'api_key' => env('AIRWALLEX_API_KEY'),

    /*
    |--------------------------------------------------------------------------
    | Airwallex Environment
    |--------------------------------------------------------------------------
    |
    | Airwallex runs a "demo" environment for testing and a "production"
    | environment for live payments. Each has its own keys and its own
    | base URL. The API version is pinned so payloads never change.
    |
    */

    'env' => env('AIRWALLEX_ENV', 'demo'),

    'api_version' => env('AIRWALLEX_API_VERSION', '2025-06-16'),

    /*
    |--------------------------------------------------------------------------
    | Cashier Path
    |--------------------------------------------------------------------------
    |
    | This is the base URI path where Cashier's routes, such as the webhook
    | endpoint, will be available from. You're free to tweak this path
    | according to your preferences and application design.
    |
    */

    'path' => env('CASHIER_PATH', 'airwallex'),

    /*
    |--------------------------------------------------------------------------
    | Airwallex Webhooks
    |--------------------------------------------------------------------------
    |
    | Your Airwallex webhook secret is used to prevent unauthorized requests to
    | your Airwallex webhook handling controllers. The tolerance setting will
    | check the drift between the current time and the signed request's.
    |
    */

    'webhook' => [
        'secret' => env('AIRWALLEX_WEBHOOK_SECRET'),
        'tolerance' => env('AIRWALLEX_WEBHOOK_TOLERANCE', 300),
    ],

    /*
    |--------------------------------------------------------------------------
    | Currency
    |--------------------------------------------------------------------------
    |
    | This is the default currency that will be used when generating charges
    | from your application. Of course, you are welcome to use any of the
    | various world currencies that are currently supported via Airwallex.
    |
    */

    'currency' => env('BILLING_CURRENCY', 'myr'),

    /*
    |--------------------------------------------------------------------------
    | Currency Locale
    |--------------------------------------------------------------------------
    |
    | This is the default locale in which your money values are formatted in
    | for display. To utilize other locales besides the default en locale
    | verify you have the "intl" PHP extension installed on the system.
    |
    */

    'currency_locale' => env('CASHIER_CURRENCY_LOCALE', 'en_MY'),

    /*
    |--------------------------------------------------------------------------
    | Airwallex Logger
    |--------------------------------------------------------------------------
    |
    | This setting defines which logging channel will be used by the Airwallex
    | client to write log messages. You are free to specify any of your
    | logging channels listed inside the "logging" configuration file.
    |
    */

    'logger' => env('CASHIER_LOGGER'),

];
