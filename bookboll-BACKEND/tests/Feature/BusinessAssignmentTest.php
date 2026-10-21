<?php

/**
 * Moving an account into a business (readmeAlamgir §1.5).
 *
 * The move is destructive: it strips platform roles, jurisdiction, venue /
 * listing / programme grants and custom permissions, then attaches one business
 * membership. So it is reviewed first, committed against the token that review
 * produced, and re-checked inside the transaction.
 *
 * "Do not retain platform privileges through stale JWT/session caches or
 * additive permission merging. Protect the acting account, the last active
 * superadmin, users already in another business and conflicting owners. A
 * blocked move must leave both memberships unchanged."
 */

use App\Models\Business;
use App\Models\BusinessMembership;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Role;

uses(RefreshDatabase::class);

beforeEach(function () {
    foreach (['super_admin', 'admin', 'provider_admin', 'platform_staff', 'customer'] as $r) {
        Role::findOrCreate($r, 'web');
    }
});

function admin(string $role = 'super_admin'): User
{
    $u = User::factory()->create(['status' => User::ACTIVE]);
    $u->assignRole($role);

    return $u->fresh();
}

function ownedBusiness(): Business
{
    return Business::create([
        'owner_id' => null, 'name' => 'Doha Padel', 'username' => 'doha-'.uniqid(),
        'status' => Business::STATUS_ACTIVE, 'plan' => 'free',
    ]);
}

/** Review a move and return its token. */
function previewMove(User $actor, Business $b, User $target, array $over = []): array
{
    return test()->actingAs($actor)->postJson(
        "/api/admin/businesses/{$b->id}/assignment-preview",
        array_merge(['user_id' => $target->id, 'role' => 'staff', 'scope' => 'all'], $over)
    )->json('data') ?? [];
}

it('lists assignable roles, candidates and venues without exposing a directory', function () {
    $actor = admin();
    $b = ownedBusiness();
    // A candidate must be on the platform team — that is what this list is for.
    $free = User::factory()->create(['status' => User::ACTIVE, 'name' => 'Free Agent']);
    $free->assignRole('platform_staff');

    $res = $this->actingAs($actor)->getJson("/api/admin/businesses/{$b->id}/assignment-options")->assertOk();

    expect($res->json('data.contract_version'))->toBe(1)
        ->and($res->json('data.can_assign'))->toBeTrue()
        // 'owner' is never offered through this flow.
        ->and($res->json('data.roles'))->not->toContain('owner');

    $ids = collect($res->json('data.candidates'))->pluck('id')->all();
    expect($ids)->toContain((string) $free->id)
        // The actor is not a candidate for their own move.
        ->and($ids)->not->toContain((string) $actor->id);

    // Only what the picker needs to show WHO is being moved — never a user
    // directory: no phone, no address, nothing a move does not depend on.
    expect(array_keys($res->json('data.candidates.0')))
        ->toBe(['id', 'name', 'email', 'role', 'roles', 'venues', 'last_login']);
});

it('warns about every inherited permission the move will remove', function () {
    $actor = admin();
    $b = ownedBusiness();
    $target = User::factory()->create([
        'status' => User::ACTIVE,
        'assigned_country_ids' => [179],
        'assigned_location_ids' => [1, 2],
        'custom_permissions' => ['grant' => ['manage_team']],
    ]);
    $target->assignRole('platform_staff');

    $data = previewMove($actor, $b, $target->fresh());
    $warnings = implode(' | ', $data['warnings'] ?? []);

    expect($warnings)->toContain('platform_staff')
        ->and($warnings)->toContain('Assigned countries')
        ->and($warnings)->toContain('venue assignments')
        ->and($warnings)->toContain('Custom platform permissions')
        ->and($warnings)->toContain('signed out')
        ->and($data['blockers'])->toBe([]);
});

it('strips platform access atomically and signs the account out', function () {
    $actor = admin();
    $b = ownedBusiness();
    $target = User::factory()->create([
        'status' => User::ACTIVE,
        'assigned_country_ids' => [179], 'assigned_location_ids' => [1],
        'custom_permissions' => ['grant' => ['manage_team']],
    ]);
    $target->assignRole('platform_staff');
    $target->createToken('session');          // a live session

    $token = previewMove($actor, $b, $target->fresh())['preview_token'];

    $res = $this->actingAs($actor)->postJson("/api/admin/businesses/{$b->id}/assignments", [
        'preview_token' => $token, 'idempotency_key' => 'k-'.uniqid(),
    ])->assertOk();

    expect($res->json('data.membership.role'))->toBe('staff');

    $target->refresh();
    expect($target->getRoleNames()->all())->toBe([])
        ->and($target->assigned_country_ids)->toBeNull()
        ->and($target->assigned_location_ids)->toBeNull()
        ->and($target->custom_permissions)->toBeNull()
        // Stale tokens would keep the old privileges alive.
        ->and($target->tokens()->count())->toBe(0)
        ->and(BusinessMembership::where('user_id', $target->id)->active()->count())->toBe(1);
});

it('refuses to move the acting account', function () {
    $actor = admin();
    $data = previewMove($actor, ownedBusiness(), $actor);

    expect(implode(' ', $data['blockers']))->toContain('your own account');
});

it('refuses to move the last active super admin', function () {
    $actor = admin();                                   // the only super_admin
    $other = admin('admin');
    $data = previewMove($other, ownedBusiness(), $actor);

    expect(implode(' ', $data['blockers']))->toContain('last active super admin');
});

it('refuses an account that already belongs to another business', function () {
    $actor = admin();
    $a = ownedBusiness();
    $b = ownedBusiness();
    $target = User::factory()->create(['status' => User::ACTIVE]);
    BusinessMembership::create([
        'business_id' => $a->id, 'user_id' => $target->id, 'role' => 'staff',
        'scope' => 'all', 'status' => BusinessMembership::STATUS_ACTIVE,
    ]);

    $data = previewMove($actor, $b, $target);
    expect(implode(' ', $data['blockers']))->toContain('another business');
});

it('leaves everything unchanged when a blocked move is committed anyway', function () {
    $actor = admin();
    $a = ownedBusiness();
    $b = ownedBusiness();
    $target = User::factory()->create(['status' => User::ACTIVE]);
    $target->assignRole('platform_staff');

    // Reviewed while clean...
    $token = previewMove($actor, $b, $target->fresh())['preview_token'];

    // ...then the account joins another business before the commit lands.
    BusinessMembership::create([
        'business_id' => $a->id, 'user_id' => $target->id, 'role' => 'staff',
        'scope' => 'all', 'status' => BusinessMembership::STATUS_ACTIVE,
    ]);

    $this->actingAs($actor)->postJson("/api/admin/businesses/{$b->id}/assignments", [
        'preview_token' => $token, 'idempotency_key' => 'k-'.uniqid(),
    ])->assertStatus(409);

    // Both memberships unchanged, platform role intact.
    $target->refresh();
    expect($target->getRoleNames()->all())->toBe(['platform_staff'])
        ->and(BusinessMembership::where('user_id', $target->id)->active()->count())->toBe(1)
        ->and(BusinessMembership::where('business_id', $b->id)->count())->toBe(0);
});

it('refuses a replayed token, a consumed one and an expired one', function () {
    $actor = admin();
    $b = ownedBusiness();
    $other = ownedBusiness();
    $target = User::factory()->create(['status' => User::ACTIVE]);

    $token = previewMove($actor, $b, $target)['preview_token'];

    // Replay against a different business.
    $this->actingAs($actor)->postJson("/api/admin/businesses/{$other->id}/assignments", [
        'preview_token' => $token, 'idempotency_key' => 'k1',
    ])->assertStatus(409);

    // Correct business: succeeds once.
    $this->actingAs($actor)->postJson("/api/admin/businesses/{$b->id}/assignments", [
        'preview_token' => $token, 'idempotency_key' => 'k2',
    ])->assertOk();

    // The same token a second time, with a NEW key, is a consumed review.
    $this->actingAs($actor)->postJson("/api/admin/businesses/{$b->id}/assignments", [
        'preview_token' => $token, 'idempotency_key' => 'k3',
    ])->assertStatus(409);
});

it('returns the original membership when a commit is retried', function () {
    $actor = admin();
    $b = ownedBusiness();
    $target = User::factory()->create(['status' => User::ACTIVE]);
    $token = previewMove($actor, $b, $target)['preview_token'];
    $key = 'same-key';

    $first = $this->actingAs($actor)->postJson("/api/admin/businesses/{$b->id}/assignments",
        ['preview_token' => $token, 'idempotency_key' => $key])->assertOk()->json('data.membership.id');

    // A lost response, retried: one membership, not two.
    $second = $this->actingAs($actor)->postJson("/api/admin/businesses/{$b->id}/assignments",
        ['preview_token' => $token, 'idempotency_key' => $key])->assertOk()->json('data.membership.id');

    expect($second)->toBe($first)
        ->and(BusinessMembership::where('user_id', $target->id)->count())->toBe(1);
});

it('keeps providers out of business administration entirely', function () {
    $provider = admin('provider_admin');
    $b = ownedBusiness();

    $this->actingAs($provider)->getJson("/api/admin/businesses/{$b->id}/assignment-options")->assertStatus(403);
    $this->actingAs($provider)->postJson("/api/admin/businesses/{$b->id}/assignment-preview",
        ['user_id' => 1, 'role' => 'staff', 'scope' => 'all'])->assertStatus(403);
});

it('refuses venues that do not belong to the business', function () {
    $actor = admin();
    $b = ownedBusiness();
    $target = User::factory()->create(['status' => User::ACTIVE]);

    $data = previewMove($actor, $b, $target, ['scope' => 'selected', 'location_ids' => [9999]]);

    expect(implode(' ', $data['blockers']))->toContain('do not belong to this business');
});

/*
 * Choosing an owner on the Add business form.
 *
 * The form offers an existing account at creation time, but creation itself
 * still refuses one — BusinessIdentityService::store accepts `owner_user_id`
 * only as null. The account is attached immediately afterwards through the
 * reviewed flow above, so there is exactly one audited way into a business.
 * This endpoint only fills the picker, before any business exists.
 */
it('offers the same candidates for a business that does not exist yet', function () {
    $actor = admin();
    $free  = User::factory()->create(['status' => User::ACTIVE, 'name' => 'Free Agent']);
    $free->assignRole('platform_staff');

    $res = $this->actingAs($actor)->getJson('/api/admin/businesses/assignment-candidates')->assertOk();

    expect($res->json('data.contract_version'))->toBe(1)
        ->and($res->json('data.roles'))->not->toContain('owner')
        ->and($res->json('data.roles'))->toContain('provider_admin');

    $ids = collect($res->json('data.candidates'))->pluck('id')->all();
    expect($ids)->toContain((string) $free->id)
        ->and($ids)->not->toContain((string) $actor->id)
        ->and(array_keys($res->json('data.candidates.0')))
            ->toBe(['id', 'name', 'email', 'role', 'roles', 'venues', 'last_login']);
});

it('leaves out accounts that already belong to a business', function () {
    $actor = admin();
    $b     = ownedBusiness();
    $taken = User::factory()->create(['status' => User::ACTIVE]);
    BusinessMembership::create([
        'business_id' => $b->id, 'user_id' => $taken->id,
        'role' => 'staff', 'status' => BusinessMembership::STATUS_ACTIVE,
    ]);
    $inactive = User::factory()->create(['status' => User::INACTIVE]);

    $ids = collect($this->actingAs($actor)->getJson('/api/admin/businesses/assignment-candidates')
        ->assertOk()->json('data.candidates'))->pluck('id')->all();

    expect($ids)->not->toContain((string) $taken->id)
        ->and($ids)->not->toContain((string) $inactive->id);
});

it('refuses the candidate list to a provider-scoped account', function () {
    $provider = User::factory()->create(['status' => User::ACTIVE]);
    $provider->assignRole('provider_admin');

    $this->actingAs($provider)->getJson('/api/admin/businesses/assignment-candidates')->assertStatus(403);
});

it('still refuses an owner at creation, so the move stays audited', function () {
    $actor = admin();
    $free  = User::factory()->create(['status' => User::ACTIVE]);

    $this->actingAs($actor)->postJson('/api/admin/businesses', [
        'business_name'   => 'Owned At Birth',
        'username'        => 'owned-at-birth',
        'owner_user_id'   => $free->id,
        'idempotency_key' => 'test-'.uniqid(),
    ])->assertStatus(422)->assertJsonValidationErrors(['owner_user_id']);

    expect(Business::where('username', 'owned-at-birth')->exists())->toBeFalse();
});

/*
 * An assigned owner has to be VISIBLE.
 *
 * The move writes a BusinessMembership and clears the account's platform role;
 * it deliberately does not touch `businesses.owner_id`, because the owner
 * membership is the authoritative record. But the Team page read owner and
 * staff count from `owner_id` alone, so a business whose owner had just been
 * assigned still said "Owner not assigned · 0 team members", and the person
 * moved into it disappeared from the page — making a successful move look like
 * a failed one, twice over.
 */
it('shows an assigned manager as the owner of the business', function () {
    $actor  = admin();
    $b      = ownedBusiness();
    $target = User::factory()->create(['status' => User::ACTIVE, 'name' => 'New Owner']);

    $preview = previewMove($actor, $b, $target, ['role' => 'provider_admin']);
    $this->actingAs($actor)->postJson("/api/admin/businesses/{$b->id}/assignments", [
        'preview_token' => $preview['preview_token'], 'idempotency_key' => 'vis-'.uniqid(),
    ])->assertOk();

    // owner_id is still null — the membership is what makes them the owner.
    expect(Business::find($b->id)->owner_id)->toBeNull();

    $row = collect($this->actingAs($actor)->getJson('/api/admin/businesses')->assertOk()
        ->json('data.businesses.data'))->firstWhere('id', $b->id);

    expect($row['owner_user_id'])->toBe($target->id)
        ->and($row['full_name'])->toBe('New Owner')
        ->and($row['email'])->toBe($target->email)
        ->and($row['staff_count'])->toBe(1);
});

it('lists the moved account with its business and business role', function () {
    $actor  = admin();
    $b      = ownedBusiness();
    $target = User::factory()->create(['status' => User::ACTIVE]);
    $target->assignRole('platform_staff');

    $preview = previewMove($actor, $b, $target->fresh(), ['role' => 'staff']);
    $this->actingAs($actor)->postJson("/api/admin/businesses/{$b->id}/assignments", [
        'preview_token' => $preview['preview_token'], 'idempotency_key' => 'vis2-'.uniqid(),
    ])->assertOk();

    $users = collect($this->actingAs($actor)->getJson('/api/admin/users?scope=provider_staff&records_per_page=200')
        ->assertOk()->json('data.users.data') ?? []);
    $row = $users->firstWhere('id', $target->id);

    // The platform role is gone, so without reading the membership this row had
    // no role and no business and could not be grouped under anything.
    expect($row)->not->toBeNull()
        ->and($row['business_id'])->toBe($b->id)
        ->and($row['role'])->toBe('staff');
});

it('counts a legacy owner_user_id team and a membership team without double counting', function () {
    $actor = admin();
    $legacyOwner = User::factory()->create(['status' => User::ACTIVE]);
    $legacyOwner->assignRole('provider_admin');
    $b = Business::create([
        'owner_id' => $legacyOwner->id, 'name' => 'Legacy Co', 'username' => 'legacy-'.uniqid(),
        'status' => Business::STATUS_ACTIVE, 'plan' => 'free',
    ]);
    // One staff member the old way…
    User::factory()->create(['status' => User::ACTIVE, 'owner_user_id' => $legacyOwner->id]);
    // …and the same person also holding a membership must count once.
    $both = User::factory()->create(['status' => User::ACTIVE, 'owner_user_id' => $legacyOwner->id]);
    BusinessMembership::create([
        'business_id' => $b->id, 'user_id' => $both->id,
        'role' => 'staff', 'status' => BusinessMembership::STATUS_ACTIVE,
    ]);

    $row = collect($this->actingAs($actor)->getJson('/api/admin/businesses')->assertOk()
        ->json('data.businesses.data'))->firstWhere('id', $b->id);

    expect($row['staff_count'])->toBe(2)
        // owner_id still resolves the owner when there is no managing membership.
        ->and($row['owner_user_id'])->toBe($legacyOwner->id);
});

/*
 * The picker offers colleagues, not customers.
 *
 * `candidates()` filtered on "active and not already in a business" alone,
 * which on live data is 16,074 accounts — the entire customer base — of which
 * 8 held a platform role. Capped at 200 and sorted by name, the admins the
 * screen exists to move were not even in the list, so an admin could not find
 * a colleague to assign. The set is now the Platform Team, the same definition
 * that tab uses.
 */
it('offers platform team accounts and leaves customers out', function () {
    $actor = admin();

    $colleague = User::factory()->create(['status' => User::ACTIVE, 'name' => 'Zoe Colleague']);
    $colleague->assignRole('platform_staff');
    $customer = User::factory()->create(['status' => User::ACTIVE, 'name' => 'Aaa Customer']);
    $customer->assignRole('customer');
    $noRole = User::factory()->create(['status' => User::ACTIVE, 'name' => 'Aab Nobody']);

    $ids = collect($this->actingAs($actor)->getJson('/api/admin/businesses/assignment-candidates')
        ->assertOk()->json('data.candidates'))->pluck('id')->all();

    expect($ids)->toContain((string) $colleague->id)
        ->and($ids)->not->toContain((string) $customer->id)
        ->and($ids)->not->toContain((string) $noRole->id);
});

it('describes each candidate well enough to tell them apart', function () {
    $actor = admin();
    $person = User::factory()->create([
        'status' => User::ACTIVE, 'name' => 'Ali Nawaz', 'last_login_at' => now()->subDays(6),
    ]);
    $person->assignRole('platform_staff');

    $row = collect($this->actingAs($actor)->getJson('/api/admin/businesses/assignment-candidates')
        ->assertOk()->json('data.candidates'))->firstWhere('id', (string) $person->id);

    expect($row['name'])->toBe('Ali Nawaz')
        ->and($row['email'])->toBe($person->email)
        ->and($row['role'])->toBe('platform_staff')
        ->and($row)->toHaveKeys(['venues', 'last_login']);
});

it('narrows the list on the server so a big team is still searchable', function () {
    $actor = admin();
    foreach (['Needle Person', 'Haystack One', 'Haystack Two'] as $name) {
        User::factory()->create(['status' => User::ACTIVE, 'name' => $name])->assignRole('platform_staff');
    }

    $names = collect($this->actingAs($actor)
        ->getJson('/api/admin/businesses/assignment-candidates?search=Needle')
        ->assertOk()->json('data.candidates'))->pluck('name')->all();

    expect($names)->toContain('Needle Person')
        ->and($names)->not->toContain('Haystack One');
});
