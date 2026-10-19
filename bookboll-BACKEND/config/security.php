<?php

return [

    /*
    |--------------------------------------------------------------------------
    | HTTP Strict Transport Security
    |--------------------------------------------------------------------------
    |
    | Sent by App\Http\Middleware\SecurityHeaders, and only on HTTPS requests.
    |
    | max_age starts at one day on purpose. HSTS is cached by the browser, so a
    | wrong setting cannot simply be deployed away — a short window expires by
    | itself. Once every host that serves this app is confirmed HTTPS-only,
    | raise HSTS_MAX_AGE to 31536000 (one year).
    |
    | include_subdomains and preload are OFF by default. Providers get
    | subdomains and custom domains on this platform, so forcing HTTPS across
    | all of them — and preloading, which is close to permanent — belongs to
    | whoever owns the DNS and certificates, not to application code.
    |
    | Suggested ramp:
    |   1. HSTS_MAX_AGE=86400        (default — one day)
    |   2. HSTS_MAX_AGE=2592000      (30 days, after a week with no reports)
    |   3. HSTS_MAX_AGE=31536000     (one year)
    |   4. HSTS_INCLUDE_SUBDOMAINS=true, only once every subdomain and custom
    |      domain is verified HTTPS-only
    |   5. HSTS_PRELOAD=true, only if the platform is submitted to the list
    |
    */

    'hsts' => [
        'max_age'            => env('HSTS_MAX_AGE', 86400),
        'include_subdomains' => env('HSTS_INCLUDE_SUBDOMAINS', false),
        'preload'            => env('HSTS_PRELOAD', false),
    ],

];
