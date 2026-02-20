<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Allowed API Origins (Web)
    |--------------------------------------------------------------------------
    |
    | Only requests from these origins (hosts) are allowed. Used for
    | Origin/Referer validation. Add hosts without scheme (e.g. admin.bookboll.com).
    |
    */

    'allowed_origins' => [
        'admin.bookboll.com',
        'www.admin.bookboll.com',
        'bookboll.com',
        'www.bookboll.com',
    ],

    /*
    |--------------------------------------------------------------------------
    | Mobile App Identification
    |--------------------------------------------------------------------------
    |
    | Requests from the mobile app must send this header with this value.
    | Header name is case-insensitive (e.g. X-App-Source).
    |
    */

    'mobile_app_header' => env('API_MOBILE_APP_HEADER', 'from'),
    'mobile_app_value'  => env('API_MOBILE_APP_VALUE', 'admin_mobile_app'),

    /*
    |--------------------------------------------------------------------------
    | Disable in Local
    |--------------------------------------------------------------------------
    |
    | When true, origin/mobile check is skipped (e.g. for local development).
    |
    */

    'disable_in_local' => env('APP_ENV', 'production') !== 'production',

];
