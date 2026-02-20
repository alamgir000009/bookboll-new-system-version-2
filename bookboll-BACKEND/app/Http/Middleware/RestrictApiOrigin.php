<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class RestrictApiOrigin
{
    /**
     * Handle an incoming request.
     * Allow only: admin.bookboll.com, bookboll.com (via Origin/Referer), or mobile app (via header).
     */
    public function handle(Request $request, Closure $next): Response
    {
        if (config('api-restriction.disable_in_local', false)) {
            return $next($request);
        }

        $allowedOrigins = config('api-restriction.allowed_origins', []);
        $headerName     = config('api-restriction.mobile_app_header', 'X-App-Source');
        $mobileValue    = config('api-restriction.mobile_app_value', 'admin_mobile_app');

        // Allow mobile app when it sends the expected header
        $appSource = $request->header($headerName);
        if ($appSource !== null && trim((string) $appSource) === $mobileValue) {
            return $next($request);
        }

        // Allow web from configured origins (Origin is set by browser; Referer as fallback)
        $origin  = $request->header('Origin');
        $referer = $request->header('Referer');

        $host = null;
        if ($origin) {
            $host = $this->parseHost($origin);
        }
        if ($host === null && $referer) {
            $host = $this->parseHost($referer);
        }

        if ($host !== null && in_array($host, $allowedOrigins, true)) {
            return $next($request);
        }

        return response()->json([
            'success' => false,
            'message' => 'Access denied. Request origin is not allowed.',
        ], 403);
    }

    private function parseHost(?string $url): ?string
    {
        if ($url === null || $url === '') {
            return null;
        }
        $parsed = parse_url($url);
        if (! isset($parsed['host'])) {
            return null;
        }
        return strtolower($parsed['host']);
    }
}
