<?php

/**
 * Google sign-in on a SaaS that sells wildcard subdomains and custom domains.
 *
 * Google validates the origin of the page that talks to it and accepts no wildcards,
 * so a storefront on alamgir.bookboll.com — or on a provider's own domain — can never
 * run the handshake itself: the origins are created at runtime and cannot be
 * registered. The broker moves the handshake to the one origin Google knows (this API)
 * and hands the finished session back by sid.
 *
 * What these tests pin is mostly the refusals. The broker ends by issuing a real
 * Sanctum token, so an origin that slips through its checks is account takeover: any
 * site could walk a customer through Google and collect their Bookboll session. The
 * happy path is one test; the rest guard the ways in.
 */

use App\Models\Business;
use App\Models\User;
use App\Services\UserService;
use App\Support\TenantOrigins;
use Database\Seeders\LoyaltyTierTableSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Spatie\Permission\Models\Role;

uses(RefreshDatabase::class);

const TENANT = 'https://alamgir.bookboll.com';
const SID    = '11111111-2222-3333-4444-555555555555';

beforeEach(function () {
    // User::$appends carries next_tier, which walks the tier ladder and null-derefs
    // when no tiers exist — exchange() serialises the user, so an unseeded database
    // 500s before the assertion is reached.
    $this->seed(LoyaltyTierTableSeeder::class);
    Role::findOrCreate('customer', 'web');

    config([
        'services.google.client_id'     => 'test-client-id.apps.googleusercontent.com',
        'services.google.client_secret' => 'test-client-secret',
        'services.google.redirect'      => 'http://localhost/api/auth/google/callback',
    ]);
});

/** Stand in for Google: the token endpoint, and the signature check we cannot forge. */
function fakeGoogle(array $payload = []): void
{
    Http::fake([
        'oauth2.googleapis.com/token' => Http::response([
            'access_token' => 'ya29.test',
            'id_token'     => 'test.id.token',
        ]),
    ]);

    $mock = Mockery::mock(UserService::class, [app(User::class)])->makePartial();
    $mock->shouldReceive('verifyGoogleIdToken')->andReturn(array_merge([
        'sub'     => 'google-sub-12345',
        'email'   => 'player@example.com',
        'name'    => 'Test Player',
        'picture' => 'https://lh3.googleusercontent.com/test',
    ], $payload));
    app()->instance(UserService::class, $mock);
}

/** Walk steps 1 and 2, returning the sid the storefront should now collect. */
function brokerThrough(string $origin = TENANT, string $sid = SID): string
{
    $location = test()->get("/api/auth/google/start?sid={$sid}&return=".urlencode($origin))
        ->assertRedirect()
        ->headers->get('Location');

    parse_str(parse_url($location, PHP_URL_QUERY), $query);

    test()->get('/api/auth/google/callback?code=test-auth-code&state='.urlencode($query['state']))
        ->assertOk();

    return $sid;
}

it('sends the popup to Google with the single registered redirect URI', function () {
    $location = $this->get('/api/auth/google/start?sid='.SID.'&return='.urlencode(TENANT))
        ->assertRedirect()
        ->headers->get('Location');

    expect($location)->toStartWith('https://accounts.google.com/o/oauth2/v2/auth?');

    parse_str(parse_url($location, PHP_URL_QUERY), $q);

    // The tenant origin is nowhere in what Google is told — that is the entire trick.
    expect($q['redirect_uri'])->toBe('http://localhost/api/auth/google/callback')
        ->and($q['response_type'])->toBe('code')
        ->and($q['state'])->not->toContain('alamgir')
        ->and($location)->not->toContain('bookboll.com');
});

it('signs a customer in on a wildcard tenant subdomain', function () {
    fakeGoogle();
    $sid = brokerThrough();

    $res = $this->withHeader('Origin', TENANT)
        ->postJson('/api/customer/auth/exchange', ['sid' => $sid])
        ->assertOk();

    expect($res->json('data.token'))->toBeString()->not->toBeEmpty()
        ->and($res->json('data.user.email'))->toBe('player@example.com');

    $user = User::where('email', 'player@example.com')->first();
    expect($user)->not->toBeNull()
        ->and($user->provider)->toBe('google')
        ->and($user->hasRole('customer'))->toBeTrue()
        ->and($user->uuid)->not->toBeNull();   // applyLaravelAuth refuses a user without one

    // The token is usable, which is the only proof that matters.
    $this->withHeader('Authorization', 'Bearer '.$res->json('data.token'))
        ->withHeader('Origin', TENANT)
        ->getJson('/api/customer/profile')
        ->assertOk();
});

it('signs a customer in on a provider custom domain just the same', function () {
    fakeGoogle();

    $owner = User::factory()->create();
    Business::create([
        'owner_id'             => $owner->id,
        'name'                 => 'Some Club',
        'username'             => 'someclub',
        'brand_custom_domain'  => 'book.someclub.com',
        'brand_domain_paid_at' => now(),
        'brand_domain_status'  => 'active',
    ]);
    TenantOrigins::forget();   // the allowlist is cached per request

    $sid = brokerThrough('https://book.someclub.com');

    $this->withHeader('Origin', 'https://book.someclub.com')
        ->postJson('/api/customer/auth/exchange', ['sid' => $sid])
        ->assertOk()
        ->assertJsonPath('data.status', 'ready');
});

it('reports pending while the customer is still choosing an account', function () {
    $this->get('/api/auth/google/start?sid='.SID.'&return='.urlencode(TENANT))->assertRedirect();

    $this->withHeader('Origin', TENANT)
        ->postJson('/api/customer/auth/exchange', ['sid' => SID])
        ->assertOk()
        ->assertJsonPath('data.status', 'pending')
        ->assertJsonMissingPath('data.token');
});

it('tells a poll that beat the popup to wait, not that it expired', function () {
    // The storefront starts polling in the same tick as window.open(), so this poll
    // regularly lands before the popup's own request to start(). Answering 410 here
    // made the client give up and show "This sign-in has expired" on a sign-in that
    // had not even begun — the first attempt failed almost every time.
    $this->withHeader('Origin', TENANT)
        ->postJson('/api/customer/auth/exchange', ['sid' => SID])
        ->assertStatus(404);

    // ...and the same sid works normally once the popup catches up.
    fakeGoogle();
    $sid = brokerThrough();

    $this->withHeader('Origin', TENANT)
        ->postJson('/api/customer/auth/exchange', ['sid' => $sid])
        ->assertOk()
        ->assertJsonPath('data.status', 'ready');
});

it('separates a spent sid from one that never started', function () {
    fakeGoogle();
    $sid = brokerThrough();

    $this->withHeader('Origin', TENANT)->postJson('/api/customer/auth/exchange', ['sid' => $sid])->assertOk();

    // Spent leaves a tombstone, so a replay is told so plainly rather than being
    // mistaken for a handshake that has not started yet.
    $this->withHeader('Origin', TENANT)
        ->postJson('/api/customer/auth/exchange', ['sid' => $sid])
        ->assertStatus(410)
        ->assertJsonPath('message', 'This sign-in has already been used. Please try again.');

    // A sid nobody ever opened is a different answer entirely.
    $this->withHeader('Origin', TENANT)
        ->postJson('/api/customer/auth/exchange', ['sid' => '99999999-8888-7777-6666-555555555555'])
        ->assertStatus(404);
});

it('refuses to start a handshake for an origin that is not ours', function (string $origin) {
    $this->get('/api/auth/google/start?sid='.SID.'&return='.urlencode($origin))
        ->assertStatus(400)
        ->assertSee('not allowed to sign in with Google');

    expect(Cache::get('oauth-broker.sid.'.SID))->toBeNull();
})->with([
    'an unrelated site'     => 'https://evil.com',
    'a suffix lookalike'    => 'https://bookboll.com.evil.com',
    'a prefix lookalike'    => 'https://evil-bookboll.com',
    'two labels deep'       => 'https://a.b.bookboll.com',
    'plain http'            => 'http://alamgir.bookboll.com',
    'an unpaid custom domain' => 'https://book.someclub.com',
]);

it('will not hand a finished session to a different tenant', function () {
    fakeGoogle();
    $sid = brokerThrough();

    // padel.bookboll.com is a legitimate storefront — it still may not collect a
    // session that was opened from somewhere else.
    $this->withHeader('Origin', 'https://padel.bookboll.com')
        ->postJson('/api/customer/auth/exchange', ['sid' => $sid])
        ->assertStatus(403);

    // ...and the rightful storefront can still collect it afterwards.
    $this->withHeader('Origin', TENANT)
        ->postJson('/api/customer/auth/exchange', ['sid' => $sid])
        ->assertOk();
});

it('spends a sid exactly once', function () {
    fakeGoogle();
    $sid = brokerThrough();

    $this->withHeader('Origin', TENANT)->postJson('/api/customer/auth/exchange', ['sid' => $sid])->assertOk();
    $this->withHeader('Origin', TENANT)->postJson('/api/customer/auth/exchange', ['sid' => $sid])->assertStatus(410);
});

it('refuses a replayed Google callback', function () {
    fakeGoogle();

    $location = $this->get('/api/auth/google/start?sid='.SID.'&return='.urlencode(TENANT))
        ->assertRedirect()->headers->get('Location');
    parse_str(parse_url($location, PHP_URL_QUERY), $q);

    $this->get('/api/auth/google/callback?code=test-auth-code&state='.urlencode($q['state']))->assertOk();

    // Same state again: the sid is no longer pending, so there is nothing to sign in.
    $this->get('/api/auth/google/callback?code=test-auth-code&state='.urlencode($q['state']))
        ->assertSee('expired');
});

it('ignores a state it did not sign', function () {
    $this->get('/api/auth/google/callback?code=x&state='.urlencode(base64_encode('{"sid":"'.SID.'","nonce":"forged"}')))
        ->assertSee('could not be verified');
});

it('tells a blocked customer why, instead of issuing a token', function () {
    User::factory()->create([
        'email'       => 'blocked@example.com',
        'provider'    => 'google',
        'provider_id' => 'google-sub-blocked',
        'status'      => User::BLOCKED,
    ]);

    fakeGoogle(['sub' => 'google-sub-blocked', 'email' => 'blocked@example.com']);
    $sid = brokerThrough();

    $this->withHeader('Origin', TENANT)
        ->postJson('/api/customer/auth/exchange', ['sid' => $sid])
        ->assertStatus(410)
        ->assertJsonPath('message', 'Your account is block by admin please contact for further details');
});

it('sends a popup-blocked customer back to the page they started on', function () {
    fakeGoogle();

    $location = $this->get('/api/auth/google/start?sid='.SID.'&return='.urlencode(TENANT)
            .'&mode=redirect&return_path='.urlencode('/listing/42?slot=7'))
        ->assertRedirect()->headers->get('Location');
    parse_str(parse_url($location, PHP_URL_QUERY), $q);

    $this->get('/api/auth/google/callback?code=test-auth-code&state='.urlencode($q['state']))
        ->assertRedirect(TENANT.'/listing/42?slot=7&bb_auth='.SID);
});

it('will not be turned into an open redirector by the return path', function (string $path) {
    fakeGoogle();

    $location = $this->get('/api/auth/google/start?sid='.SID.'&return='.urlencode(TENANT)
            .'&mode=redirect&return_path='.urlencode($path))
        ->assertRedirect()->headers->get('Location');
    parse_str(parse_url($location, PHP_URL_QUERY), $q);

    $this->get('/api/auth/google/callback?code=test-auth-code&state='.urlencode($q['state']))
        ->assertRedirect(TENANT.'/?bb_auth='.SID);
})->with([
    'protocol relative' => '//evil.com',
    'absolute url'      => 'https://evil.com/steal',
    'no leading slash'  => 'evil.com',
    'a backslash'       => '/\\evil.com',
]);
