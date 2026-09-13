<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Cross-Origin Resource Sharing (CORS) Configuration
    |--------------------------------------------------------------------------
    |
    | Here you may configure your settings for cross-origin resource sharing
    | or "CORS". This determines what cross-origin operations may execute
    | in web browsers. You are free to adjust these settings as needed.
    |
    | To learn more: https://developer.mozilla.org/en-US/docs/Web/HTTP/CORS
    |
    */

    'paths'                    => ['api/*', 'sanctum/csrf-cookie', 'broadcasting/auth', 'api/broadcasting/auth'],

    'allowed_methods'          => ['*'],

    'allowed_origins'          => [
        'http://localhost:8081',
        'http://localhost:5173',
        'http://localhost:5174',
        'http://localhost:5175',

        'https://bookboll.com',
        'https://new.bookboll.com',
        'https://newadmin.bookboll.com',
        'https://www.bookboll.com',
        'https://admin.bookboll.com',
        'https://www.admin.bookboll.com',
        'https://business.bookboll.com',
        'https://www.business.bookboll.com',

        'https://staging.bookboll.com',
        'https://stagingadmin.bookboll.com',
    ],

    'allowed_origins_patterns' => [
        '#^https?://([\w-]+\.)?bookboll\.com$#',
        '#^http://localhost(:\d+)?$#',
        '#^http://127\.0\.0\.1(:\d+)?$#',
    ],

    'allowed_headers'          => ['*'],

    'exposed_headers'          => [],

    'max_age'                  => 0,

    'supports_credentials'     => false,

];
