<?php

// CORS for the website enquiry endpoint (POST /api/enquiries).
// Only the origins in WEBSITE_ALLOWED_ORIGINS (comma-separated, e.g. https://www.smash.example) may call it from a browser.
return [

    'paths' => ['api/*'],

    'allowed_methods' => ['POST', 'OPTIONS'],

    'allowed_origins' => array_values(array_filter(array_map(
        fn ($o) => rtrim(trim($o), '/'),
        explode(',', (string) env('WEBSITE_ALLOWED_ORIGINS', ''))
    ))),

    'allowed_origins_patterns' => [],

    'allowed_headers' => ['Content-Type', 'Accept', 'X-Requested-With'],

    'exposed_headers' => [],

    'max_age' => 3600,

    'supports_credentials' => false,

];
