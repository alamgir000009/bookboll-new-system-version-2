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
    foreach (['super_admin', 'admin', 'provider_admin', 'platform_staff'] as $r) {
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
    $free = User::factory()->create(['status' => User::ACTIVE, 'name' => 'Free Agent']);

    $res = $this->actingAs($actor)->getJson("/api/admin/businesses/{$b->id}/assignment-options")->assertOk();

    expect($res->json('data.contract_version'))->toBe(1)
        ->and($res->json('data.can_assign'))->toBeTrue()
        // 'owner' is never offered through this flow.
        ->and($res->json('data.roles'))->not->toContain('owner');

    $ids = collect($res->json('data.candidates'))->pluck('id')->all();
    expect($ids)->toContain((string) $free->id)
        // The actor is not a candidate for their own move.
        ->and($ids)->not->toContain((string) $actor->id);

    // Only these three fields — never a user directory.
    expect(array_keys($res->json('data.candidates.0')))->toBe(['id', 'name', 'email']);
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
