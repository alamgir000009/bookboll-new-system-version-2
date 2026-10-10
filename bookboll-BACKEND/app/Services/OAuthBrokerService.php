<?php

namespace App\Services;

use App\Exceptions\CustomerAuthException;
use App\Models\User;
use App\Support\TenantOrigins;
use Exception;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

/**
 * Google sign-in for a SaaS that sells wildcard subdomains and custom domains.
 *
 * The problem this solves: Google validates the *origin of the page* that talks to it,
 * and accepts no wildcards — so alamgir.bookboll.com, padel.bookboll.com and
 * book.someclub.com would each have to be registered by hand, against a ~100 entry
 * cap, forever. The storefronts cannot be enumerated, so that approach cannot work.
 *
 * The fix is the standard one for multi-tenant SaaS (Shopify's accounts.shopify.com,
 * Atlassian's id.atlassian.com, Clerk's "satellite domains"): do the Google handshake
 * on ONE origin — this API — and hand the finished session back to whichever
 * storefront the customer started on. Google knows a single redirect URI and never
 * learns that tenant domains exist.
 *
 * The flow, all of it inside a popup the storefront opened:
 *
 *   1. start()     the storefront's popup lands here with ?sid&return. The return
 *                  origin is checked against TenantOrigins, the pair is recorded, and
 *                  the popup is sent to Google.
 *   2. callback()  Google returns a code to this API. The code is exchanged
 *                  server-side, the id_token verified, the customer upserted, and a
 *                  Sanctum token parked under the sid. The popup closes itself.
 *   3. exchange()  the storefront — which never navigated, so its half-filled booking
 *                  and its pending ensureSignedIn() promise are both still alive —
 *                  polls for the sid and gets the token.
 *
 * What crosses the popup boundary is the sid: an opaque handle, useless without the
 * matching Origin, single-use, and dead in two minutes. No Google credential and no
 * Sanctum token is ever put in a URL, where it would survive in history, Referer
 * headers and access logs.
 */
class OAuthBrokerService
{
    /** How long a customer has to finish picking a Google account. */
    private const PENDING_TTL = 600;

    /** How long a finished session waits to be collected by the storefront. */
    private const READY_TTL = 120;

    public function __construct(protected UserService $users)
    {
    }

    /**
     * Step 1 — open the handshake and redirect the popup to Google.
     *
     * Errors here are rendered in the popup rather than redirected anywhere: a bad
     * `return` is either a misconfigured storefront or someone trying to use us as a
     * redirector, and neither deserves a redirect.
     */
    public function start(Request $request)
    {
        $origin = TenantOrigins::canonicalizeOrigin($request->query('return'));
        $sid    = $this->cleanSid($request->query('sid'));

        if ($origin === null) {
            Log::warning('OAuth broker refused a return origin', [
                'return' => (string) $request->query('return'),
                'ip'     => $request->ip(),
            ]);

            return $this->fail('This site is not allowed to sign in with Google.');
        }

        if ($sid === null) {
            return $this->fail('Sign-in could not be started. Please try again.');
        }

        if (! config('services.google.client_id') || ! config('services.google.client_secret')) {
            Log::error('OAuth broker is missing GOOGLE_CLIENT_ID / GOOGLE_CLIENT_SECRET');

            return $this->fail('Google sign-in is not configured. Please use email instead.');
        }

        $nonce = Str::random(40);

        // Recording the sid before leaving pins the origin to it. callback() trusts
        // this record and not the round-trip, so the origin a session is handed back
        // to is the one that was validated here, whatever comes back from Google.
        Cache::put($this->key($sid), [
            'status' => 'pending',
            'origin' => $origin,
            'nonce'  => $nonce,
            // Popups are blocked outright in some in-app browsers (Instagram, Facebook),
            // so the storefront falls back to a top-level redirect and says so here.
            // callback() has to know: calling window.close() on a tab that was never a
            // popup does nothing and strands the customer on a spinner.
            'mode'   => $request->query('mode') === 'redirect' ? 'redirect' : 'popup',
            'path'   => $this->cleanReturnPath($request->query('return_path')),
        ], self::PENDING_TTL);

        $state = Crypt::encryptString(json_encode([
            'sid'   => $sid,
            'nonce' => $nonce,
        ]));

        return redirect()->away('https://accounts.google.com/o/oauth2/v2/auth?'.http_build_query([
            'client_id'     => config('services.google.client_id'),
            'redirect_uri'  => config('services.google.redirect'),
            'response_type' => 'code',
            'scope'         => 'openid email profile',
            'state'         => $state,
            'nonce'         => $nonce,
            // The popup is shared with whatever Google account the browser already
            // has; without this a signed-in customer is never asked which to use.
            'prompt'        => 'select_account',
        ]));
    }

    /**
     * Step 2 — Google's redirect lands here. Exchange, verify, upsert, park, close.
     *
     * Always renders the closing page, never an HTTP error: this document is only
     * ever seen inside a popup, so a failure has to travel back through the sid like
     * a success does, or the storefront polls until it times out with no idea why.
     */
    public function callback(Request $request)
    {
        $state = $this->unpackState($request->query('state'));

        if ($state === null) {
            return $this->fail('Sign-in could not be verified. Please try again.');
        }

        [$sid, $nonce] = $state;
        $record = Cache::get($this->key($sid));

        // One sid, one sign-in: a replayed callback finds nothing pending.
        if (! is_array($record) || ($record['status'] ?? null) !== 'pending' || ! hash_equals((string) ($record['nonce'] ?? ''), $nonce)) {
            return $this->fail('This sign-in has expired. Please try again.');
        }

        $origin = $record['origin'];

        if ($request->query('error')) {
            // The customer pressed Cancel on Google's account picker.
            $this->reject($sid, $origin, 'Google sign-in was cancelled.');

            return $this->handBack($record, $sid);
        }

        try {
            $payload = $this->exchangeCodeForIdentity((string) $request->query('code'));

            if ($payload === null) {
                $this->reject($sid, $origin, 'Google sign-in failed. Please try again.');

                return $this->handBack($record, $sid);
            }

            [$user, $token] = $this->users->issueCustomerSessionFromGoogle($payload);

            // Only the user id is parked. Caching a serialised model would hand back a
            // snapshot of whatever the row looked like mid-flow; exchange() re-reads it.
            Cache::put($this->key($sid), [
                'status'  => 'ready',
                'origin'  => $origin,
                'user_id' => $user->id,
                'token'   => $token,
            ], self::READY_TTL);

        } catch (CustomerAuthException $e) {
            $this->reject($sid, $origin, $e->getMessage());
        } catch (Exception $e) {
            Log::error('OAuth broker callback failed: '.$e->getMessage(), ['exception' => $e]);
            $this->reject($sid, $origin, 'Something went wrong. Please try again.');
        }

        return $this->handBack($record, $sid);
    }

    /**
     * Return control to the storefront, whichever way the customer arrived.
     *
     * A popup closes itself and the storefront's poll picks the session up. A
     * top-level fallback has to be navigated back, carrying the sid so the storefront
     * knows to collect one — the session itself stays server-side either way.
     *
     * @param  array<string, mixed>  $record  The pending record written by start().
     */
    protected function handBack(array $record, string $sid)
    {
        $origin = (string) $record['origin'];

        if (($record['mode'] ?? 'popup') !== 'redirect') {
            return $this->closeWindow($origin);
        }

        $path      = $record['path'] ?? '/';
        $separator = str_contains($path, '?') ? '&' : '?';

        return redirect()->away($origin.$path.$separator.'bb_auth='.urlencode($sid));
    }

    /**
     * Step 3 — the storefront collects its session.
     *
     * Polled roughly once a second while the popup is open, so "not finished yet" is a
     * success with a status, not an error: a 4xx every second would bury real failures
     * in the browser console and trip rate limits.
     */
    public function exchange(Request $request)
    {
        $sid = $this->cleanSid($request->input('sid'));

        if ($sid === null) {
            return sendError('Invalid sign-in reference.', [], 422);
        }

        $record = Cache::get($this->key($sid));

        if (! is_array($record)) {
            // 404, not 410, and the difference matters. The storefront starts polling
            // in the same tick as window.open(), so its first poll routinely arrives
            // before the popup has even issued its own request to start() — a warm
            // XHR beats a cold document load almost every time. Answering "expired"
            // there made a perfectly good sign-in fail on the first attempt. "Not
            // started" tells the caller to keep waiting; a sid that really was spent
            // leaves the tombstone below and still answers 410.
            return sendError('This sign-in has not started.', [], 404);
        }

        // The sid is a handle, not a secret: it travels in a popup URL and lands in
        // this API's access log. Binding it to the origin that opened the handshake is
        // what stops it being replayed from anywhere else.
        $caller = TenantOrigins::canonicalizeOrigin($request->header('Origin'));

        if ($caller === null || ! hash_equals((string) $record['origin'], $caller)) {
            Log::warning('OAuth broker exchange from an unexpected origin', [
                'expected' => $record['origin'] ?? null,
                'actual'   => $request->header('Origin'),
                'ip'       => $request->ip(),
            ]);

            return sendError('This sign-in cannot be completed here.', [], 403);
        }

        if (($record['status'] ?? null) === 'pending') {
            return sendSuccess('Waiting for Google.', ['status' => 'pending']);
        }

        if (($record['status'] ?? null) === 'spent') {
            return sendError('This sign-in has already been used. Please try again.', [], 410);
        }

        // Single use, whichever way it went. A tombstone rather than a plain forget,
        // so a replay is answered "already used" instead of being mistaken for a
        // handshake that has not started yet.
        Cache::put($this->key($sid), [
            'status' => 'spent',
            'origin' => $record['origin'],
        ], self::READY_TTL);

        if (($record['status'] ?? null) === 'error') {
            return sendError($record['message'] ?? 'Google sign-in failed. Please try again.', [], 410);
        }

        $user = User::with('preferences')->find($record['user_id'] ?? null);

        if (! $user) {
            return sendError('Your account could not be loaded. Please try again.', [], 410);
        }

        $user->makeHidden(['roles']);

        // Same shape as /customer/google-login, so the storefront's applyLaravelAuth()
        // handles both without knowing which door the customer came through.
        return sendSuccess('Logged in with google', [
            'status' => 'ready',
            'token'  => $record['token'],
            'user'   => $user,
        ]);
    }

    /**
     * Trade Google's one-time code for an identity, entirely server-side.
     *
     * The returned id_token is verified rather than trusted: it arrives over TLS from
     * Google's token endpoint, but verifying costs nothing and means the signature and
     * `aud` are checked on every path into issueCustomerSessionFromGoogle().
     *
     * @return array<string, mixed>|null
     */
    protected function exchangeCodeForIdentity(string $code): ?array
    {
        if ($code === '') {
            return null;
        }

        $response = Http::asForm()
            ->timeout(15)
            ->post('https://oauth2.googleapis.com/token', [
                'code'          => $code,
                'client_id'     => config('services.google.client_id'),
                'client_secret' => config('services.google.client_secret'),
                'redirect_uri'  => config('services.google.redirect'),
                'grant_type'    => 'authorization_code',
            ]);

        if (! $response->successful()) {
            Log::warning('Google token exchange failed', [
                'status' => $response->status(),
                'body'   => $response->json('error_description') ?? $response->json('error'),
            ]);

            return null;
        }

        $idToken = (string) $response->json('id_token');

        return $idToken === '' ? null : $this->users->verifyGoogleIdToken($idToken);
    }

    /** Park a failure under the sid so the storefront can show it and stop polling. */
    protected function reject(string $sid, string $origin, string $message): void
    {
        Cache::put($this->key($sid), [
            'status'  => 'error',
            'origin'  => $origin,
            'message' => $message,
        ], self::READY_TTL);
    }

    /**
     * @return array{0: string, 1: string}|null  [sid, nonce]
     */
    protected function unpackState(?string $state): ?array
    {
        if (! is_string($state) || $state === '') {
            return null;
        }

        try {
            $decoded = json_decode(Crypt::decryptString($state), true, 8, JSON_THROW_ON_ERROR);
        } catch (Exception) {
            // Not ours, or tampered with. Either way there is nothing to act on.
            return null;
        }

        $sid = $this->cleanSid($decoded['sid'] ?? null);

        if ($sid === null || ! is_string($decoded['nonce'] ?? null)) {
            return null;
        }

        return [$sid, $decoded['nonce']];
    }

    /**
     * The sid is generated by the storefront (it has to know the handle before it can
     * poll for it), so it is validated as a shape and never interpolated anywhere —
     * it only ever becomes part of a cache key.
     */
    protected function cleanSid(mixed $sid): ?string
    {
        if (! is_string($sid)) {
            return null;
        }

        $sid = trim($sid);

        return preg_match('/^[A-Za-z0-9-]{20,64}$/', $sid) ? $sid : null;
    }

    /**
     * Where to drop the customer back on their storefront.
     *
     * Only ever a path, and only ever appended to an origin this class already
     * validated — so the worst a forged value can do is land someone on the wrong page
     * of a site they were already on. A leading "//" is refused because browsers read
     * "//evil.com" as protocol-relative, which would turn this into the open
     * redirector the origin check exists to prevent.
     */
    protected function cleanReturnPath(mixed $path): string
    {
        if (! is_string($path)) {
            return '/';
        }

        $path = trim($path);

        if ($path === '' || ! str_starts_with($path, '/') || str_starts_with($path, '//')) {
            return '/';
        }

        if (strlen($path) > 512 || str_contains($path, '\\') || preg_match('/[\x00-\x1f\x7f\s]/', $path)) {
            return '/';
        }

        return $path;
    }

    protected function key(string $sid): string
    {
        return "oauth-broker.sid.{$sid}";
    }

    /** The popup's last act: tell the opener to stop waiting, then close. */
    protected function closeWindow(?string $origin = null)
    {
        return response()->view('auth.broker-close', ['origin' => $origin]);
    }

    /** A dead end the customer can read, for failures with no sid to carry them. */
    protected function fail(string $message)
    {
        return response()->view('auth.broker-close', [
            'origin'  => null,
            'message' => $message,
        ], 400);
    }
}
