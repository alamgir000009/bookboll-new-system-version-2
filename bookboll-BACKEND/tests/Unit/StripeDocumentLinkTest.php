<?php

use App\Support\StripeDocumentLink as Link;

/**
 * The dashboard opens these links in a new tab, so the only thing that may ever
 * come back is an https page on stripe.com. The admin client enforces the same
 * rule; this is the server half, so a tampered or mis-migrated row cannot put
 * another host in front of staff.
 */
it('accepts stripe.com and its subdomains over https', function (string $url) {
    expect(Link::sanitize($url))->toBe($url);
})->with([
    'https://invoice.stripe.com/i/acct_1/live_abc',
    'https://pay.stripe.com/receipts/acct_1/ch_123',
    'https://stripe.com/receipts/ch_123',
]);

it('refuses anything that is not an https stripe.com page', function ($url) {
    expect(Link::sanitize($url))->toBeNull();
})->with([
    'http://invoice.stripe.com/i/abc',        // not https
    'https://stripe.com.evil.test/receipt',   // suffix lookalike
    'https://notstripe.com/receipt',
    'https://evil.test/https://stripe.com',
    'javascript:alert(1)',
    'https://user:pw@invoice.stripe.com/i/a', // credentials in the URL
    '//invoice.stripe.com/i/abc',             // no scheme
    'invoice.stripe.com/i/abc',
    '',
    '   ',
    null,
    12345,
    [['https://stripe.com']],   // an array is not a link
]);

it('trims surrounding whitespace rather than rejecting the link', function () {
    expect(Link::sanitize("  https://invoice.stripe.com/i/abc  "))
        ->toBe('https://invoice.stripe.com/i/abc');
});

it('refuses an absurdly long value instead of storing it in a row', function () {
    expect(Link::sanitize('https://invoice.stripe.com/i/' . str_repeat('a', 2100)))->toBeNull();
});

it('offers a document only for money Stripe actually captured', function () {
    expect(Link::appliesTo('online', 'paid'))->toBeTrue()
        ->and(Link::appliesTo('card', 'paid'))->toBeTrue()
        ->and(Link::appliesTo('credit_card', 'paid'))->toBeTrue();
});

it('offers no document for cash, unpaid rows or provider slot blocks', function (array $row) {
    expect(Link::appliesTo($row[0], $row[1]))->toBeFalse();
})->with([
    [['cash', 'paid']],
    [['cheque', 'paid']],
    [['wire_transfer', 'paid']],
    [['payment_skipped', 'paid']],   // a provider reserving their own slot
    [['online', 'pending']],
    [['online', 'refunded']],
    [['online', 'cancelled']],
    [[null, null]],
]);
