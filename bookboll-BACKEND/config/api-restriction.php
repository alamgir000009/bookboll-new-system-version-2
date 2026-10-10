<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Allowed API Origins (exact hosts)
    |--------------------------------------------------------------------------
    |
    | Hosts without scheme. These are Bookboll's own front-ends.
    |
    */

    'allowed_origins' => [
        'bookboll.com',
        'www.bookboll.com',
        'admin.bookboll.com',
        'www.admin.bookboll.com',
        'business.bookboll.com',
        'www.business.bookboll.com',
    ],

    /*
    |--------------------------------------------------------------------------
    | Allowed Origin Patterns (wildcard subdomains)
    |--------------------------------------------------------------------------
    |
    | Every provider gets a white-label storefront at <slug>.bookboll.com, created
    | whenever a provider picks a web address. Those hosts cannot be enumerated in a
    | static list, so they are matched by pattern instead. `*` matches ONE label, so
    | `*.bookboll.com` allows `padelrepublic.bookboll.com` but not a deeper host.
    |
    */

    'allowed_origin_patterns' => [
        '*.bookboll.com',
    ],

    /*
    |--------------------------------------------------------------------------
    | Console Origins (dashboards, never storefronts)
    |--------------------------------------------------------------------------
    |
    | These hosts are allowed to call the API, but they are the provider dashboard —
    | not a place a paying customer may be redirected back to. TenantOrigins excludes
    | them when it records which storefront a Stripe Checkout started from, so an
    | admin-generated pay-link never sends the customer into the admin app. The
    | configured ADMIN_URL is excluded automatically on top of this list.
    |
    */

    'console_origins' => [
        'admin.bookboll.com',
        'www.admin.bookboll.com',
        'business.bookboll.com',
        'www.business.bookboll.com',
    ],

    /*
    |--------------------------------------------------------------------------
    | Provider Custom Domains
    |--------------------------------------------------------------------------
    |
    | Providers who paid the one-time fee serve their storefront from their OWN
    | domain (book.myclub.com). Those are created at runtime, so they are read from
    | the database and cached — this check runs on every API request.
    |
    */

    'allow_custom_domains' => env('API_ALLOW_CUSTOM_DOMAINS', true),
    'custom_domain_cache_ttl' => (int) env('API_CUSTOM_DOMAIN_TTL', 300),

    /*
    |--------------------------------------------------------------------------
    | Public Paths (no browser origin by nature)
    |--------------------------------------------------------------------------
    |
    | These are called by machines or by an email client, not by our own front-end,
    | so they carry no usable Origin/Referer and must never be origin-checked:
    |
    |   whatsapp/webhook      Meta posts delivery receipts and inbound messages here;
    |                         it is authenticated by its X-Hub-Signature-256 header.
    |   marketing/unsubscribe the one-click opt-out in a marketing email. Blocking it
    |                         would break a legal requirement, and the token is the
    |                         credential.
    |
    | Matched with Request::is(), so wildcards work.
    |
    */

    'public_paths' => [
        'api/whatsapp/webhook',
        'api/marketing/unsubscribe',
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
    | When true, the origin/mobile check is skipped (e.g. for local development,
    | where the front-ends run on localhost ports).
    |
    */

    'disable_in_local' => env('APP_ENV', 'production') !== 'production',

];
