<?php

/**
 * The admin panel's Active Sessions page.
 *
 * config('sanctum.expiration') is null, so every token this app issues stays
 * valid until something deletes it, and until now the only thing that ever did
 * was the user signing out. A lost or stolen device kept working forever. These
 * tests pin the two halves of the fix: that each token records where and on what
 * it is being used, and that an admin can end one, several, a person's lot, or
 * all of them — without accidentally ending their own.
 */

use App\Livewire\Admin\Sessions\Index;
use App\Models\PersonalAccessToken;
use App\Models\User;
use App\Support\UserAgentDetails;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Spatie\Activitylog\Models\Activity;
use Spatie\Permission\Models\Role;

uses(RefreshDatabase::class);

beforeEach(function () {
    foreach (['super_admin', 'customer'] as $role) {
        Role::findOrCreate($role, 'web');
    }
});

function superAdmin(): User
{
    $admin = User::factory()->create(['status' => User::ACTIVE]);
    $admin->assignRole('super_admin');

    return $admin->fresh();
}

function sessionFor(User $user, array $attributes = []): PersonalAccessToken
{
    return PersonalAccessToken::forceCreate(array_merge([
        'tokenable_type' => User::class,
        'tokenable_id' => $user->id,
        'name' => 'bookboll-customer-login-api-token',
        'token' => hash('sha256', uniqid('tok', true)),
        'abilities' => ['*'],
        'ip_address' => '203.0.113.10',
        'user_agent' => 'Mozilla/5.0 (iPhone; CPU iPhone OS 17_4 like Mac OS X) AppleWebKit/605.1.15 (KHTML, like Gecko) Version/17.4 Mobile/15E148 Safari/604.1',
        'last_used_at' => now()->subMinutes(5),
    ], $attributes));
}

/* ---------------------------------------------------------------------------
 | Capture — the metadata the page depends on
 ---------------------------------------------------------------------------*/

it('records the address and device a token was issued to, through a real login', function () {
    $user = User::factory()->create([
        'email' => 'staff@bookboll.test',
        'password' => 'correct-horse-battery',
        'status' => User::ACTIVE,
    ]);
    $user->assignRole('super_admin');

    $this->withServerVariables(['REMOTE_ADDR' => '198.51.100.7'])
        ->withHeaders([
            'User-Agent' => 'Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/131.0.0.0 Safari/537.36',
        ])
        ->postJson('/api/login', [
            'email' => 'staff@bookboll.test',
            'password' => 'correct-horse-battery',
        ])
        ->assertOk();

    $token = PersonalAccessToken::query()->where('tokenable_id', $user->id)->latest('id')->first();

    expect($token)->not->toBeNull()
        ->and($token->ip_address)->toBe('198.51.100.7')
        ->and($token->user_agent)->toContain('Chrome/131')
        ->and($token->clientLabel())->toBe('Admin Dashboard')
        ->and($token->device()->summary())->toContain('macOS');
});

it('updates the last-used address on the request the guard already writes to', function () {
    $user = User::factory()->create(['status' => User::ACTIVE]);
    $plain = $user->createToken('bookboll-customer-login-api-token')->plainTextToken;

    // The token was minted in this test process, which has no client address.
    PersonalAccessToken::query()->latest('id')->first()->forceFill([
        'ip_address' => '203.0.113.10',
        'user_agent' => 'Dart/3.3 (dart:io) Flutter/3.19 (Android 14; SM-S918B)',
    ])->save();

    // Now the same token turns up from a different network.
    $this->withServerVariables(['REMOTE_ADDR' => '192.0.2.44'])
        ->withHeaders(['User-Agent' => 'Dart/3.3 (dart:io) Flutter/3.20 (Android 14; SM-S918B)'])
        ->withToken($plain)
        ->getJson('/api/me')
        ->assertOk();

    $token = PersonalAccessToken::query()->latest('id')->first();

    expect($token->last_used_ip)->toBe('192.0.2.44')
        ->and($token->last_used_user_agent)->toContain('Flutter/3.20')
        // The issue metadata must survive — the pair is the whole signal.
        ->and($token->ip_address)->toBe('203.0.113.10')
        ->and($token->hasMovedAddress())->toBeTrue()
        ->and($token->last_used_at)->not->toBeNull();
});

/* ---------------------------------------------------------------------------
 | The page
 ---------------------------------------------------------------------------*/

it('is reachable only by a super admin', function () {
    $customer = User::factory()->create(['status' => User::ACTIVE]);
    $customer->assignRole('customer');

    // CheckRole redirects a signed-in non-admin rather than returning 403.
    $this->actingAs($customer)->get(route('sessions.index'))->assertRedirect();
    $this->get(route('sessions.index'))->assertRedirect(route('login'));
    $this->actingAs(superAdmin())->get(route('sessions.index'))->assertOk();
});

it('lists sessions with the user, role, app, device and address', function () {
    $customer = User::factory()->create([
        'name' => 'Dana Ruiz',
        'email' => 'dana@example.test',
        'status' => User::ACTIVE,
    ]);
    $customer->assignRole('customer');
    sessionFor($customer);

    Livewire::actingAs(superAdmin())
        ->test(Index::class)
        ->assertSee('Dana Ruiz')
        ->assertSee('dana@example.test')
        ->assertSee('customer')
        ->assertSee('Customer App')
        ->assertSee('203.0.113.10')
        ->assertSee('iPhone');
});

it('shows the full detail panel for one session', function () {
    $customer = User::factory()->create(['name' => 'Dana Ruiz', 'status' => User::ACTIVE]);
    $token = sessionFor($customer, [
        'last_used_ip' => '192.0.2.44',
        'last_used_user_agent' => 'Dart/3.3 (dart:io) Flutter/3.20 (Android 14; SM-S918B)',
    ]);

    Livewire::actingAs(superAdmin())
        ->test(Index::class)
        ->call('inspect', $token->id)
        ->assertSet('inspecting', $token->id)
        ->assertSee('Session details')
        // Both halves of the pair, which is the point of the panel.
        ->assertSee('203.0.113.10')
        ->assertSee('192.0.2.44')
        ->assertSee('Never (no expiry configured)')
        ->call('closeInspector')
        ->assertSet('inspecting', null);
});

it('filters by app, status and platform', function () {
    $customer = User::factory()->create(['status' => User::ACTIVE]);

    sessionFor($customer, ['name' => 'bookboll-customer-login-api-token']);
    sessionFor($customer, [
        'name' => 'bookboll-admin-web-api-token',
        'user_agent' => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) Chrome/131.0.0.0 Safari/537.36',
        'last_used_at' => now()->subDays(30),
    ]);

    $component = Livewire::actingAs(superAdmin())->test(Index::class);

    $component->set('client', 'bookboll-admin-web-api-token')
        ->assertSet('client', 'bookboll-admin-web-api-token')
        ->assertViewHas('filteredTotal', 1);

    $component->set('client', '')->set('status', PersonalAccessToken::STATUS_IDLE)
        ->assertViewHas('filteredTotal', 1);

    $component->set('status', '')->set('platform', 'ios')
        ->assertViewHas('filteredTotal', 1);

    $component->set('platform', 'windows')
        ->assertViewHas('filteredTotal', 1);
});

it('searches by email and by address', function () {
    $customer = User::factory()->create(['email' => 'dana@example.test', 'status' => User::ACTIVE]);
    $other = User::factory()->create(['status' => User::ACTIVE]);

    sessionFor($customer, ['ip_address' => '203.0.113.99']);
    sessionFor($other, ['ip_address' => '198.51.100.1']);

    $component = Livewire::actingAs(superAdmin())->test(Index::class);

    $component->set('q', 'dana@example.test')->assertViewHas('filteredTotal', 1);
    $component->set('q', '203.0.113.99')->assertViewHas('filteredTotal', 1);
});

/* ---------------------------------------------------------------------------
 | Revocation
 ---------------------------------------------------------------------------*/

it('revokes a single session', function () {
    $customer = User::factory()->create(['status' => User::ACTIVE]);
    $token = sessionFor($customer);

    Livewire::actingAs(superAdmin())
        ->test(Index::class)
        ->call('revoke', $token->id)
        ->assertSet('errorMessage', null);

    expect(PersonalAccessToken::query()->whereKey($token->id)->exists())->toBeFalse();
});

it('revokes only the ticked sessions', function () {
    $customer = User::factory()->create(['status' => User::ACTIVE]);
    $doomed = sessionFor($customer);
    $spared = sessionFor($customer);

    Livewire::actingAs(superAdmin())
        ->test(Index::class)
        ->set('selected', [(string) $doomed->id])
        ->call('revokeSelected');

    expect(PersonalAccessToken::query()->whereKey($doomed->id)->exists())->toBeFalse()
        ->and(PersonalAccessToken::query()->whereKey($spared->id)->exists())->toBeTrue();
});

it('revokes every session belonging to one user and leaves other users alone', function () {
    $customer = User::factory()->create(['status' => User::ACTIVE]);
    $bystander = User::factory()->create(['status' => User::ACTIVE]);

    sessionFor($customer);
    sessionFor($customer, ['name' => 'auth-token']);
    $bystanderToken = sessionFor($bystander);

    Livewire::actingAs(superAdmin())
        ->test(Index::class)
        ->call('revokeAllForUser', $customer->id);

    expect(PersonalAccessToken::query()->where('tokenable_id', $customer->id)->count())->toBe(0)
        ->and(PersonalAccessToken::query()->whereKey($bystanderToken->id)->exists())->toBeTrue();
});

it('revokes everything matching the active filters and nothing outside them', function () {
    $customer = User::factory()->create(['status' => User::ACTIVE]);

    $matching = sessionFor($customer, ['name' => 'bookboll-admin-web-api-token']);
    $outside = sessionFor($customer, ['name' => 'bookboll-customer-login-api-token']);

    Livewire::actingAs(superAdmin())
        ->test(Index::class)
        ->set('client', 'bookboll-admin-web-api-token')
        ->call('revokeMatchingFilters');

    expect(PersonalAccessToken::query()->whereKey($matching->id)->exists())->toBeFalse()
        ->and(PersonalAccessToken::query()->whereKey($outside->id)->exists())->toBeTrue();
});

it('refuses to revoke by filter when no filter is set', function () {
    $customer = User::factory()->create(['status' => User::ACTIVE]);
    $token = sessionFor($customer);

    Livewire::actingAs(superAdmin())
        ->test(Index::class)
        ->call('revokeMatchingFilters')
        ->assertSet('statusMessage', null);

    expect(PersonalAccessToken::query()->whereKey($token->id)->exists())->toBeTrue();
});

it('holds back the acting admin’s own sessions on a bulk revoke', function () {
    $admin = superAdmin();
    $customer = User::factory()->create(['status' => User::ACTIVE]);

    $ownSession = sessionFor($admin, ['name' => 'bookboll-admin-web-api-token']);
    $customerSession = sessionFor($customer);

    Livewire::actingAs($admin)
        ->test(Index::class)
        ->call('revokeAll');

    expect(PersonalAccessToken::query()->whereKey($ownSession->id)->exists())->toBeTrue()
        ->and(PersonalAccessToken::query()->whereKey($customerSession->id)->exists())->toBeFalse();
});

it('revokes the admin’s own sessions too once protection is switched off', function () {
    $admin = superAdmin();
    $ownSession = sessionFor($admin, ['name' => 'bookboll-admin-web-api-token']);

    Livewire::actingAs($admin)
        ->test(Index::class)
        ->set('protectOwnSessions', false)
        ->call('revokeAll');

    expect(PersonalAccessToken::query()->whereKey($ownSession->id)->exists())->toBeFalse();
});

it('still revokes one named session of the admin’s own, since that is an explicit choice', function () {
    $admin = superAdmin();
    $ownSession = sessionFor($admin, ['name' => 'bookboll-admin-mobile-app-api-token']);

    Livewire::actingAs($admin)
        ->test(Index::class)
        ->call('revoke', $ownSession->id);

    expect(PersonalAccessToken::query()->whereKey($ownSession->id)->exists())->toBeFalse();
});

it('records every revocation in the activity log', function () {
    $admin = superAdmin();
    $customer = User::factory()->create(['status' => User::ACTIVE]);
    $token = sessionFor($customer);

    Livewire::actingAs($admin)
        ->test(Index::class)
        ->call('revoke', $token->id);

    $entry = Activity::query()->where('log_name', 'api-session')->latest('id')->first();

    expect($entry)->not->toBeNull()
        ->and($entry->causer_id)->toBe($admin->id)
        ->and($entry->description)->toContain('Revoked 1 API session')
        ->and($entry->properties['revoked_count'])->toBe(1);
});

it('reports a revoked session as already gone rather than failing', function () {
    Livewire::actingAs(superAdmin())
        ->test(Index::class)
        ->call('revoke', 999999)
        ->assertSet('statusMessage', null)
        ->assertSee('already ended');
});

/* ---------------------------------------------------------------------------
 | Status and parsing
 ---------------------------------------------------------------------------*/

it('classifies sessions by how recently they were used', function () {
    $customer = User::factory()->create(['status' => User::ACTIVE]);

    expect(sessionFor($customer, ['last_used_at' => now()->subMinutes(2)])->status())->toBe(PersonalAccessToken::STATUS_ACTIVE)
        ->and(sessionFor($customer, ['last_used_at' => now()->subDays(10)])->status())->toBe(PersonalAccessToken::STATUS_IDLE)
        ->and(sessionFor($customer, ['last_used_at' => null])->status())->toBe(PersonalAccessToken::STATUS_UNUSED)
        ->and(sessionFor($customer, ['expires_at' => now()->subDay()])->status())->toBe(PersonalAccessToken::STATUS_EXPIRED);
});

it('reads a device out of the user agents the apps actually send', function (string $agent, string $expected, string $deviceType) {
    $parsed = UserAgentDetails::parse($agent);

    expect($parsed->summary())->toContain($expected)
        ->and($parsed->deviceType)->toBe($deviceType);
})->with([
    'iPhone Safari' => [
        'Mozilla/5.0 (iPhone; CPU iPhone OS 17_4 like Mac OS X) AppleWebKit/605.1.15 (KHTML, like Gecko) Version/17.4 Mobile/15E148 Safari/604.1',
        'iOS 17.4',
        UserAgentDetails::DEVICE_MOBILE,
    ],
    'Android Chrome phone' => [
        'Mozilla/5.0 (Linux; Android 14; Pixel 8 Pro) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/131.0.0.0 Mobile Safari/537.36',
        'Chrome 131',
        UserAgentDetails::DEVICE_MOBILE,
    ],
    'Android tablet has no Mobile token' => [
        'Mozilla/5.0 (Linux; Android 13; SM-X710) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/120.0.0.0 Safari/537.36',
        'Android 13',
        UserAgentDetails::DEVICE_TABLET,
    ],
    'iPad is a tablet, not a phone' => [
        'Mozilla/5.0 (iPad; CPU OS 17_4 like Mac OS X) AppleWebKit/605.1.15 (KHTML, like Gecko) Version/17.4 Safari/604.1',
        'iPadOS',
        UserAgentDetails::DEVICE_TABLET,
    ],
    'Flutter mobile app' => [
        'Dart/3.3 (dart:io) Flutter/3.19 (Android 14; SM-S918B)',
        'Dart 3',
        UserAgentDetails::DEVICE_MOBILE,
    ],
    'Edge is not reported as Chrome' => [
        'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/131.0.0.0 Safari/537.36 Edg/131.0.0.0',
        'Edge 131',
        UserAgentDetails::DEVICE_DESKTOP,
    ],
    'macOS desktop' => [
        'Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/131.0.0.0 Safari/537.36',
        'macOS',
        UserAgentDetails::DEVICE_DESKTOP,
    ],
    'a crawler is flagged as one' => [
        'Mozilla/5.0 (compatible; Googlebot/2.1; +http://www.google.com/bot.html)',
        '',
        UserAgentDetails::DEVICE_BOT,
    ],
]);

it('says so plainly when there is no user agent at all', function () {
    $parsed = UserAgentDetails::parse(null);

    expect($parsed->summary())->toBe('Unknown device')
        ->and($parsed->deviceType)->toBe(UserAgentDetails::DEVICE_UNKNOWN);
});
