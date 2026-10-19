<?php

/**
 * Mobile-app adoption.
 *
 * The websites kept a per-account browser self-report and nothing else. The
 * document is emphatic that this is not evidence: a OneLink click, a QR
 * display, a user agent or "I already have the app" cannot establish that an
 * account installed anything, and people install apps without signing in.
 *
 * So the only state that counts as evidence is an authenticated call from the
 * app itself, and the states are `unknown` / `used_app` — never "downloaded"
 * against "not downloaded". These pin that distinction, that one account
 * cannot be credited with another's use, and that a shared device handed over
 * does not keep crediting the person who left.
 */

use App\Models\Business;
use App\Models\MobileAppInstallation;
use App\Models\User;
use App\Support\MobileAppAdoption;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use Spatie\Permission\Models\Role;

uses(RefreshDatabase::class);

beforeEach(function () {
    foreach (['customer', 'provider_admin', 'super_admin', 'provider_viewer'] as $r) {
        Role::findOrCreate($r, 'web');
    }

    $this->customer = appUser('customer', 'App Customer');
    $this->provider = appUser('provider_admin', 'App Provider');
    Business::create([
        'owner_id' => $this->provider->id, 'name' => 'North Club',
        'username' => 'north-'.uniqid(), 'status' => Business::STATUS_ACTIVE, 'plan' => 'free',
    ]);
    $this->platform = appUser('super_admin', 'Platform Staff');
});

function appUser(string $role, string $name): User
{
    $u = User::factory()->create(['name' => $name, 'status' => User::ACTIVE, 'uuid' => (string) Str::uuid()]);
    $u->assignRole($role);

    return $u->fresh();
}

/** A native app calling in, as the real client would. */
function registerApp(User $actor, string $app, array $over = [])
{
    Sanctum::actingAs($actor);

    return test()->postJson("/api/{$app}/mobile-app/sessions", array_merge([
        'app'             => $app === 'customer' ? 'customer' : 'admin',
        'platform'        => 'ios',
        'installation_id' => (string) Str::uuid(),
        'app_version'     => '2.1.0',
    ], $over));
}

function appStatus(User $actor, string $app)
{
    Sanctum::actingAs($actor);

    return test()->getJson("/api/{$app}/mobile-app-status");
}

function setSelfReport(User $actor, string $app, bool $value)
{
    Sanctum::actingAs($actor);

    return test()->putJson("/api/{$app}/mobile-app-preferences", ['self_reported' => $value]);
}

function adoptionReport(User $actor, array $query = [])
{
    Sanctum::actingAs($actor);

    return test()->getJson('/api/admin/mobile-app/adoption-report?'.http_build_query($query));
}

// ── Honest states ────────────────────────────────────────────────────────────

it('starts unknown with no evidence, which is not the same as not downloaded', function () {
    $status = appStatus($this->customer, 'customer')->assertOk()->json('data.status');

    expect($status['usage_status'])->toBe('unknown');
    // Null, not a zero date: an absent timestamp must not read as a real one.
    expect($status['first_authenticated_use_at'])->toBeNull();
    expect($status['last_authenticated_use_at'])->toBeNull();
    expect($status['self_reported'])->toBeFalse();
    expect($status['suppress_prompt'])->toBeFalse();
});

it('records authenticated native use as evidence, with server timestamps', function () {
    $status = registerApp($this->customer, 'customer')->assertOk()->json('data.status');

    expect($status['usage_status'])->toBe('used_app');
    expect($status['first_authenticated_use_at'])->not->toBeNull();
    expect($status['suppress_prompt'])->toBeTrue();

    $row = MobileAppInstallation::first();
    expect((int) $row->user_id)->toBe($this->customer->id);
    expect($row->app)->toBe('customer');
});

it('takes the account from the session and never from the request', function () {
    $victim = appUser('customer', 'Other Customer');

    // Every shape of "credit this to somebody else".
    registerApp($this->customer, 'customer', [
        'user_id' => $victim->id, 'account_id' => $victim->id, 'uuid' => $victim->uuid,
    ])->assertOk();

    expect(MobileAppInstallation::where('user_id', $victim->id)->exists())->toBeFalse();
    expect(MobileAppInstallation::where('user_id', $this->customer->id)->count())->toBe(1);
    expect(appStatus($victim, 'customer')->assertOk()->json('data.status.usage_status'))->toBe('unknown');
});

it('ignores a client-supplied timestamp, because a claim is not a clock', function () {
    registerApp($this->customer, 'customer', [
        'first_authenticated_use_at' => '2020-01-01T00:00:00Z',
        'last_authenticated_use_at'  => '2030-01-01T00:00:00Z',
    ])->assertOk();

    $row = MobileAppInstallation::first();
    expect($row->first_authenticated_use_at->year)->toBe(now()->year);
    expect($row->last_authenticated_use_at->year)->toBe(now()->year);
});

// ── Audience separation ──────────────────────────────────────────────────────

it('keeps the customer and admin app states apart for the same human', function () {
    // The provider is a business identity, so only the admin app applies.
    registerApp($this->provider, 'admin')->assertOk();

    expect(appStatus($this->provider, 'admin')->assertOk()->json('data.status.usage_status'))->toBe('used_app');

    // And a customer who has used the customer app has no admin adoption.
    registerApp($this->customer, 'customer')->assertOk();
    expect(MobileAppInstallation::where('app', 'admin')->count())->toBe(1);
    expect(MobileAppInstallation::where('app', 'customer')->count())->toBe(1);
});

it('does not let a customer register admin adoption, or a provider customer adoption', function () {
    registerApp($this->customer, 'admin')->assertStatus(403);
    registerApp($this->provider, 'customer')->assertStatus(403);

    expect(MobileAppInstallation::count())->toBe(0);
});

it('fixes the audience by the route, so the body cannot switch it', function () {
    registerApp($this->customer, 'customer', ['app' => 'admin'])->assertStatus(422);

    expect(MobileAppInstallation::count())->toBe(0);
});

// ── Shared devices ───────────────────────────────────────────────────────────

it('moves an installation to whoever signs in, and stops crediting the last account', function () {
    $installation = (string) Str::uuid();
    $second = appUser('customer', 'Second Customer');

    registerApp($this->customer, 'customer', ['installation_id' => $installation])->assertOk();

    // The first account logs out and someone else signs in on the same phone.
    $handover = registerApp($second, 'customer', ['installation_id' => $installation])->assertOk();
    expect($handover->json('data.outcome'))->toBe('rebound');

    // One record, now theirs. The person who left is back to unknown, because
    // later activity on that phone is not theirs.
    expect(MobileAppInstallation::count())->toBe(1);
    expect(appStatus($this->customer, 'customer')->assertOk()->json('data.status.usage_status'))->toBe('unknown');
    expect(appStatus($second, 'customer')->assertOk()->json('data.status.usage_status'))->toBe('used_app');

    $row = MobileAppInstallation::first();
    expect($row->rebound_at)->not->toBeNull();
    // First use restarts with the new account rather than carrying over.
    expect($row->first_authenticated_use_at->toIso8601String())->toBe($row->last_authenticated_use_at->toIso8601String());
});

it('keeps two installations of the same account separate', function () {
    registerApp($this->customer, 'customer', ['installation_id' => (string) Str::uuid(), 'platform' => 'ios'])->assertOk();
    registerApp($this->customer, 'customer', ['installation_id' => (string) Str::uuid(), 'platform' => 'android'])->assertOk();

    expect(MobileAppInstallation::where('user_id', $this->customer->id)->count())->toBe(2);
});

// ── Refresh window ──────────────────────────────────────────────────────────

it('does not rewrite its own last-use timestamp on every foreground', function () {
    $installation = (string) Str::uuid();
    registerApp($this->customer, 'customer', ['installation_id' => $installation])->assertOk();

    $first = MobileAppInstallation::first()->last_authenticated_use_at->toIso8601String();

    $again = registerApp($this->customer, 'customer', ['installation_id' => $installation])->assertOk();
    expect($again->json('data.outcome'))->toBe('throttled');
    expect(MobileAppInstallation::first()->last_authenticated_use_at->toIso8601String())->toBe($first);

    // Past the window it is a genuine new session.
    MobileAppInstallation::query()->update([
        'last_authenticated_use_at' => now()->subMinutes(MobileAppAdoption::REFRESH_MINUTES + 5),
    ]);
    $later = registerApp($this->customer, 'customer', ['installation_id' => $installation])->assertOk();
    expect($later->json('data.outcome'))->toBe('refreshed');
});

it('validates what an app may send', function () {
    registerApp($this->customer, 'customer', ['installation_id' => 'not-a-uuid'])->assertStatus(422);
    registerApp($this->customer, 'customer', ['platform' => 'windows'])->assertStatus(422);
    registerApp($this->customer, 'customer', ['app_version' => str_repeat('9', 40)])->assertStatus(422);

    expect(MobileAppInstallation::count())->toBe(0);
});

// ── Self-report stays a preference ──────────────────────────────────────────

it('keeps the self-report separate from evidence, and lets it be undone', function () {
    $status = setSelfReport($this->customer, 'customer', true)->assertOk()->json('data.status');

    expect($status['self_reported'])->toBeTrue();
    // It silences the prompt WITHOUT ever becoming evidence of use.
    expect($status['suppress_prompt'])->toBeTrue();
    expect($status['usage_status'])->toBe('unknown');
    expect($status['first_authenticated_use_at'])->toBeNull();

    $undone = setSelfReport($this->customer, 'customer', false)->assertOk()->json('data.status');
    expect($undone['self_reported'])->toBeFalse();
    expect($undone['suppress_prompt'])->toBeFalse();
});

it('keeps the self-report per app audience', function () {
    setSelfReport($this->provider, 'admin', true)->assertOk();

    expect(appStatus($this->provider, 'admin')->assertOk()->json('data.status.self_reported'))->toBeTrue();
    expect($this->provider->fresh()->mobile_app_self_reported)->toBe(['admin' => true]);
});

it('stores nothing but the known audiences in the preference column', function () {
    setSelfReport($this->customer, 'customer', true)->assertOk();

    DB::table('users')->where('id', $this->customer->id)
        ->update(['mobile_app_self_reported' => json_encode(['customer' => true, 'spoof' => true])]);

    setSelfReport($this->customer, 'customer', true)->assertOk();
    expect(array_keys($this->customer->fresh()->mobile_app_self_reported))->toBe(['customer']);
});

// ── Suppression policy ──────────────────────────────────────────────────────

it('stops suppressing the prompt once use is no longer recent', function () {
    registerApp($this->customer, 'customer')->assertOk();
    expect(appStatus($this->customer, 'customer')->assertOk()->json('data.status.suppress_prompt'))->toBeTrue();

    MobileAppInstallation::query()->update([
        'last_authenticated_use_at' => now()->subDays(MobileAppAdoption::RECENT_USE_DAYS + 1),
    ]);

    // Still evidence that they used it — but a timestamp that old cannot show
    // the app is still installed, so asking again is the honest behaviour.
    $status = appStatus($this->customer, 'customer')->assertOk()->json('data.status');
    expect($status['usage_status'])->toBe('used_app');
    expect($status['suppress_prompt'])->toBeFalse();
});

it('states the suppression policy it applied rather than leaving it implicit', function () {
    registerApp($this->customer, 'customer')->assertOk();

    $policy = appStatus($this->customer, 'customer')->assertOk()->json('data.status.policy');
    expect($policy['recent_use_days'])->toBe(MobileAppAdoption::RECENT_USE_DAYS);
    expect($policy['reason'])->toBe('recent_authenticated_use');

    setSelfReport($this->provider, 'admin', true)->assertOk();
    expect(appStatus($this->provider, 'admin')->assertOk()->json('data.status.policy.reason'))->toBe('self_reported');
});

// ── Reporting ───────────────────────────────────────────────────────────────

it('reports evidence states as used or no confirmed use, never as downloaded', function () {
    registerApp($this->customer, 'customer')->assertOk();
    $quiet = appUser('customer', 'Quiet Customer');
    setSelfReport($quiet, 'customer', true)->assertOk();

    $data = adoptionReport($this->platform, ['app' => 'customer'])->assertOk()->json('data');

    $rows = collect($data['rows'])->keyBy('account_name');
    expect($rows['App Customer']['evidence_label'])->toBe('Used the app');
    expect($rows['Quiet Customer']['evidence_label'])->toBe('No confirmed app use');

    // The legend is in the response, so a dashboard cannot relabel it.
    expect($data['legend'])->toBe(['used_app' => 'Used the app', 'unknown' => 'No confirmed app use']);
    expect(json_encode($data))->not->toContain('Downloaded');
});

it('counts self-reports separately from confirmed use', function () {
    registerApp($this->customer, 'customer')->assertOk();
    $quiet = appUser('customer', 'Quiet Customer');
    setSelfReport($quiet, 'customer', true)->assertOk();
    appUser('customer', 'Silent Customer');

    $totals = adoptionReport($this->platform, ['app' => 'customer'])->assertOk()->json('data.totals');

    expect($totals['accounts'])->toBe(3);
    expect($totals['used'])->toBe(1);
    expect($totals['unknown'])->toBe(2);
    // A self-report never inflates the use count.
    expect($totals['self_reported'])->toBe(1);
});

it('counts over the whole filter, not just the page it returned', function () {
    foreach (range(1, 5) as $i) {
        registerApp(appUser('customer', "Paged Customer {$i}"), 'customer')->assertOk();
    }

    $data = adoptionReport($this->platform, ['app' => 'customer', 'per_page' => 2])->assertOk()->json('data');

    expect($data['rows'])->toHaveCount(2);
    // Six customers: the five above plus the one from beforeEach.
    expect($data['totals']['accounts'])->toBe(6);
    expect($data['totals']['used'])->toBe(5);
    expect($data['pagination']['total'])->toBe(6);
});

it('never exposes an email or a device identifier through the report', function () {
    registerApp($this->customer, 'customer', ['installation_id' => 'f1f0c0de-0000-4000-8000-000000000001'])->assertOk();

    $body = adoptionReport($this->platform, ['app' => 'customer'])->assertOk()->getContent();

    expect($body)->not->toContain($this->customer->email);
    expect($body)->not->toContain('f1f0c0de-0000-4000-8000-000000000001');
    expect(array_keys(json_decode($body, true)['data']['rows'][0]))->toBe([
        'account_id', 'account_name', 'app', 'evidence', 'evidence_label',
        'first_authenticated_use_at', 'last_authenticated_use_at', 'self_reported',
    ]);
});

it('keeps the report behind a capability', function () {
    adoptionReport($this->customer)->assertStatus(403);

    // A provider reads their own business's customers, not the platform's.
    $outside = appUser('customer', 'Someone Elses Customer');
    registerApp($outside, 'customer')->assertOk();

    $data = adoptionReport($this->provider, ['app' => 'customer'])->assertOk()->json('data');
    expect($data['scope']['kind'])->toBe('business');
    expect(collect($data['rows'])->pluck('account_name'))->not->toContain('Someone Elses Customer');
});

it('refuses an app audience it does not recognise', function () {
    adoptionReport($this->platform, ['app' => 'kiosk'])->assertStatus(422);
});

// ── Retention and deletion ──────────────────────────────────────────────────

it('removes installation records when the account is deleted', function () {
    registerApp($this->customer, 'customer')->assertOk();
    expect(MobileAppInstallation::count())->toBe(1);

    $this->customer->delete();

    expect(MobileAppInstallation::count())->toBe(0);
});

it('deletes records past the documented retention window and nothing sooner', function () {
    registerApp($this->customer, 'customer')->assertOk();
    $old = appUser('customer', 'Long Gone');
    registerApp($old, 'customer')->assertOk();

    MobileAppInstallation::where('user_id', $old->id)->update([
        'last_authenticated_use_at' => now()->subDays(MobileAppAdoption::RETENTION_DAYS + 1),
    ]);

    test()->artisan('mobile-app:prune-installations --dry-run')->assertSuccessful();
    expect(MobileAppInstallation::count())->toBe(2);

    test()->artisan('mobile-app:prune-installations')->assertSuccessful();

    expect(MobileAppInstallation::count())->toBe(1);
    expect(MobileAppInstallation::where('user_id', $this->customer->id)->exists())->toBeTrue();
    // The account returns to the honest answer rather than keeping a stale one.
    expect(appStatus($old, 'customer')->assertOk()->json('data.status.usage_status'))->toBe('unknown');
});

// ── Access ──────────────────────────────────────────────────────────────────

it('is closed to anyone who is not signed in', function () {
    foreach ([
        ['postJson', '/api/customer/mobile-app/sessions'],
        ['postJson', '/api/admin/mobile-app/sessions'],
        ['getJson', '/api/customer/mobile-app-status'],
        ['getJson', '/api/admin/mobile-app-status'],
        ['putJson', '/api/customer/mobile-app-preferences'],
        ['getJson', '/api/admin/mobile-app/adoption-report'],
    ] as [$method, $path]) {
        test()->{$method}($path, [])->assertStatus(401);
    }
});
