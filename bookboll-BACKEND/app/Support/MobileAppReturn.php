<?php

namespace App\Support;

use Illuminate\Http\Request;
use MobileApps;

/**
 * Sending a customer back INTO the mobile app after a hosted Stripe Checkout.
 *
 * Stripe only accepts http(s) success/cancel URLs, so a phone can never be
 * returned to directly. Every checkout therefore comes back through this
 * backend first (StripeService::checkoutReturnUrls()), and PaymentController
 * decides where to send the customer next. For a browser that is the storefront
 * they started on; for the app it is the app's own URL scheme, which both the
 * iOS and Android builds register (`bookboll://`).
 *
 * The decision is stamped into the Checkout Session metadata when the session
 * is created, because at return time the only thing the backend has is the
 * session: a native app sends no Origin/Referer, and the return request comes
 * from whatever browser Stripe rendered in, not from the app.
 */
final class MobileAppReturn
{
    /** Metadata key on the Stripe Checkout Session. */
    public const METADATA_KEY = 'return_target';

    /** Metadata value meaning "reopen the customer mobile app". */
    public const TARGET_APP = 'customer_mobile_app';

    /** Path inside the app that every Stripe return lands on. */
    public const RETURN_PATH = 'payment/return';

    /**
     * What to stamp into session metadata for the current request.
     *
     * The customer app already identifies itself on every call with the project's
     * `from` header (MobileApps::CUSTOMER, see ApiClientSurfaces). An explicit
     * `return_target=app` body field is honoured too, for a client that cannot set
     * headers. Anything else — the website, a direct API call — gets an empty
     * string, and the return goes to the storefront exactly as before.
     */
    public static function targetFromRequest(?Request $request): string
    {
        if (! $request) {
            return '';
        }

        $header = (string) config('api-restriction.mobile_app_header', 'from');
        $from   = trim((string) $request->header($header, ''));

        if ($from === MobileApps::CUSTOMER) {
            return self::TARGET_APP;
        }

        if ($request->input(self::METADATA_KEY) === 'app') {
            return self::TARGET_APP;
        }

        return '';
    }

    /** Did this Checkout Session start in the mobile app? */
    public static function wantsApp($session): bool
    {
        if (! $session) {
            return false;
        }

        $metadata = $session->metadata ?? null;
        $value    = null;

        if (is_array($metadata)) {
            $value = $metadata[self::METADATA_KEY] ?? null;
        } elseif (is_object($metadata)) {
            $value = $metadata[self::METADATA_KEY] ?? ($metadata->{self::METADATA_KEY} ?? null);
        }

        return $value === self::TARGET_APP;
    }

    /** The app's URL scheme, without the "://". */
    public static function scheme(): string
    {
        $scheme = strtolower(trim((string) config('app.mobile_app_scheme', 'bookboll')));

        return preg_replace('/[^a-z0-9+.\-]/', '', $scheme) ?: 'bookboll';
    }

    /** Android application id, used to build an intent:// URL Chrome will honour. */
    public static function androidPackage(): string
    {
        $package = trim((string) config('app.mobile_app_android_package', 'com.bookboll.app'));

        return preg_replace('/[^A-Za-z0-9_.]/', '', $package) ?: 'com.bookboll.app';
    }

    /**
     * `bookboll://payment/return?status=success&session_id=cs_…`
     *
     * Empty and null values are dropped so the app never sees `listing_id=`.
     */
    public static function url(array $query = [], string $path = self::RETURN_PATH): string
    {
        $qs = http_build_query(self::cleanQuery($query), '', '&', PHP_QUERY_RFC3986);

        return self::scheme().'://'.ltrim($path, '/').($qs !== '' ? '?'.$qs : '');
    }

    /**
     * The same destination as an Android intent URL.
     *
     * Chrome on Android is the one browser that does not reliably follow a plain
     * custom-scheme navigation that was not started by a tap; an intent:// URL
     * naming the package is the form it is documented to honour. `browser_fallback_url`
     * is where Chrome goes when the app is not installed.
     */
    public static function androidIntentUrl(array $query = [], ?string $fallbackUrl = null, string $path = self::RETURN_PATH): string
    {
        $qs  = http_build_query(self::cleanQuery($query), '', '&', PHP_QUERY_RFC3986);
        $url = 'intent://'.ltrim($path, '/').($qs !== '' ? '?'.$qs : '')
            .'#Intent;scheme='.self::scheme()
            .';package='.self::androidPackage();

        if ($fallbackUrl) {
            $url .= ';S.browser_fallback_url='.rawurlencode($fallbackUrl);
        }

        return $url.';end';
    }

    /** @return array<string, string> */
    private static function cleanQuery(array $query): array
    {
        $clean = [];
        foreach ($query as $key => $value) {
            if ($value === null || $value === '' || $value === []) {
                continue;
            }
            $clean[(string) $key] = is_array($value) ? implode(',', $value) : (string) $value;
        }

        return $clean;
    }
}
