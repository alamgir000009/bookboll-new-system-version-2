<?php

namespace App\Support;

use App\Models\Business;
use Illuminate\Support\Facades\Cache;

/**
 * The one place that answers "is this host one of ours?".
 *
 * A tenant storefront is served from three shapes of address: the marketplace itself
 * (bookboll.com), a white-label subdomain every provider gets (<slug>.bookboll.com),
 * and the provider's own domain once they have paid to connect it. Only the first can
 * be written down; the other two are created at runtime, so they are matched by
 * pattern and by what providers have actually paid for.
 *
 * Two callers share this, and they must agree:
 *   • RestrictApiOrigin — may this host call the API at all?
 *   • OAuthBrokerService — may a Google sign-in be handed back to this origin?
 *
 * The second is the security-critical one. The broker finishes a sign-in and hands
 * back a credential, so an origin that slips through this check is account takeover:
 * any site could walk a customer through Google and collect their Bookboll session.
 * That is why the wildcard match below is one DNS label and not a suffix test.
 */
final class TenantOrigins
{
    private const CACHE_KEY = 'api-restriction.custom_domains';

    /** Host-only check (no scheme), as the API origin middleware needs. */
    public static function isAllowedHost(string $host): bool
    {
        $host = strtolower(trim($host));

        if ($host === '') {
            return false;
        }

        if (in_array($host, config('api-restriction.allowed_origins', []), true)) {
            return true;
        }

        foreach (config('api-restriction.allowed_origin_patterns', []) as $pattern) {
            if (self::matchesPattern($host, strtolower($pattern))) {
                return true;
            }
        }

        return config('api-restriction.allow_custom_domains', true)
            && in_array($host, self::customDomains(), true);
    }

    /**
     * Full-origin check for the OAuth broker: returns the canonical "scheme://host"
     * to hand a session back to, or null to refuse.
     *
     * Stricter than isAllowedHost() in three ways, because the return value is where
     * a credential gets delivered:
     *
     *   • the scheme must be https (loopback over http is allowed outside production,
     *     because the dev storefront runs on a localhost port),
     *   • a port is only tolerated on loopback, so bookboll.com:8443 is refused,
     *   • "disable_in_local" is deliberately NOT honoured. The API middleware turns
     *     itself off in local so the dev front-ends can call it; doing the same here
     *     would make every local build a wide-open redirector, and a habit that only
     *     holds in production is not a control.
     *
     * @param string|null $origin  Untrusted: the `return` query parameter.
     */
    public static function canonicalizeOrigin(?string $origin): ?string
    {
        if (! is_string($origin) || trim($origin) === '') {
            return null;
        }

        $parsed = parse_url(trim($origin));

        if ($parsed === false || ! isset($parsed['scheme'], $parsed['host'])) {
            return null;
        }

        // A URL carrying credentials or a path is not an origin — refuse rather than
        // silently trim, so a caller passing a full return URL fails loudly.
        if (isset($parsed['user'], $parsed['pass'])) {
            return null;
        }
        if (isset($parsed['path']) && $parsed['path'] !== '' && $parsed['path'] !== '/') {
            return null;
        }
        if (isset($parsed['query']) || isset($parsed['fragment'])) {
            return null;
        }

        $scheme = strtolower($parsed['scheme']);
        $host   = strtolower($parsed['host']);
        $port   = $parsed['port'] ?? null;

        if (self::isLoopback($host)) {
            if (app()->environment('production') || ! in_array($scheme, ['http', 'https'], true)) {
                return null;
            }

            return $port ? "{$scheme}://{$host}:{$port}" : "{$scheme}://{$host}";
        }

        if ($scheme !== 'https' || $port !== null) {
            return null;
        }

        return self::isAllowedHost($host) ? "https://{$host}" : null;
    }

    /**
     * `*` matches exactly ONE label, so `*.bookboll.com` allows
     * `padelrepublic.bookboll.com` but not `evil.attacker.bookboll.com.example.net`
     * — a plain "ends with" test would allow the latter.
     */
    public static function matchesPattern(string $host, string $pattern): bool
    {
        $regex = '/^'.str_replace('\*', '[a-z0-9]([a-z0-9-]*[a-z0-9])?', preg_quote($pattern, '/')).'$/i';

        return (bool) preg_match($regex, $host);
    }

    /**
     * Domains providers have paid to connect. Cached because the API middleware runs
     * this on every request; SiteSettingsService and ProviderPlanService clear it the
     * moment a domain is connected or removed, so a new domain works immediately.
     *
     * @return array<int, string>
     */
    public static function customDomains(): array
    {
        return Cache::remember(
            self::CACHE_KEY,
            (int) config('api-restriction.custom_domain_cache_ttl', 300),
            fn () => Business::query()
                ->whereNotNull('brand_custom_domain')
                ->where('brand_custom_domain', '!=', '')
                ->whereNotNull('brand_domain_paid_at')   // paid for, so genuinely theirs
                ->pluck('brand_custom_domain')
                ->map(fn ($d) => strtolower(trim($d)))
                ->filter()
                ->unique()
                ->values()
                ->all()
        );
    }

    public static function forget(): void
    {
        Cache::forget(self::CACHE_KEY);
    }

    private static function isLoopback(string $host): bool
    {
        return in_array($host, ['localhost', '127.0.0.1', '[::1]', '::1'], true);
    }
}
