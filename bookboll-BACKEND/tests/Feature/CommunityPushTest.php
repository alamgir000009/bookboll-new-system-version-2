<?php

namespace Tests\Feature;

use App\Models\Community;
use App\Models\CommunityMember;
use App\Models\CommunityPost;
use App\Models\User;
use App\Services\CustomerCommunityService;
use App\Services\OneSignalService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Mockery;
use Tests\TestCase;

/**
 * Who gets a push when something happens in a community — and, as
 * importantly, who does not (the person who did it).
 */
class CommunityPushTest extends TestCase
{
    use RefreshDatabase;

    protected $push;

    protected function setUp(): void
    {
        parent::setUp();
        $this->push = Mockery::mock(OneSignalService::class);
        $this->app->instance(OneSignalService::class, $this->push);
    }

    protected function community(User $owner, array $attrs = []): Community
    {
        $c = Community::create(array_merge([
            'owner_customer_id' => $owner->uuid,
            'name'              => 'Doha Padel Crew',
            'sport'             => 'Padel',
            'is_private'        => false,
            'moderation_status' => 'active',
        ], $attrs));
        CommunityMember::create(['community_id' => $c->id, 'customer_id' => $owner->uuid, 'role' => 'owner', 'status' => 'accepted', 'joined_at' => now()]);

        return $c;
    }

    protected function member(Community $c, User $u, string $role = 'member', string $status = 'accepted'): CommunityMember
    {
        return CommunityMember::create(['community_id' => $c->id, 'customer_id' => $u->uuid, 'role' => $role, 'status' => $status, 'joined_at' => now()]);
    }

    protected function expectPush(array $userIds, string $type, ?string $messageContains = null): void
    {
        $this->push->shouldReceive('sendToUsers')->once()->withArgs(function ($ids, $title, $message, $data) use ($userIds, $type, $messageContains) {
            sort($ids);
            sort($userIds);
            return $ids === $userIds
                && ($data['type'] ?? null) === $type
                && ($messageContains === null || str_contains($message, $messageContains));
        })->andReturn(true);
    }

    public function test_a_join_request_reaches_the_owner_and_admins_but_not_the_requester(): void
    {
        [$owner, $admin, $plain, $joiner] = User::factory()->count(4)->create();
        $c = $this->community($owner, ['is_private' => true]);
        $this->member($c, $admin, 'admin');
        $this->member($c, $plain);

        $this->expectPush([$owner->id, $admin->id], 'community_join_request', 'wants to join');

        app(CustomerCommunityService::class)->join($joiner, $c->uuid);
    }

    public function test_joining_an_open_community_tells_the_managers(): void
    {
        [$owner, $joiner] = User::factory()->count(2)->create();
        $c = $this->community($owner);

        $this->expectPush([$owner->id], 'community_member_joined', 'joined');

        app(CustomerCommunityService::class)->join($joiner, $c->uuid);
    }

    public function test_accepting_a_request_tells_the_requester_only(): void
    {
        [$owner, $joiner] = User::factory()->count(2)->create();
        $c = $this->community($owner, ['is_private' => true]);
        $m = $this->member($c, $joiner, 'member', 'pending');

        $this->expectPush([$joiner->id], 'community_request_accepted', 'Welcome');

        app(CustomerCommunityService::class)->respondToMember($owner, $c->uuid, $m->uuid, true);
    }

    public function test_declining_a_request_tells_the_requester(): void
    {
        [$owner, $joiner] = User::factory()->count(2)->create();
        $c = $this->community($owner, ['is_private' => true]);
        $m = $this->member($c, $joiner, 'member', 'pending');

        $this->expectPush([$joiner->id], 'community_request_declined');

        app(CustomerCommunityService::class)->respondToMember($owner, $c->uuid, $m->uuid, false);
    }

    public function test_a_post_reaches_every_member_except_the_author(): void
    {
        [$owner, $a, $b, $pending] = User::factory()->count(4)->create();
        $c = $this->community($owner);
        $this->member($c, $a);
        $this->member($c, $b);
        $this->member($c, $pending, 'member', 'pending');

        $this->expectPush([$owner->id, $b->id], 'community_post', 'Game tonight');

        app(CustomerCommunityService::class)->createPost($a, $c->uuid, ['body' => 'Game tonight at 8?']);
    }

    public function test_a_chat_message_reaches_every_member_except_the_sender(): void
    {
        [$owner, $a, $b] = User::factory()->count(3)->create();
        $c = $this->community($owner);
        $this->member($c, $a);
        $this->member($c, $b);

        $this->expectPush([$a->id, $b->id], 'community_chat', 'hello all');

        app(CustomerCommunityService::class)->sendGroupMessage($owner, 'community__'.$c->uuid, 'hello all');
    }

    public function test_removing_a_member_and_changing_a_role_tell_that_member(): void
    {
        [$owner, $a] = User::factory()->count(2)->create();
        $c = $this->community($owner);
        $m = $this->member($c, $a);

        $this->expectPush([$a->id], 'community_role_changed', 'moderator');
        app(CustomerCommunityService::class)->setMemberRole($owner, $c->uuid, $m->uuid, 'moderator');

        $this->expectPush([$a->id], 'community_member_removed');
        app(CustomerCommunityService::class)->removeMember($owner, $c->uuid, $m->uuid);
    }

    public function test_a_moderator_removing_a_post_tells_its_author_but_deleting_your_own_is_silent(): void
    {
        [$owner, $a] = User::factory()->count(2)->create();
        $c = $this->community($owner);
        $this->member($c, $a);

        // Created directly, so no "new post" push is expected here.
        $post = CommunityPost::create(['community_id' => $c->id, 'author_id' => $a->uuid, 'body' => 'hi', 'photos' => [], 'moderation_status' => 'active']);
        $own = CommunityPost::create(['community_id' => $c->id, 'author_id' => $a->uuid, 'body' => 'mine', 'photos' => [], 'moderation_status' => 'active']);

        // Author deletes their own: nothing.
        app(CustomerCommunityService::class)->deletePost($a, $c->uuid, $own->uuid);

        $this->expectPush([$a->id], 'community_post_removed');
        app(CustomerCommunityService::class)->deletePost($owner, $c->uuid, $post->uuid);
    }
}
