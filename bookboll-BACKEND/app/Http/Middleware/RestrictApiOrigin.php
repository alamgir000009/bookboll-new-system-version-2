<?php

namespace App\Http\Middleware;

use App\Models\Business;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Symfony\Component\HttpFoundation\Response;

/**
 * Only Bookboll's own front-ends may call the API from a browser.
 *
 * "Our own front-ends" is more than a fixed list, because the product sells the
 * opposite: every provider gets a white-label storefront at <slug>.bookboll.com, and
 * those who pay the one-time fee serve it from their own domain entirely. Both are
 * created at runtime, so this matches by pattern and by the domains providers have
 * actually paid to connect — an exact-match list would 403 the whole white-label
 * feature the moment it were switched on.
 *
 * Machine callers are exempt by PATH, not by luck: Meta's webhook and the one-click
 * unsubscribe link carry no usable Origin. Each has its own credential (a signature
 * header, and the recipient's token), so origin adds nothing there and blocking them
 * would break inbound WhatsApp and a legal opt-out obligation.
 */
class RestrictApiOrigin
{
    private const CACHE_KEY = 'api-restriction.custom_domains';

    public function handle(Request $request, Closure $next): Response
    {
        if (config('api-restriction.disable_in_local', false)) {
            return $next($request);
        }

        // Machine callers with their own authentication — see the class docblock.
        foreach (config('api-restriction.public_paths', []) as $path) {
            if ($request->is($path)) {
                return $next($request);
            }
        }

        // The mobile app, which has no Origin at all.
        $headerName  = config('api-restriction.mobile_app_header', 'from');
        $mobileValue = config('api-restriction.mobile_app_value', 'admin_mobile_app');
        $appSource   = $request->header($headerName);
        if ($appSource !== null && trim((string) $appSource) === $mobileValue) {
            return $next($request);
        }

        // Origin is set by the browser on cross-origin calls; Referer is the fallback.
        $host = $this->parseHost($request->header('Origin'))
            ?? $this->parseHost($request->header('Referer'));

        if ($host !== null && $this->isAllowed($host)) {
            return $next($request);
        }

        return response()->json([
            'success' => false,
            'message' => 'Access denied. Request origin is not allowed.',
        ], 403);
    }

    protected function isAllowed(string $host): bool
    {
        if (in_array($host, config('api-restriction.allowed_origins', []), true)) {
            return true;
        }

        foreach (config('api-restriction.allowed_origin_patterns', []) as $pattern) {
            if ($this->matchesPattern($host, strtolower($pattern))) {
                return true;
            }
        }

        return config('api-restriction.allow_custom_domains', true)
            && in_array($host, $this->customDomains(), true);
    }

    /**
     * `*` matches exactly ONE label, so `*.bookboll.com` allows
     * `padelrepublic.bookboll.com` but not `evil.attacker.bookboll.com.example.net`
     * — a plain "ends with" test would allow the latter.
     */
    protected function matchesPattern(string $host, string $pattern): bool
    {
        $regex = '/^'.str_replace('\*', '[a-z0-9]([a-z0-9-]*[a-z0-9])?', preg_quote($pattern, '/')).'$/i';

        return (bool) preg_match($regex, $host);
    }

    /**
     * Domains providers have paid to connect. Cached because this runs on every API
     * request; SiteSettingsService and ProviderPlanService clear it the moment a
     * domain is connected or removed, so a new domain works immediately.
     *
     * @return array<int, string>
     */
    protected function customDomains(): array
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

    /** Called wherever a custom domain is connected, verified or removed. */
    public static function forgetCustomDomains(): void
    {
        Cache::forget(self::CACHE_KEY);
    }

    private function parseHost(?string $url): ?string
    {
        if ($url === null || $url === '') {
            return null;
        }
        $parsed = parse_url($url);

        return isset($parsed['host']) ? strtolower($parsed['host']) : null;
    }
}
