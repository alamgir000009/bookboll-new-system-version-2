<?php

/**
 * Where a customer lands after Stripe Checkout.
 *
 * The product sells white-label storefronts, so the site a booking was made on is not
 * knowable from config: it is alamgir.bookboll.com, or a provider's own domain, or the
 * marketplace. Stripe's return hits this API, which then has to redirect — and before
 * this existed it always redirected to FRONTEND_URL, dropping every merchant's customer
 * onto bookboll.com the moment they paid or cancelled.
 *
 * The origin is therefore recorded when the Checkout session is created and re-validated
 * on the way back. These tests pin the validation, because a redirect target taken from
 * a header is an open redirector if the allowlist is wrong — and pin the two exclusions
 * that are easy to regress: the dashboards are not storefronts, and a caller with no
 * browser origin at all still has to go somewhere.
 */

use App\Support\TenantOrigins;

it('returns the merchant storefront a booking was made on', function () {
    expect(TenantOrigins::storefrontOrigin('https://alamgir.bookboll.com'))
        ->toBe('https://alamgir.bookboll.com');
});

it('reduces a referer to its origin', function () {
    // Origin is absent on same-site navigations, so Referer is the fallback — and it
    // always carries a path, which the origin check refuses outright.
    expect(TenantOrigins::storefrontOrigin('https://alamgir.bookboll.com/listing/9?slot=3'))
        ->toBe('https://alamgir.bookboll.com');
});

it('keeps a marketplace customer on the marketplace', function () {
    // The same listing is bookable from bookboll.com. Deriving the return address from
    // the provider instead of the origin would yank that customer onto a tenant site.
    expect(TenantOrigins::storefrontOrigin('https://bookboll.com/'))->toBe('https://bookboll.com');
});

it('never returns a customer to the provider dashboard', function (string $console) {
    // An admin generating a pay-link for a customer to open later is on admin.bookboll.com.
    // That origin is allowed to call the API, but sending the payer there would drop them
    // into an app they cannot sign in to.
    expect(TenantOrigins::storefrontOrigin($console))->toBeNull();
})->with([
    'https://admin.bookboll.com',
    'https://business.bookboll.com',
    'https://admin.bookboll.com/bookings/412',
]);

it('refuses an origin that is not ours', function (string $hostile) {
    expect(TenantOrigins::storefrontOrigin($hostile))->toBeNull();
})->with([
    'https://evil.com',
    // A suffix test would pass this one; the wildcard matches a single label.
    'https://alamgir.bookboll.com.evil.net',
    'https://evil.attacker.bookboll.com.example.net',
    // Scheme and port are part of the origin, not decoration.
    'http://alamgir.bookboll.com',
    'https://alamgir.bookboll.com:8443',
    'javascript:alert(1)',
    'not a url',
    '',
]);

it('has no storefront for a caller with no browser origin', function () {
    // The mobile app sends `from: admin_mobile_app` and no Origin — it must fall back to
    // FRONTEND_URL rather than being handed a null redirect.
    $request = Illuminate\Http\Request::create('/api/checkout', 'POST');
    $request->headers->set('from', 'admin_mobile_app');

    expect(TenantOrigins::storefrontOriginFromRequest($request))->toBeNull();
});

it('prefers the origin header over the referer', function () {
    $request = Illuminate\Http\Request::create('/api/checkout', 'POST');
    $request->headers->set('Origin', 'https://alamgir.bookboll.com');
    $request->headers->set('Referer', 'https://bookboll.com/listing/9');

    expect(TenantOrigins::storefrontOriginFromRequest($request))
        ->toBe('https://alamgir.bookboll.com');
});
