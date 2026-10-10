<?php

namespace App\Http\Middleware;

use App\Support\TenantOrigins;
use Closure;
use Illuminate\Http\Request;
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
 * The allowlist itself lives in App\Support\TenantOrigins, shared with the OAuth
 * broker: the set of hosts allowed to call the API and the set allowed to receive a
 * brokered sign-in must be the same set, or one of them is wrong.
 *
 * Machine callers are exempt by PATH, not by luck: Meta's webhook and the one-click
 * unsubscribe link carry no usable Origin. Each has its own credential (a signature
 * header, and the recipient's token), so origin adds nothing there and blocking them
 * would break inbound WhatsApp and a legal opt-out obligation.
 */
class RestrictApiOrigin
{
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

        if ($host !== null && TenantOrigins::isAllowedHost($host)) {
            return $next($request);
        }

        return response()->json([
            'success' => false,
            'message' => 'Access denied. Request origin is not allowed.',
        ], 403);
    }

    /** Called wherever a custom domain is connected, verified or removed. */
    public static function forgetCustomDomains(): void
    {
        TenantOrigins::forget();
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
