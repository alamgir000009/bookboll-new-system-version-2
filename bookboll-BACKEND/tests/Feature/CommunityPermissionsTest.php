<?php

/**
 * Community permissions existed in the UI and nowhere else.
 *
 * `post_permission` and `chat_permission` were stored as whatever a client
 * sent, read by nothing, and enforced by nothing — the server checked only the
 * legacy `chat_locked` flag. The moderator role did not exist at all: the role
 * endpoint accepted admin and member. So an "Admins only" feed accepted posts
 * from every member, and a community could be taken over by promoting oneself.
 *
 * These pin the whole matrix, both directions: that each audience is enforced,
 * that a moderator's narrow patch is accepted without granting them anything
 * else, and that the role boundaries hold against a member uuid from another
 * community.
 */

use App\Models\Community;
use App\Models\CommunityMember;
use App\Models\CommunityPost;
use App\Models\CustomerMessage;
use App\Models\User;
use App\Support\CommunityPermissions;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use Spatie\Permission\Models\Role;

uses(RefreshDatabase::class);

beforeEach(function () {
    Role::findOrCreate('customer', 'web');

    $this->owner = member('Owner');
    $this->community = Community::create([
        'owner_customer_id' => $this->owner->uuid,
        'name' => 'North Club', 'moderation_status' => 'active',
        'settings' => CommunityPermissions::DEFAULTS,
    ]);
    seat($this->community, $this->owner, 'owner');

    $this->admin = seat($this->community, member('Admin'), 'admin')->user;
    $this->moderator = seat($this->community, member('Moderator'), 'moderator')->user;
    $this->player = seat($this->community, member('Player'), 'member')->user;
    $this->pending = seat($this->community, member('Pending'), 'member', 'pending')->user;
    $this->outsider = member('Outsider');
});

function member(string $name): User
{
    $u = User::factory()->create(['name' => $name, 'status' => User::ACTIVE, 'uuid' => (string) Str::uuid()]);
    $u->assignRole('customer');

    return $u->fresh();
}

function seat(Community $c, User $u, string $role, string $status = 'accepted'): CommunityMember
{
    $m = CommunityMember::create([
        'community_id' => $c->id, 'customer_id' => $u->uuid,
        'role' => $role, 'status' => $status, 'joined_at' => now(),
    ]);
    $m->user = $u;

    return $m;
}

function audience(Community $c, string $channel, string $value): void
{
    $c->settings = array_merge(CommunityPermissions::settings($c), [$channel.'_permission' => $value]);
    $c->save();
}

/** The role endpoint, as the storefront calls it: by member id alone. */
function setRole(User $actor, CommunityMember $target, string $role)
{
    Sanctum::actingAs($actor);

    return test()->patchJson("/api/customer/community-members/{$target->uuid}/role", ['role' => $role]);
}

function post(User $actor, Community $c, string $body = 'Hello')
{
    Sanctum::actingAs($actor);

    return test()->postJson("/api/customer/communities/{$c->uuid}/posts", ['body' => $body]);
}

function chat(User $actor, Community $c, string $body = 'Hi')
{
    Sanctum::actingAs($actor);

    return test()->postJson('/api/customer/messages/group', ['key' => 'community__'.$c->uuid, 'body' => $body]);
}

function patchCommunity(User $actor, Community $c, array $payload)
{
    Sanctum::actingAs($actor);

    return test()->patchJson("/api/customer/communities/{$c->uuid}", $payload);
}

function storedSettings(Community $c): array
{
    return CommunityPermissions::settings($c->fresh());
}

// ── Explicit settings ─────────────────────────────────────────────────────────

it('returns explicit permissions, so a dropped setting is visible rather than silent', function () {
    $settings = test()->withHeader('from', 'frontend-web')
        ->getJson("/api/marketplace/communities/{$this->community->uuid}")
        ->assertOk()->json('data.community.settings');

    expect($settings['post_permission'])->toBe('members');
    expect($settings['chat_permission'])->toBe('members');
    expect($settings['chat_locked'])->toBeFalse();
});

it('reads a legacy locked chat as admins only without having been migrated', function () {
    // Rows saved before explicit permissions existed said only this much.
    $this->community->settings = ['chat_locked' => true];
    $this->community->save();

    expect(storedSettings($this->community)['chat_permission'])->toBe('admins');

    // And it is enforced as such, not merely reported.
    chat($this->player, $this->community)->assertStatus(403);
    chat($this->admin, $this->community)->assertOk();
});

it('creates a community with its permissions already saved and returned', function () {
    Sanctum::actingAs($this->outsider);

    $created = test()->postJson('/api/customer/communities', [
        'name' => 'South Club',
        'settings' => ['post_permission' => 'admins', 'chat_permission' => 'moderators'],
    ])->assertOk()->json('data.community');

    expect($created['settings']['post_permission'])->toBe('admins');
    expect($created['settings']['chat_permission'])->toBe('moderators');
    expect($created['settings']['chat_locked'])->toBeFalse();

    expect(Community::where('uuid', $created['id'])->first()->settings['post_permission'])->toBe('admins');
});

it('refuses a permission value it does not recognise instead of storing it', function () {
    Sanctum::actingAs($this->outsider);
    test()->postJson('/api/customer/communities', [
        'name' => 'Bad Club', 'settings' => ['post_permission' => 'everyone'],
    ])->assertStatus(422);

    expect(Community::where('name', 'Bad Club')->exists())->toBeFalse();

    patchCommunity($this->owner, $this->community, ['settings' => ['chat_permission' => 'nobody']])
        ->assertStatus(422);
    expect(storedSettings($this->community)['chat_permission'])->toBe('members');
});

it('merges a settings patch instead of replacing everything saved beside it', function () {
    $this->community->settings = array_merge(CommunityPermissions::settings($this->community), [
        'post_permission' => 'admins',
        'custom_welcome'  => 'Bring your own water',
    ]);
    $this->community->save();

    patchCommunity($this->owner, $this->community, ['settings' => ['chat_permission' => 'moderators']])->assertOk();

    $saved = storedSettings($this->community);
    expect($saved['chat_permission'])->toBe('moderators');
    expect($saved['post_permission'])->toBe('admins');
    expect($saved['custom_welcome'])->toBe('Bring your own water');
});

it('keeps the compatibility flag in step with the permission it stands for', function () {
    patchCommunity($this->owner, $this->community, ['settings' => ['chat_permission' => 'admins']])->assertOk();
    expect(storedSettings($this->community)['chat_locked'])->toBeTrue();

    // A request contradicting itself cannot store a row that contradicts itself.
    patchCommunity($this->owner, $this->community, [
        'settings' => ['chat_permission' => 'members', 'chat_locked' => true],
    ])->assertOk();
    expect(storedSettings($this->community)['chat_locked'])->toBeFalse();
});

// ── Feed permission ──────────────────────────────────────────────────────────

it('enforces the feed audience for every role', function () {
    audience($this->community, 'post', 'members');
    post($this->player, $this->community)->assertOk();
    post($this->moderator, $this->community)->assertOk();

    audience($this->community, 'post', 'moderators');
    post($this->player, $this->community)->assertStatus(403);
    post($this->moderator, $this->community)->assertOk();
    post($this->admin, $this->community)->assertOk();
    post($this->owner, $this->community)->assertOk();

    audience($this->community, 'post', 'admins');
    post($this->moderator, $this->community)->assertStatus(403);
    post($this->admin, $this->community)->assertOk();
    post($this->owner, $this->community)->assertOk();
});

it('never lets a pending member or an outsider post, whatever the audience', function () {
    audience($this->community, 'post', 'members');

    post($this->pending, $this->community)->assertStatus(403);
    post($this->outsider, $this->community)->assertStatus(403);
    expect(CommunityPost::count())->toBe(0);
});

// ── Chat permission ──────────────────────────────────────────────────────────

it('enforces the chat audience for sending while members keep reading', function () {
    audience($this->community, 'chat', 'admins');

    chat($this->player, $this->community)->assertStatus(403);
    chat($this->moderator, $this->community)->assertStatus(403);
    chat($this->admin, $this->community)->assertOk();

    // Reading is unaffected: an announcements-only chat is still a chat people
    // are in, not one they are locked out of.
    Sanctum::actingAs($this->player);
    test()->getJson('/api/customer/messages/group?key=community__'.$this->community->uuid)->assertOk();

    audience($this->community, 'chat', 'moderators');
    chat($this->moderator, $this->community)->assertOk();
    chat($this->player, $this->community)->assertStatus(403);
});

it('applies a permission change to the very next message', function () {
    audience($this->community, 'chat', 'members');
    chat($this->player, $this->community, 'before')->assertOk();

    patchCommunity($this->owner, $this->community, ['settings' => ['chat_permission' => 'admins']])->assertOk();

    chat($this->player, $this->community, 'after')->assertStatus(403);
    expect(CustomerMessage::where('body', 'after')->exists())->toBeFalse();
});

it('keeps pending members and outsiders out of the group chat entirely', function () {
    chat($this->pending, $this->community)->assertStatus(403);
    chat($this->outsider, $this->community)->assertStatus(403);
});

// ── The moderator's narrow patch ─────────────────────────────────────────────

it('lets a moderator change only who can send group messages', function () {
    $response = patchCommunity($this->moderator, $this->community, [
        'settings' => ['chat_permission' => 'moderators', 'chat_locked' => false],
    ])->assertOk();

    expect($response->json('data.community.settings.chat_permission'))->toBe('moderators');
    expect(storedSettings($this->community)['chat_permission'])->toBe('moderators');
});

it('refuses a moderator patch carrying anything else, whole rather than in part', function () {
    audience($this->community, 'post', 'members');

    foreach ([
        ['settings' => ['chat_permission' => 'admins', 'post_permission' => 'admins']],
        ['settings' => ['chat_permission' => 'admins'], 'name' => 'Renamed Club'],
        ['name' => 'Renamed Club'],
        ['settings' => ['post_permission' => 'admins']],
        ['is_private' => true],
    ] as $patch) {
        patchCommunity($this->moderator, $this->community, $patch)->assertStatus(403);
    }

    // Nothing from any of those requests was applied.
    $saved = storedSettings($this->community);
    expect($saved['chat_permission'])->toBe('members');
    expect($saved['post_permission'])->toBe('members');
    expect($this->community->fresh()->name)->toBe('North Club');
    expect($this->community->fresh()->is_private)->toBeFalsy();
});

it('does not give the narrow patch to members, pending moderators or outsiders', function () {
    $patch = ['settings' => ['chat_permission' => 'admins', 'chat_locked' => true]];

    patchCommunity($this->player, $this->community, $patch)->assertStatus(403);
    patchCommunity($this->outsider, $this->community, $patch)->assertStatus(403);

    $pendingMod = seat($this->community, member('Pending Mod'), 'moderator', 'pending')->user;
    patchCommunity($pendingMod, $this->community, $patch)->assertStatus(403);

    expect(storedSettings($this->community)['chat_permission'])->toBe('members');
});

it('does not let a moderator of one community touch another', function () {
    $other = Community::create([
        'owner_customer_id' => $this->outsider->uuid, 'name' => 'Other Club',
        'moderation_status' => 'active', 'settings' => CommunityPermissions::DEFAULTS,
    ]);
    seat($other, $this->outsider, 'owner');

    patchCommunity($this->moderator, $other, ['settings' => ['chat_permission' => 'admins']])
        ->assertStatus(403);

    expect(storedSettings($other)['chat_permission'])->toBe('members');
});

// ── Role hierarchy ───────────────────────────────────────────────────────────

it('lets the owner assign moderators and admins, and read the role back', function () {
    $target = CommunityMember::where('customer_id', $this->player->uuid)->first();

    $body = setRole($this->owner, $target, 'moderator')->assertOk()->json('data.member');
    expect($body['role'])->toBe('moderator');
    expect($target->fresh()->role)->toBe('moderator');

    expect(setRole($this->owner, $target, 'admin')->assertOk()->json('data.member.role'))->toBe('admin');
    expect(setRole($this->owner, $target, 'member')->assertOk()->json('data.member.role'))->toBe('member');
});

it('lets an admin assign moderators but never admins', function () {
    $target = CommunityMember::where('customer_id', $this->player->uuid)->first();

    setRole($this->admin, $target, 'moderator')->assertOk();
    setRole($this->admin, $target, 'admin')->assertStatus(403);
    expect($target->fresh()->role)->toBe('moderator');
});

it('does not let an admin demote another admin and take the community', function () {
    $peer = CommunityMember::where('customer_id', $this->admin->uuid)->first();
    $secondAdmin = seat($this->community, member('Second Admin'), 'admin');

    setRole($secondAdmin->user, $peer, 'member')->assertStatus(403);
    expect($peer->fresh()->role)->toBe('admin');

    // Nor by removing them, which is the same demotion by another route.
    Sanctum::actingAs($secondAdmin->user);
    test()->deleteJson("/api/customer/community-members/{$peer->uuid}")->assertStatus(403);
    expect($peer->fresh())->not->toBeNull();

    // The owner can do both.
    setRole($this->owner, $peer, 'member')->assertOk();
});

it('never changes the owner, and never the acting person themselves', function () {
    $ownerSeat = CommunityMember::where('customer_id', $this->owner->uuid)->first();
    $adminSeat = CommunityMember::where('customer_id', $this->admin->uuid)->first();

    // Refused for everyone, owner included: ownership transfer is a separate
    // flow and it does not exist.
    setRole($this->owner, $ownerSeat, 'member')->assertStatus(422);
    setRole($this->admin, $ownerSeat, 'member')->assertStatus(422);
    expect($ownerSeat->fresh()->role)->toBe('owner');

    // No self-promotion, and no self-demotion either.
    setRole($this->admin, $adminSeat, 'moderator')->assertStatus(403);
    expect($adminSeat->fresh()->role)->toBe('admin');

    $modSeat = CommunityMember::where('customer_id', $this->moderator->uuid)->first();
    setRole($this->moderator, $modSeat, 'admin')->assertStatus(403);
    expect($modSeat->fresh()->role)->toBe('moderator');
});

it('refuses a role nobody defined, and a role for someone not yet accepted', function () {
    $target = CommunityMember::where('customer_id', $this->player->uuid)->first();
    setRole($this->owner, $target, 'superuser')->assertStatus(422);
    setRole($this->owner, $target, 'owner')->assertStatus(422);

    $pendingSeat = CommunityMember::where('customer_id', $this->pending->uuid)->first();
    setRole($this->owner, $pendingSeat, 'moderator')->assertStatus(422);
    expect($pendingSeat->fresh()->role)->toBe('member');
});

it('authorizes a role change against the member row, not the community id sent', function () {
    $other = Community::create([
        'owner_customer_id' => $this->outsider->uuid, 'name' => 'Other Club',
        'moderation_status' => 'active', 'settings' => CommunityPermissions::DEFAULTS,
    ]);
    seat($other, $this->outsider, 'owner');
    $theirMember = seat($other, member('Their Player'), 'member');

    // Our owner holds no power in a community they do not own, however the
    // request is addressed.
    setRole($this->owner, $theirMember, 'moderator')->assertStatus(403);
    expect($theirMember->fresh()->role)->toBe('member');

    Sanctum::actingAs($this->owner);
    test()->patchJson("/api/customer/communities/{$this->community->uuid}/members/{$theirMember->uuid}/role", ['role' => 'moderator'])
        ->assertStatus(404);
    expect($theirMember->fresh()->role)->toBe('member');
});

// ── Moderation powers ────────────────────────────────────────────────────────

it('lets moderators remove unwanted posts and messages, and authors their own', function () {
    $post = CommunityPost::create([
        'community_id' => $this->community->id, 'author_id' => $this->player->uuid,
        'body' => 'spam', 'moderation_status' => 'active',
    ]);

    Sanctum::actingAs($this->moderator);
    test()->deleteJson("/api/customer/community-posts/{$post->uuid}")->assertOk();
    expect(CommunityPost::where('uuid', $post->uuid)->exists())->toBeFalse();

    $own = CommunityPost::create([
        'community_id' => $this->community->id, 'author_id' => $this->player->uuid,
        'body' => 'mine', 'moderation_status' => 'active',
    ]);
    Sanctum::actingAs($this->player);
    test()->deleteJson("/api/customer/community-posts/{$own->uuid}")->assertOk();
});

it('does not let a member delete someone else, or a moderator reach another community', function () {
    $post = CommunityPost::create([
        'community_id' => $this->community->id, 'author_id' => $this->admin->uuid,
        'body' => 'keep', 'moderation_status' => 'active',
    ]);

    Sanctum::actingAs($this->player);
    test()->deleteJson("/api/customer/community-posts/{$post->uuid}")->assertStatus(403);
    expect(CommunityPost::where('uuid', $post->uuid)->exists())->toBeTrue();

    $other = Community::create([
        'owner_customer_id' => $this->outsider->uuid, 'name' => 'Other Club',
        'moderation_status' => 'active', 'settings' => CommunityPermissions::DEFAULTS,
    ]);
    seat($other, $this->outsider, 'owner');
    $theirPost = CommunityPost::create([
        'community_id' => $other->id, 'author_id' => $this->outsider->uuid,
        'body' => 'theirs', 'moderation_status' => 'active',
    ]);

    Sanctum::actingAs($this->moderator);
    test()->deleteJson("/api/customer/community-posts/{$theirPost->uuid}")->assertStatus(403);
    expect(CommunityPost::where('uuid', $theirPost->uuid)->exists())->toBeTrue();
});

it('will not let a moderator delete another community message by changing the key', function () {
    $other = Community::create([
        'owner_customer_id' => $this->outsider->uuid, 'name' => 'Other Club',
        'moderation_status' => 'active', 'settings' => CommunityPermissions::DEFAULTS,
    ]);
    seat($other, $this->outsider, 'owner');

    $theirs = CustomerMessage::create([
        'conversation_key' => 'community__'.$other->uuid,
        'sender_id' => $this->outsider->uuid, 'body' => 'theirs',
    ]);

    Sanctum::actingAs($this->moderator);

    // Claiming it belongs to our community does not make it ours.
    test()->deleteJson("/api/customer/messages/group/{$theirs->uuid}", ['key' => 'community__'.$this->community->uuid])
        ->assertStatus(403);
    // Nor does naming the real one we have no standing in.
    test()->deleteJson("/api/customer/messages/group/{$theirs->uuid}", ['key' => 'community__'.$other->uuid])
        ->assertStatus(403);

    expect(CustomerMessage::where('uuid', $theirs->uuid)->exists())->toBeTrue();
});

it('reports a deletion only once the row is gone', function () {
    $message = CustomerMessage::create([
        'conversation_key' => 'community__'.$this->community->uuid,
        'sender_id' => $this->player->uuid, 'body' => 'noise',
    ]);

    Sanctum::actingAs($this->moderator);
    test()->deleteJson("/api/customer/messages/group/{$message->uuid}", ['key' => 'community__'.$this->community->uuid])
        ->assertOk();

    expect(CustomerMessage::where('uuid', $message->uuid)->exists())->toBeFalse();
});

// ── The viewer's own role ────────────────────────────────────────────────────

it('tells the viewer their role, including an owner with no membership row', function () {
    Sanctum::actingAs($this->moderator);
    expect(test()->getJson("/api/marketplace/communities/{$this->community->uuid}")
        ->assertOk()->json('data.community.viewer_role'))->toBe('moderator');

    Sanctum::actingAs($this->pending);
    expect(test()->getJson("/api/marketplace/communities/{$this->community->uuid}")
        ->assertOk()->json('data.community.viewer_role'))->toBe('visitor');

    // A community whose owner row is missing must still answer "owner".
    CommunityMember::where('community_id', $this->community->id)
        ->where('customer_id', $this->owner->uuid)->delete();

    Sanctum::actingAs($this->owner);
    expect(test()->getJson("/api/marketplace/communities/{$this->community->uuid}")
        ->assertOk()->json('data.community.viewer_role'))->toBe('owner');
});

it('lists moderators with their role, ranked between admins and members', function () {
    Sanctum::actingAs($this->owner);

    $roles = collect(test()->getJson("/api/customer/communities/{$this->community->uuid}/members")
        ->assertOk()->json('data.members'))
        ->where('status', 'accepted')
        ->pluck('role')->values()->all();

    expect($roles)->toBe(['owner', 'admin', 'moderator', 'member']);
});

it('keeps the owner from leaving, so a community is never left without one', function () {
    Sanctum::actingAs($this->owner);
    test()->deleteJson("/api/customer/communities/{$this->community->uuid}/leave")
        ->assertStatus(400)
        ->assertJsonPath('success', false);

    expect(CommunityMember::where('community_id', $this->community->id)
        ->where('customer_id', $this->owner->uuid)->exists())->toBeTrue();
});

/**
 * The same table the storefront writes out (web/tests/communityAccess.test.js).
 *
 * Written in full on both sides on purpose: if the two drift, a member is shown
 * a compose box the server rejects, or a moderator is handed a permissions
 * panel that 403s. Changing one side without the other fails here.
 */
dataset('contributeMatrix', [
    ['owner', 'members', true], ['owner', 'moderators', true], ['owner', 'admins', true],
    ['admin', 'members', true], ['admin', 'moderators', true], ['admin', 'admins', true],
    ['moderator', 'members', true], ['moderator', 'moderators', true], ['moderator', 'admins', false],
    ['member', 'members', true], ['member', 'moderators', false], ['member', 'admins', false],
    ['visitor', 'members', false], ['visitor', 'moderators', false], ['visitor', 'admins', false],
]);

it('agrees with the storefront on the role by audience matrix', function (string $role, string $audience, bool $expected) {
    foreach (['posts', 'chat'] as $channel) {
        $settings = CommunityPermissions::settings(null);
        $settings[($channel === 'chat' ? 'chat' : 'post').'_permission'] = $audience;

        expect(CommunityPermissions::canContribute($role, $settings, $channel))
            ->toBe($expected, "{$role} / {$audience} / {$channel}");
    }
})->with('contributeMatrix');
