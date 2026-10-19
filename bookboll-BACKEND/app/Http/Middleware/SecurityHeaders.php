<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Response headers the browser needs in order to protect our own pages.
 *
 * This backend serves two very different things from one origin: the JSON API
 * the three Vue apps call, and real HTML — the Livewire super-admin panel, the
 * sign-in form and the Stripe return pages. The HTML half is what these
 * headers are for; a session-bearing admin panel with no clickjacking or
 * sniffing protection is the actual exposure.
 *
 * Deliberately conservative, because this runs in production:
 *
 *  - Nothing already set by a controller or the web server is overwritten, so
 *    a response that has thought about its own framing keeps its answer.
 *  - SAMEORIGIN rather than DENY. Nothing frames this origin today (the
 *    provider embed widget frames the storefront app, not the API), but DENY
 *    would also break a same-origin frame — a PDF preview inside the admin
 *    panel, say — and SAMEORIGIN stops cross-origin clickjacking just as well.
 *  - No Content-Security-Policy. Livewire needs inline script, so a policy
 *    guessed from here would break the admin panel silently. A CSP belongs on
 *    this origin, but it has to be built report-only first against real
 *    traffic; shipping one blind would be worse than not having it.
 */
class SecurityHeaders
{
    /**
     * Headers every response gets. Each one is safe for JSON and HTML alike.
     *
     * Referrer-Policy is here for privacy as much as security: without it, the
     * full URL of a page — which can carry a booking or account id — is sent
     * to any third-party host the page talks to.
     */
    private const HEADERS = [
        'X-Content-Type-Options' => 'nosniff',
        'X-Frame-Options'        => 'SAMEORIGIN',
        'Referrer-Policy'        => 'strict-origin-when-cross-origin',
    ];

    public function handle(Request $request, Closure $next): Response
    {
        return self::apply($request, $next($request));
    }

    /**
     * The headers, applied to any response.
     *
     * Public and static because the middleware pipeline is not the only path a
     * response can take: an authentication failure is rendered by the exception
     * handler, which unwinds without the middleware's response phase. Those 401
     * and 403 answers were going out bare — including the admin area's — so
     * bootstrap/app.php calls this from $exceptions->respond() too, and there is
     * one definition rather than two that can drift.
     */
    public static function apply(Request $request, Response $response): Response
    {
        foreach (self::HEADERS as $header => $value) {
            if (! $response->headers->has($header)) {
                $response->headers->set($header, $value);
            }
        }

        self::applyTransportSecurity($request, $response);

        return $response;
    }

    /**
     * HSTS, which is the one header here that is awkward to take back.
     *
     * Three deliberate restraints:
     *
     *  - Only on a secure request. Over plain HTTP the header is meaningless
     *    and browsers ignore it.
     *  - `includeSubDomains` is OFF unless explicitly switched on. Providers
     *    get subdomains and custom domains on this platform; forcing HTTPS
     *    across every one of them from here could take a working site down,
     *    and the people who own that decision are the ones deploying.
     *  - `preload` is OFF unless explicitly switched on. Submitting to the
     *    preload list is close to permanent and is a deployment decision, not
     *    a code one.
     *
     * The default max-age is one day: long enough to be real protection,
     * short enough that a mistake expires by itself. Raise it to 31536000
     * (a year) through HSTS_MAX_AGE once HTTPS is confirmed everywhere.
     */
    private static function applyTransportSecurity(Request $request, Response $response): void
    {
        if (! $request->isSecure() || $response->headers->has('Strict-Transport-Security')) {
            return;
        }

        $maxAge = (int) config('security.hsts.max_age');
        if ($maxAge <= 0) {
            return;   // switched off entirely
        }

        $value = 'max-age='.$maxAge;

        if (config('security.hsts.include_subdomains')) {
            $value .= '; includeSubDomains';
        }

        // Preload is only honoured with includeSubDomains, so asking for one
        // without the other would advertise something that cannot apply.
        if (config('security.hsts.preload') && config('security.hsts.include_subdomains')) {
            $value .= '; preload';
        }

        $response->headers->set('Strict-Transport-Security', $value);
    }
}
