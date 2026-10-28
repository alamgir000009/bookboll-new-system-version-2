<?php

/**
 * Who may host an event inside a community.
 *
 * Neither path checked anything. Creating an event with a `community_id` looked
 * the community up and attached it; attaching an existing event checked only
 * that the caller was the event's own host, which says nothing about the
 * community it is being posted into. Any signed-in user could put an event on
 * any community's page, where it sells seats under that community's name.
 *
 * Hosting is now a moderation-level act: owner, admin or moderator.
 */

use App\Models\Community;
use App\Models\CommunityMember;
use App\Models\HostedGame;
use App\Models\User;
use App\Support\CommunityPermissions;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;

uses(RefreshDatabase::class);

function communityOwnedBy(User $owner): Community
{
    return Community::create([
        'name' => 'Lahore Padel',
        'owner_customer_id' => $owner->uuid,
        'settings' => [],
    ])->refresh();
}

function joinCommunity(Community $community, User $user, string $role, string $status = 'accepted'): CommunityMember
{
    return CommunityMember::create([
        'community_id' => $community->id,
        'customer_id' => $user->uuid,
        'role' => $role,
        'status' => $status,
    ]);
}

function gameHostedBy(User $host, ?Community $community = null): HostedGame
{
    return HostedGame::create([
        'uuid' => (string) Str::uuid(),
        'host_customer_id' => $host->uuid,
        'title' => 'Friday doubles',
        'game_date' => now()->addWeek()->toDateString(),
        'status' => 'open',
        'price_per_person' => 100,
        'community_id' => $community?->id,
        // HostedGame has a global `published` scope; an unpublished row is
        // invisible to every query the service makes.
        'published_at' => now(),
    ])->refresh();
}

/* ---------------------------------------------------------------------------
 | The predicate
 ---------------------------------------------------------------------------*/

it('lets only owner, admin and moderator host', function () {
    expect(CommunityPermissions::canHostEvent('owner'))->toBeTrue()
        ->and(CommunityPermissions::canHostEvent('admin'))->toBeTrue()
        ->and(CommunityPermissions::canHostEvent('moderator'))->toBeTrue()
        ->and(CommunityPermissions::canHostEvent('member'))->toBeFalse()
        ->and(CommunityPermissions::canHostEvent('visitor'))->toBeFalse();
});

/* ---------------------------------------------------------------------------
 | Attaching an existing event to a community
 ---------------------------------------------------------------------------*/

it('refuses a host who is not in the community at all', function () {
    $outsider = User::factory()->create(['status' => User::ACTIVE]);
    $community = communityOwnedBy(User::factory()->create());
    $game = gameHostedBy($outsider);

    $this->actingAs($outsider)
        ->patchJson("/api/customer/games/{$game->uuid}/community", ['community_id' => $community->uuid])
        ->assertStatus(403)
        ->assertJsonFragment(['message' => 'Join this community before hosting an event in it.']);

    expect($game->refresh()->community_id)->toBeNull();
});

it('refuses a plain member, and says who may host', function () {
    $member = User::factory()->create(['status' => User::ACTIVE]);
    $community = communityOwnedBy(User::factory()->create());
    joinCommunity($community, $member, 'member');
    $game = gameHostedBy($member);

    $this->actingAs($member)
        ->patchJson("/api/customer/games/{$game->uuid}/community", ['community_id' => $community->uuid])
        ->assertStatus(403)
        ->assertJsonFragment(['message' => 'Only the community owner, admins and moderators can host events here.']);

    expect($game->refresh()->community_id)->toBeNull();
});

it('refuses a member whose join is still pending', function () {
    $pending = User::factory()->create(['status' => User::ACTIVE]);
    $community = communityOwnedBy(User::factory()->create());
    // A moderator role means nothing until the membership is accepted.
    joinCommunity($community, $pending, 'moderator', 'pending');
    $game = gameHostedBy($pending);

    $this->actingAs($pending)
        ->patchJson("/api/customer/games/{$game->uuid}/community", ['community_id' => $community->uuid])
        ->assertStatus(403);

    expect($game->refresh()->community_id)->toBeNull();
});

it('allows a moderator, an admin and the owner', function (string $role) {
    $owner = User::factory()->create(['status' => User::ACTIVE]);
    $community = communityOwnedBy($owner);

    if ($role === 'owner') {
        $host = $owner;
    } else {
        $host = User::factory()->create(['status' => User::ACTIVE]);
        joinCommunity($community, $host, $role);
    }

    $game = gameHostedBy($host);

    $this->actingAs($host)
        ->patchJson("/api/customer/games/{$game->uuid}/community", ['community_id' => $community->uuid])
        ->assertOk();

    expect($game->refresh()->community_id)->toBe($community->id);
})->with(['moderator', 'admin', 'owner']);

it('still refuses someone who is not the event host, whatever their community role', function () {
    $owner = User::factory()->create(['status' => User::ACTIVE]);
    $community = communityOwnedBy($owner);
    $someoneElse = User::factory()->create(['status' => User::ACTIVE]);
    $game = gameHostedBy($someoneElse);

    // The owner may host here, but this is not their event.
    $this->actingAs($owner)
        ->patchJson("/api/customer/games/{$game->uuid}/community", ['community_id' => $community->uuid])
        ->assertStatus(403)
        ->assertJsonFragment(['message' => 'Only the host can change this event.']);
});

it('reports an unknown community rather than attaching nothing', function () {
    $host = User::factory()->create(['status' => User::ACTIVE]);
    $game = gameHostedBy($host);

    $this->actingAs($host)
        ->patchJson("/api/customer/games/{$game->uuid}/community", ['community_id' => (string) Str::uuid()])
        ->assertStatus(404)
        // Named, because "Event not found" is also a 404 here and the two mean
        // very different things.
        ->assertJsonFragment(['message' => 'Community not found.']);
});

/* ---------------------------------------------------------------------------
 | The audit command
 ---------------------------------------------------------------------------*/

it('reports an event hosted without permission and changes nothing', function () {
    $outsider = User::factory()->create(['name' => 'Outsider', 'status' => User::ACTIVE]);
    $community = communityOwnedBy(User::factory()->create());
    $game = gameHostedBy($outsider, $community);

    $this->artisan('communities:audit-event-hosts')
        ->expectsOutputToContain('1 of 1 community event(s) were hosted without permission.')
        ->assertSuccessful();

    // Read only, always.
    expect($game->refresh()->community_id)->toBe($community->id);
});

it('says nothing is wrong when every host held the role', function () {
    $owner = User::factory()->create(['status' => User::ACTIVE]);
    $community = communityOwnedBy($owner);
    gameHostedBy($owner, $community);

    $this->artisan('communities:audit-event-hosts')
        ->expectsOutputToContain('Every community event was hosted by an owner, admin or moderator.')
        ->assertSuccessful();
});
