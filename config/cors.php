<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Cross-Origin Resource Sharing (CORS)
    |--------------------------------------------------------------------------
    |
    | Only the www contact form calls the app from another site: POST /api/enquiries, from www.complyrobin.com
    | (ENQUIRY_ALLOWED_ORIGINS, comma-separated, for a staging site). No cookies or credentials cross.
    |
    */

    'paths' => ['api/enquiries'],

    'allowed_methods' => ['POST'],

    'allowed_origins' => array_values(array_filter(array_map(trim(...), explode(',', (string) env('ENQUIRY_ALLOWED_ORIGINS', 'https://www.complyrobin.com'))))),

    'allowed_origins_patterns' => [],

    'allowed_headers' => ['Content-Type', 'Accept'],

    'exposed_headers' => [],

    'max_age' => 3600,

    'supports_credentials' => false,

];
