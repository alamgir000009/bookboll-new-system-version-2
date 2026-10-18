<?php

namespace App\Support;

/**
 * Stripe's hosted invoice / receipt pages, as served to the dashboard.
 *
 * The admin client refuses anything that is not an https link on stripe.com
 * (admin/src/lib/stripeInvoice.js). The same rule is applied here so a bad or
 * tampered row can never put another host in front of staff, and so the two
 * ends cannot drift apart: a link this class rejects is returned as null, which
 * the client already renders as "not available yet".
 */
class StripeDocumentLink
{
    /** Stripe serves these pages from stripe.com and its subdomains only. */
    protected const HOST = 'stripe.com';

    /**
     * The link, or null when it is absent or not a Stripe https URL.
     * Never throws: a malformed value is simply not a link.
     */
    public static function sanitize($url): ?string
    {
        if (! is_string($url)) {
            return null;
        }

        $url = trim($url);
        if ($url === '' || mb_strlen($url) > 2048) {
            return null;
        }

        $parts = parse_url($url);
        if ($parts === false || ! is_array($parts)) {
            return null;
        }

        $scheme = strtolower((string) ($parts['scheme'] ?? ''));
        $host   = strtolower((string) ($parts['host'] ?? ''));

        if ($scheme !== 'https' || $host === '') {
            return null;
        }
        if ($host !== self::HOST && ! str_ends_with($host, '.' . self::HOST)) {
            return null;
        }
        // A credential in the URL would be shipped to the browser as-is.
        if (isset($parts['user']) || isset($parts['pass'])) {
            return null;
        }

        return $url;
    }

    /**
     * Whether this payment is one the dashboard offers a Stripe document for:
     * money actually captured through Stripe. A cash or bank payment has no
     * hosted page, and an unpaid row has nothing to show yet.
     */
    public static function appliesTo($method, $status): bool
    {
        $method = strtolower((string) $method);
        $status = strtolower((string) $status);

        return in_array($method, ['online', 'card', 'credit_card'], true)
            && $status === 'paid';
    }
}
