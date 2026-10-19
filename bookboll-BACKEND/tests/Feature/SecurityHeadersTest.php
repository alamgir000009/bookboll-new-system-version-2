<?php

/**
 * Response security headers.
 *
 * The staging API was serving none of these. It matters more than it looks,
 * because this origin serves real HTML as well as JSON: the Livewire
 * super-admin panel, the sign-in form and the Stripe return pages all carry a
 * session, and a session-bearing page with no clickjacking or MIME-sniffing
 * protection is the actual exposure.
 *
 * These pin the cautious choices as much as the headers themselves. On a live
 * platform the dangerous mistake is not a missing header — it is a header that
 * takes a working provider domain down, or one that cannot be withdrawn.
 */

use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

/**
 * The headers as a real request receives them.
 *
 * `$secure` asks for an https URL rather than a server variable: isSecure()
 * reads the request's own scheme, and withServerVariables() does not set it.
 */
function headersFor(string $path = '/api/marketplace/countries', bool $secure = false): array
{
    $url = ($secure ? 'https://localhost' : '').$path;

    $response = test()->withHeader('Accept', 'application/json')->get($url);

    return collect($response->headers->allPreserveCase())
        ->map(fn ($v) => is_array($v) ? ($v[0] ?? '') : $v)
        ->all();
}

it('sends the headers that are safe on every response', function () {
    $headers = headersFor();

    expect($headers['X-Content-Type-Options'])->toBe('nosniff');
    expect($headers['Referrer-Policy'])->toBe('strict-origin-when-cross-origin');
});

it('allows same-origin framing rather than denying it outright', function () {
    // DENY would stop cross-origin clickjacking and also break a same-origin
    // frame — a PDF preview inside the admin panel, say. SAMEORIGIN stops the
    // attack without that cost, and nothing cross-origin frames this host:
    // the provider embed widget frames the storefront app, not the API.
    expect(headersFor()['X-Frame-Options'])->toBe('SAMEORIGIN');
});

it('covers the HTML pages too, not just the JSON API', function () {
    // The sign-in form and the Livewire panel are the pages these headers are
    // for: a session-bearing HTML page is where clickjacking actually bites.
    $response = test()->get('/login-000009');

    expect($response->headers->get('X-Frame-Options'))->toBe('SAMEORIGIN');
    expect($response->headers->get('X-Content-Type-Options'))->toBe('nosniff');
});

it('still sends them when authentication is what failed', function () {
    // These used to go out bare. An auth failure is rendered by the exception
    // handler, which unwinds without the middleware's response phase — so the
    // admin area's own 401 had no clickjacking or sniffing protection at all.
    foreach (['/admin/dashboard-000009', '/api/admin/plan'] as $path) {
        $response = test()->withHeader('Accept', 'application/json')->get($path);

        expect($response->status())->toBeIn([401, 403, 302]);
        expect($response->headers->get('X-Frame-Options'))->toBe('SAMEORIGIN');
        expect($response->headers->get('X-Content-Type-Options'))->toBe('nosniff');
        expect($response->headers->get('Referrer-Policy'))->toBe('strict-origin-when-cross-origin');
    }
});

// ── HSTS, the one that is hard to take back ─────────────────────────────────

it('never sends HSTS over plain http, where it means nothing', function () {
    expect(headersFor())->not->toHaveKey('Strict-Transport-Security');
});

it('sends HSTS on a secure request', function () {
    $headers = headersFor('/api/marketplace/countries', true);

    expect($headers)->toHaveKey('Strict-Transport-Security');
    expect($headers['Strict-Transport-Security'])->toBe('max-age=86400');
});

it('starts at a max-age that expires by itself', function () {
    // HSTS is cached by the browser, so a wrong setting cannot be deployed
    // away. One day is real protection that heals on its own; a year is the
    // destination, not the first step.
    expect((int) config('security.hsts.max_age'))->toBe(86400);
    expect(config('security.hsts.include_subdomains'))->toBeFalsy();
    expect(config('security.hsts.preload'))->toBeFalsy();
});

it('does not reach provider subdomains unless somebody switches that on', function () {
    // Providers get subdomains and custom domains here. Forcing HTTPS across
    // all of them could take a working site down, and that decision belongs to
    // whoever owns the DNS and certificates.
    config(['security.hsts.include_subdomains' => false]);

    expect(headersFor('/api/marketplace/countries', true)['Strict-Transport-Security'])
        ->not->toContain('includeSubDomains');

    config(['security.hsts.include_subdomains' => true]);

    expect(headersFor('/api/marketplace/countries', true)['Strict-Transport-Security'])
        ->toContain('includeSubDomains');
});

it('will not advertise preload without the subdomains it requires', function () {
    // Browsers only honour preload alongside includeSubDomains, so one without
    // the other advertises something that cannot apply.
    config(['security.hsts.preload' => true, 'security.hsts.include_subdomains' => false]);

    expect(headersFor('/api/marketplace/countries', true)['Strict-Transport-Security'])
        ->not->toContain('preload');

    config(['security.hsts.include_subdomains' => true]);

    expect(headersFor('/api/marketplace/countries', true)['Strict-Transport-Security'])
        ->toContain('preload');
});

it('can be switched off entirely', function () {
    config(['security.hsts.max_age' => 0]);

    expect(headersFor('/api/marketplace/countries', true))
        ->not->toHaveKey('Strict-Transport-Security');
});

// ── Restraint ──────────────────────────────────────────────────────────────

it('leaves a header a response already set for itself', function () {
    // A response that has thought about its own framing keeps its answer; this
    // middleware fills gaps rather than overruling.
    $middleware = new \App\Http\Middleware\SecurityHeaders;

    $response = $middleware->handle(
        \Illuminate\Http\Request::create('/api/x'),
        function () {
            $r = new \Illuminate\Http\Response('ok');
            $r->headers->set('X-Frame-Options', 'ALLOWALL');

            return $r;
        }
    );

    expect($response->headers->get('X-Frame-Options'))->toBe('ALLOWALL');
    // The ones it did not set are still filled in.
    expect($response->headers->get('X-Content-Type-Options'))->toBe('nosniff');
});

it('ships no Content-Security-Policy, which would break Livewire if guessed', function () {
    // A CSP belongs on this origin, but the admin panel needs inline script, so
    // it has to be built report-only against real traffic first. Shipping one
    // blind would break the panel silently, which is worse than not having it.
    expect(headersFor())->not->toHaveKey('Content-Security-Policy');

    $source = (string) file_get_contents(app_path('Http/Middleware/SecurityHeaders.php'));
    expect($source)->not->toContain("'Content-Security-Policy' =>");
});

it('does not disturb the response itself', function () {
    // Headers must not change what the endpoint answers.
    $response = test()->withHeader('Accept', 'application/json')
        ->withHeader('from', 'frontend-web')
        ->get('/api/marketplace/countries');

    $response->assertOk();
    expect($response->json('success'))->toBeTrue();
});
