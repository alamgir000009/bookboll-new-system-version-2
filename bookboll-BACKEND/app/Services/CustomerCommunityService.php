<?php

namespace App\Services;

use App\Models\Community;
use App\Models\CommunityMember;
use App\Models\CommunityPost;
use App\Models\CustomerMessage;
use App\Models\StreetCred;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Customer marketplace communities — parity with storefront community.js.
 * Public IDs are UUIDs; FKs use numeric communities.id internally.
 */
class CustomerCommunityService
{
    private const EDITABLE = [
        'name', 'description', 'sport', 'sports', 'cover_image', 'avatar_image',
        'images', 'is_private', 'city_id', 'country_id', 'settings',
    ];

    public function probe()
    {
        return sendSuccess('Communities available', ['enabled' => true]);
    }

    public function list(Request $request, ?User $viewer = null)
    {
        $cityId = $request->query('city_id');
        $sport  = trim((string) $request->query('sport', ''));
        $q      = trim((string) $request->query('q', ''));

        $query = Community::query()
            ->where(function ($w) {
                $w->where('moderation_status', 'active')->orWhereNull('moderation_status');
            })
            ->orderByDesc('created_at');

        if ($cityId) {
            $query->where('city_id', $cityId);
        }

        $rows = $query->get();

        if ($sport !== '') {
            $sportLower = strtolower($sport);
            $rows = $rows->filter(function (Community $c) use ($sportLower) {
                if (strtolower((string) $c->sport) === $sportLower) {
                    return true;
                }
                $sports = is_array($c->sports) ? $c->sports : [];

                return collect($sports)->contains(fn ($s) => strtolower((string) $s) === $sportLower);
            })->values();
        }

        if ($q !== '') {
            $t = strtolower($q);
            $rows = $rows->filter(function (Community $c) use ($t) {
                return str_contains(strtolower((string) $c->name), $t)
                    || str_contains(strtolower((string) $c->description), $t);
            })->values();
        }

        $mapped = $this->decorateCounts($rows)->map(fn (Community $c) => $this->mapCommunity($c))->values()->all();

        return sendSuccess('Communities fetched', ['communities' => $mapped]);
    }

    public function show(string $uuid, ?User $viewer = null)
    {
        $community = $this->findVisibleByUuid($uuid);
        if (! $community) {
            return sendError('Community not found.', [], 404);
        }

        [$withCount] = $this->decorateCounts(collect([$community]))->all();
        $membership = null;
        if ($viewer?->uuid) {
            $membership = $this->mapMembership(
                CommunityMember::where('community_id', $community->id)
                    ->where('customer_id', $viewer->uuid)
                    ->first()
            );
        }

        $data = $this->mapCommunity($withCount);
        $data['membership'] = $membership;

        return sendSuccess('Community fetched', ['community' => $data]);
    }

    public function mine(User $user)
    {
        if (! $user->uuid) {
            return sendSuccess('My communities', ['communities' => []]);
        }

        $mems = CommunityMember::where('customer_id', $user->uuid)->get();
        if ($mems->isEmpty()) {
            return sendSuccess('My communities', ['communities' => []]);
        }

        $byId = $mems->keyBy('community_id');
        $communities = Community::whereIn('id', $mems->pluck('community_id'))
            ->where(function ($w) {
                $w->where('moderation_status', 'active')->orWhereNull('moderation_status');
            })
            ->get();

        $decorated = $this->decorateCounts($communities)->map(function (Community $c) use ($byId) {
            $row = $this->mapCommunity($c);
            $row['membership'] = $this->mapMembership($byId->get($c->id));

            return $row;
        })->sort(function ($a, $b) {
            $ao = ($a['membership']['role'] ?? '') === 'owner' ? 0 : 1;
            $bo = ($b['membership']['role'] ?? '') === 'owner' ? 0 : 1;

            return $ao <=> $bo ?: strcmp($a['name'] ?? '', $b['name'] ?? '');
        })->values()->all();

        return sendSuccess('My communities', ['communities' => $decorated]);
    }

    /**
     * Everyone waiting to be let into a community I run — across all of them at
     * once, so the notification bell doesn't ask community by community.
     */
    public function pendingMembers(User $user)
    {
        if (! $user->uuid) {
            return sendSuccess('Pending members', ['members' => []]);
        }

        $managedIds = CommunityMember::where('customer_id', $user->uuid)
            ->where('status', 'accepted')
            ->whereIn('role', ['owner', 'admin'])
            ->pluck('community_id');

        if ($managedIds->isEmpty()) {
            return sendSuccess('Pending members', ['members' => []]);
        }

        $communities = Community::whereIn('id', $managedIds)
            ->where(function ($w) {
                $w->where('moderation_status', 'active')->orWhereNull('moderation_status');
            })
            ->get()->keyBy('id');

        $pending = CommunityMember::whereIn('community_id', $communities->keys())
            ->where('status', 'pending')
            ->where('customer_id', '!=', $user->uuid)
            ->orderBy('joined_at')
            ->get();

        $members = $pending->map(fn (CommunityMember $m) => [
            'id'             => $m->uuid,
            'community_id'   => $communities->get($m->community_id)?->uuid,
            'community_name' => $communities->get($m->community_id)?->name ?? '',
            'customer_id'    => $m->customer_id,
            'joined_at'      => optional($m->joined_at)?->toIso8601String(),
        ])->values()->all();

        return sendSuccess('Pending members', ['members' => $members]);
    }

    public function create(User $user, array $fields)
    {
        if (! $user->uuid) {
            return sendError('Not signed in');
        }

        $name = trim((string) ($fields['name'] ?? ''));
        if ($name === '') {
            return sendError('Give your community a name.');
        }

        try {
            $community = DB::transaction(function () use ($user, $fields, $name) {
                $community = Community::create([
                    'owner_customer_id' => $user->uuid,
                    'name'              => $name,
                    'description'       => trim((string) ($fields['description'] ?? '')),
                    'sport'             => trim((string) ($fields['sport'] ?? '')),
                    'sports'            => $fields['sports'] ?? [],
                    'city_id'           => $fields['city_id'] ?? null,
                    'country_id'        => $fields['country_id'] ?? null,
                    'cover_image'       => $fields['cover_image'] ?? '',
                    'avatar_image'      => $fields['avatar_image'] ?? null,
                    'images'            => $fields['images'] ?? [],
                    'is_private'        => (bool) ($fields['is_private'] ?? false),
                    'settings'          => $fields['settings'] ?? [],
                    'moderation_status' => 'active',
                ]);

                CommunityMember::create([
                    'community_id' => $community->id,
                    'customer_id'  => $user->uuid,
                    'role'         => 'owner',
                    'status'       => 'accepted',
                    'joined_at'    => now(),
                ]);

                return $community;
            });
        } catch (\Throwable $e) {
            return sendError($e->getMessage() ?: 'Could not create the community.');
        }

        return sendSuccess('Community created', [
            'community' => $this->mapCommunity($community->setAttribute('memberCount', 1)),
        ]);
    }

    public function update(User $user, string $uuid, array $patch)
    {
        $community = $this->findVisibleByUuid($uuid);
        if (! $community) {
            return sendError('Community not found.', [], 404);
        }

        $membership = $this->membershipFor($community->id, $user->uuid);
        if (! $this->canManage($membership)) {
            return sendError('You cannot edit this community.', [], 403);
        }

        $clean = [];
        foreach (self::EDITABLE as $key) {
            if (array_key_exists($key, $patch)) {
                $clean[$key] = $patch[$key];
            }
        }
        if (isset($clean['name'])) {
            $clean['name'] = trim((string) $clean['name']);
            if ($clean['name'] === '') {
                return sendError('Give your community a name.');
            }
        }
        if (isset($clean['description'])) {
            $clean['description'] = trim((string) $clean['description']);
        }
        if (isset($clean['sport'])) {
            $clean['sport'] = trim((string) $clean['sport']);
        }
        if (array_key_exists('is_private', $clean)) {
            $clean['is_private'] = (bool) $clean['is_private'];
        }

        if ($clean) {
            $community->fill($clean)->save();
        }

        return sendSuccess('Community updated', ['community' => $this->mapCommunity($community->fresh())]);
    }

    public function join(User $user, string $uuid)
    {
        if (! $user->uuid) {
            return sendError('Not signed in');
        }

        $community = $this->findVisibleByUuid($uuid);
        if (! $community) {
            return sendError('Community not found.', [], 404);
        }

        $existing = $this->membershipFor($community->id, $user->uuid);
        if ($existing && $existing->status === 'accepted') {
            return sendSuccess('Joined', ['membership' => $this->mapMembership($existing)]);
        }

        $status = $community->is_private ? 'pending' : 'accepted';
        $member = CommunityMember::updateOrCreate(
            [
                'community_id' => $community->id,
                'customer_id'  => $user->uuid,
            ],
            [
                'role'      => 'member',
                'status'    => $status,
                'joined_at' => $existing?->joined_at ?? now(),
            ]
        );

        return sendSuccess('Joined', ['membership' => $this->mapMembership($member->fresh())]);
    }

    public function leave(User $user, string $uuid)
    {
        $community = $this->findVisibleByUuid($uuid);
        if (! $community) {
            return sendError('Community not found.', [], 404);
        }

        $m = $this->membershipFor($community->id, $user->uuid);
        if ($m?->role === 'owner') {
            return sendError("You own this community — you can't leave it.");
        }
        if ($m) {
            $m->delete();
        }

        return sendSuccess('Left community', ['ok' => true]);
    }

    public function members(string $uuid, ?User $viewer = null)
    {
        $community = $this->findVisibleByUuid($uuid);
        if (! $community) {
            return sendError('Community not found.', [], 404);
        }

        if ($community->is_private) {
            $membership = $this->membershipFor($community->id, $viewer?->uuid);
            if (! $this->isMember($membership) && $community->owner_customer_id !== $viewer?->uuid) {
                return sendSuccess('Members', ['members' => []]);
            }
        }

        $mems = CommunityMember::where('community_id', $community->id)
            ->orderBy('joined_at')
            ->get();

        if ($mems->isEmpty()) {
            return sendSuccess('Members', ['members' => []]);
        }

        $people = User::whereIn('uuid', $mems->pluck('customer_id')->unique())
            ->get(['uuid', 'name', 'image'])
            ->keyBy('uuid');

        $rank = ['owner' => 0, 'admin' => 1, 'member' => 2];
        $rows = $mems->map(function (CommunityMember $m) use ($people) {
            $p = $people->get($m->customer_id);
            $mapped = $this->mapMembership($m);
            $mapped['name'] = $p?->name ?: 'Player';
            $mapped['avatar'] = $p?->image ?: '';

            return $mapped;
        })->sort(function ($a, $b) use ($rank) {
            $ap = ($a['status'] ?? '') === 'pending' ? 1 : 0;
            $bp = ($b['status'] ?? '') === 'pending' ? 1 : 0;
            if ($ap !== $bp) {
                return $ap <=> $bp;
            }
            $ar = $rank[$a['role'] ?? 'member'] ?? 2;
            $br = $rank[$b['role'] ?? 'member'] ?? 2;
            if ($ar !== $br) {
                return $ar <=> $br;
            }

            return strcmp($a['joined_at'] ?? '', $b['joined_at'] ?? '');
        })->values()->all();

        return sendSuccess('Members', ['members' => $rows]);
    }

    public function respondToMember(User $user, string $uuid, string $memberUuid, bool $accept)
    {
        $community = $this->findVisibleByUuid($uuid);
        if (! $community) {
            return sendError('Community not found.', [], 404);
        }
        if (! $this->canManage($this->membershipFor($community->id, $user->uuid))) {
            return sendError('Not allowed.', [], 403);
        }

        $member = CommunityMember::where('community_id', $community->id)
            ->where('uuid', $memberUuid)
            ->first();
        if (! $member) {
            return sendError('Member not found.', [], 404);
        }

        if ($accept) {
            $member->status = 'accepted';
            $member->save();
        } else {
            $member->delete();
        }

        return sendSuccess('Member updated', ['ok' => true]);
    }

    /** Resolve community from member uuid (storefront only has member id). */
    public function respondToMemberById(User $user, string $memberUuid, bool $accept)
    {
        $member = CommunityMember::where('uuid', $memberUuid)->first();
        if (! $member) {
            return sendError('Member not found.', [], 404);
        }
        $community = Community::find($member->community_id);
        if (! $community) {
            return sendError('Community not found.', [], 404);
        }

        return $this->respondToMember($user, $community->uuid, $memberUuid, $accept);
    }

    public function removeMember(User $user, string $uuid, string $memberUuid)
    {
        $community = $this->findVisibleByUuid($uuid);
        if (! $community) {
            return sendError('Community not found.', [], 404);
        }
        if (! $this->canManage($this->membershipFor($community->id, $user->uuid))) {
            return sendError('Not allowed.', [], 403);
        }

        $member = CommunityMember::where('community_id', $community->id)
            ->where('uuid', $memberUuid)
            ->first();
        if (! $member) {
            return sendError('Member not found.', [], 404);
        }
        if ($member->role === 'owner') {
            return sendError('Cannot remove the owner.');
        }

        $member->delete();

        return sendSuccess('Member removed', ['ok' => true]);
    }

    public function removeMemberById(User $user, string $memberUuid)
    {
        $member = CommunityMember::where('uuid', $memberUuid)->first();
        if (! $member) {
            return sendError('Member not found.', [], 404);
        }
        $community = Community::find($member->community_id);
        if (! $community) {
            return sendError('Community not found.', [], 404);
        }

        return $this->removeMember($user, $community->uuid, $memberUuid);
    }

    public function setMemberRole(User $user, string $uuid, string $memberUuid, string $role)
    {
        if (! in_array($role, ['admin', 'member'], true)) {
            return sendError('Invalid role');
        }

        $community = $this->findVisibleByUuid($uuid);
        if (! $community) {
            return sendError('Community not found.', [], 404);
        }
        if (! $this->canManage($this->membershipFor($community->id, $user->uuid))) {
            return sendError('Not allowed.', [], 403);
        }

        $member = CommunityMember::where('community_id', $community->id)
            ->where('uuid', $memberUuid)
            ->first();
        if (! $member) {
            return sendError('Member not found.', [], 404);
        }
        if ($member->role === 'owner') {
            return sendError('Cannot change the owner role.');
        }

        $member->role = $role;
        $member->save();

        return sendSuccess('Role updated', ['ok' => true]);
    }

    public function setMemberRoleById(User $user, string $memberUuid, string $role)
    {
        $member = CommunityMember::where('uuid', $memberUuid)->first();
        if (! $member) {
            return sendError('Member not found.', [], 404);
        }
        $community = Community::find($member->community_id);
        if (! $community) {
            return sendError('Community not found.', [], 404);
        }

        return $this->setMemberRole($user, $community->uuid, $memberUuid, $role);
    }

    public function feed(string $uuid, ?User $viewer = null)
    {
        $community = $this->findVisibleByUuid($uuid);
        if (! $community) {
            return sendError('Community not found.', [], 404);
        }

        if ($community->is_private) {
            $membership = $this->membershipFor($community->id, $viewer?->uuid);
            if (! $this->isMember($membership) && $community->owner_customer_id !== $viewer?->uuid) {
                return sendSuccess('Feed', ['posts' => []]);
            }
        }

        $posts = CommunityPost::where('community_id', $community->id)
            ->where(function ($w) {
                $w->where('moderation_status', 'active')->orWhereNull('moderation_status');
            })
            ->orderByDesc('created_at')
            ->limit(100)
            ->get();

        if ($posts->isEmpty()) {
            return sendSuccess('Feed', ['posts' => []]);
        }

        $people = User::whereIn('uuid', $posts->pluck('author_id')->unique())
            ->get(['uuid', 'name', 'image'])
            ->keyBy('uuid');

        $mapped = $posts->map(function (CommunityPost $p) use ($people, $community) {
            $author = $people->get($p->author_id);

            return [
                'id'                => $p->uuid,
                'community_id'      => $community->uuid,
                'author_id'         => $p->author_id,
                'body'              => $p->body ?? '',
                'photos'            => array_values(array_filter($p->photos ?? [])),
                'created_at'        => optional($p->created_at)?->toIso8601String(),
                'authorName'        => $author?->name ?: 'Player',
                'authorAvatar'      => $author?->image ?: '',
                'moderation_status' => $p->moderation_status ?: 'active',
            ];
        })->values()->all();

        return sendSuccess('Feed', ['posts' => $mapped]);
    }

    public function createPost(User $user, string $uuid, array $data)
    {
        if (! $user->uuid) {
            return sendError('Not signed in');
        }

        $community = $this->findVisibleByUuid($uuid);
        if (! $community) {
            return sendError('Community not found.', [], 404);
        }

        $membership = $this->membershipFor($community->id, $user->uuid);
        if (! $this->isMember($membership)) {
            return sendError('Join the community to post.', [], 403);
        }

        $body = trim((string) ($data['body'] ?? ''));
        $photos = array_values(array_filter($data['photos'] ?? []));
        if ($body === '' && ! $photos) {
            return sendError('Write something or add a photo.');
        }

        $post = CommunityPost::create([
            'community_id'      => $community->id,
            'author_id'         => $user->uuid,
            'body'              => $body,
            'photos'            => $photos,
            'moderation_status' => 'active',
        ]);

        return sendSuccess('Post created', [
            'post' => [
                'id'           => $post->uuid,
                'community_id' => $community->uuid,
                'author_id'    => $post->author_id,
                'body'         => $post->body ?? '',
                'photos'       => $photos,
                'created_at'   => optional($post->created_at)?->toIso8601String(),
            ],
        ]);
    }

    public function deletePost(User $user, string $uuid, string $postUuid)
    {
        $community = $this->findVisibleByUuid($uuid);
        if (! $community) {
            return sendError('Community not found.', [], 404);
        }

        $post = CommunityPost::where('community_id', $community->id)
            ->where('uuid', $postUuid)
            ->first();
        if (! $post) {
            return sendError('Post not found.', [], 404);
        }

        $membership = $this->membershipFor($community->id, $user->uuid);
        $isAuthor = $post->author_id === $user->uuid;
        if (! $isAuthor && ! $this->canManage($membership)) {
            return sendError('Not allowed.', [], 403);
        }

        $post->delete();

        return sendSuccess('Post deleted', ['ok' => true]);
    }

    public function deletePostById(User $user, string $postUuid)
    {
        $post = CommunityPost::where('uuid', $postUuid)->first();
        if (! $post) {
            return sendError('Post not found.', [], 404);
        }
        $community = Community::find($post->community_id);
        if (! $community) {
            return sendError('Community not found.', [], 404);
        }

        return $this->deletePost($user, $community->uuid, $postUuid);
    }

    public function loadGroupThread(User $user, string $key, int $limit = 200)
    {
        $communityUuid = $this->communityUuidFromGroupKey($key);
        if (! $communityUuid) {
            return sendError('Invalid conversation key.');
        }

        $community = $this->findVisibleByUuid($communityUuid);
        if (! $community) {
            return sendError('Community not found.', [], 404);
        }

        $membership = $this->membershipFor($community->id, $user->uuid);
        if (! $this->isMember($membership)) {
            return sendError('Members only.', [], 403);
        }

        $rows = CustomerMessage::where('conversation_key', $key)
            ->orderBy('created_at')
            ->limit(max(1, min($limit, 500)))
            ->get();

        if ($rows->isEmpty()) {
            return sendSuccess('Messages', ['messages' => []]);
        }

        $people = User::whereIn('uuid', $rows->pluck('sender_id')->unique())
            ->get(['uuid', 'name', 'image'])
            ->keyBy('uuid');

        $mapped = $rows->map(function (CustomerMessage $m) use ($people) {
            $p = $people->get($m->sender_id);

            return [
                'id'               => $m->uuid,
                'conversation_key' => $m->conversation_key,
                'sender_id'        => $m->sender_id,
                'recipient_id'     => $m->recipient_id,
                'body'             => $m->body,
                'created_at'       => optional($m->created_at)?->toIso8601String(),
                'read_at'          => optional($m->read_at)?->toIso8601String(),
                'senderName'       => $p?->name ?: 'Player',
                'senderAvatar'     => $p?->image ?: '',
            ];
        })->values()->all();

        return sendSuccess('Messages', ['messages' => $mapped]);
    }

    public function sendGroupMessage(User $user, string $key, string $body)
    {
        $clean = trim($body);
        if (! $user->uuid || $clean === '') {
            return sendError('Empty message');
        }

        $communityUuid = $this->communityUuidFromGroupKey($key);
        if (! $communityUuid) {
            return sendError('Invalid conversation key.');
        }

        $community = $this->findVisibleByUuid($communityUuid);
        if (! $community) {
            return sendError('Community not found.', [], 404);
        }

        $membership = $this->membershipFor($community->id, $user->uuid);
        if (! $this->isMember($membership)) {
            return sendError('Members only.', [], 403);
        }

        $settings = is_array($community->settings) ? $community->settings : [];
        if (! empty($settings['chat_locked']) && ! $this->canManage($membership)) {
            return sendError('Chat is locked to announcements only.');
        }

        $message = CustomerMessage::create([
            'conversation_key' => $key,
            'sender_id'        => $user->uuid,
            'recipient_id'     => null,
            'body'             => $clean,
        ]);

        return sendSuccess('Message sent', [
            'message' => [
                'id'               => $message->uuid,
                'conversation_key' => $message->conversation_key,
                'sender_id'        => $message->sender_id,
                'recipient_id'     => null,
                'body'             => $message->body,
                'created_at'       => optional($message->created_at)?->toIso8601String(),
            ],
        ]);
    }

    public function markChatRead(User $user, string $uuid)
    {
        $community = $this->findVisibleByUuid($uuid);
        if (! $community || ! $user->uuid) {
            return sendSuccess('OK', ['ok' => true]);
        }

        CommunityMember::where('community_id', $community->id)
            ->where('customer_id', $user->uuid)
            ->update(['last_read_at' => now()]);

        return sendSuccess('Marked read', ['ok' => true]);
    }

    public function chatUnread(User $user, string $uuid)
    {
        $community = $this->findVisibleByUuid($uuid);
        if (! $community || ! $user->uuid) {
            return sendSuccess('Unread', ['count' => 0]);
        }

        $membership = $this->membershipFor($community->id, $user->uuid);
        if (! $this->isMember($membership)) {
            return sendSuccess('Unread', ['count' => 0]);
        }

        $key = 'community__'.$community->uuid;
        $q = CustomerMessage::where('conversation_key', $key)
            ->where('sender_id', '!=', $user->uuid);
        if ($membership->last_read_at) {
            $q->where('created_at', '>', $membership->last_read_at);
        }

        return sendSuccess('Unread', ['count' => (int) $q->count()]);
    }

    public function membershipCheck(string $communityUuid, string $customerId)
    {
        $community = Community::where('uuid', $communityUuid)->first();
        if (! $community) {
            return sendSuccess('Membership check', [
                'membership' => null,
                'is_member'  => false,
            ]);
        }

        $m = $this->membershipFor($community->id, $customerId);

        return sendSuccess('Membership check', [
            'membership' => $this->mapMembership($m),
            'is_member'  => $this->isMember($m),
        ]);
    }

    public function giveStreetCred(User $user, array $data)
    {
        $gameId = $data['game_id'] ?? null;
        $receiverId = $data['receiver_id'] ?? null;
        $badge = $data['badge'] ?? null;

        if (! $gameId || ! $receiverId || ! isset(StreetCred::BADGE_LABELS[$badge])) {
            return sendError('Invalid props.');
        }
        if (! $user->uuid) {
            return sendError('Not signed in');
        }
        if ($user->uuid === $receiverId) {
            return sendError("You can't give props to yourself 😄");
        }

        StreetCred::updateOrCreate(
            [
                'game_uuid'     => $gameId,
                'giver_uuid'    => $user->uuid,
                'receiver_uuid' => $receiverId,
                'badge'         => $badge,
            ],
            []
        );

        return sendSuccess('Props given', ['ok' => true]);
    }

    public function myPropsForGame(User $user, string $gameId)
    {
        if (! $user->uuid || ! $gameId) {
            return sendSuccess('Props', ['props' => (object) []]);
        }

        $rows = StreetCred::where('game_uuid', $gameId)
            ->where('giver_uuid', $user->uuid)
            ->get(['receiver_uuid', 'badge']);

        $out = [];
        foreach ($rows as $r) {
            $out[$r->receiver_uuid] ??= [];
            $out[$r->receiver_uuid][] = $r->badge;
        }

        return sendSuccess('Props', ['props' => $out]);
    }

    public function streetCredCounts(string $customerId)
    {
        $rows = StreetCred::where('receiver_uuid', $customerId)->get(['badge']);
        $counts = [];
        foreach ($rows as $row) {
            $counts[$row->badge] = ($counts[$row->badge] ?? 0) + 1;
        }

        $out = [];
        foreach ($counts as $badge => $count) {
            $meta = StreetCred::BADGE_LABELS[$badge] ?? null;
            if (! $meta) {
                continue;
            }
            $out[] = [
                'badge' => $badge,
                'count' => $count,
                'label' => $meta['label'],
                'emoji' => $meta['emoji'],
            ];
        }
        usort($out, fn ($a, $b) => $b['count'] <=> $a['count']);

        return sendSuccess('Street cred', ['streetCred' => $out]);
    }

    // ── Helpers ──────────────────────────────────────────────────────────────

    protected function findVisibleByUuid(string $uuid): ?Community
    {
        $c = Community::where('uuid', $uuid)->first();
        if (! $c) {
            return null;
        }
        $status = $c->moderation_status ?: 'active';
        if ($status === 'removed') {
            return null;
        }

        return $c;
    }

    protected function membershipFor(int $communityId, ?string $customerId): ?CommunityMember
    {
        if (! $customerId) {
            return null;
        }

        return CommunityMember::where('community_id', $communityId)
            ->where('customer_id', $customerId)
            ->first();
    }

    protected function canManage(?CommunityMember $membership): bool
    {
        return $membership
            && $membership->status === 'accepted'
            && in_array($membership->role, ['owner', 'admin'], true);
    }

    protected function isMember(?CommunityMember $membership): bool
    {
        return $membership && $membership->status === 'accepted';
    }

    protected function decorateCounts($communities)
    {
        $collection = $communities instanceof \Illuminate\Support\Collection
            ? $communities
            : collect($communities);

        if ($collection->isEmpty()) {
            return $collection;
        }

        $ids = $collection->pluck('id')->all();
        $counts = CommunityMember::whereIn('community_id', $ids)
            ->where('status', 'accepted')
            ->selectRaw('community_id, COUNT(*) as cnt')
            ->groupBy('community_id')
            ->pluck('cnt', 'community_id');

        return $collection->map(function (Community $c) use ($counts) {
            $c->setAttribute('memberCount', (int) ($counts[$c->id] ?? 0));

            return $c;
        });
    }

    protected function mapCommunity(Community $c): array
    {
        return [
            'id'                => $c->uuid,
            'owner_customer_id' => $c->owner_customer_id ? (string) $c->owner_customer_id : null,
            'name'              => $c->name ?? '',
            'description'       => $c->description ?? '',
            'sport'             => $c->sport ?? '',
            'sports'            => $c->sports ?? [],
            'city_id'           => $c->city_id ? (string) $c->city_id : null,
            'country_id'        => $c->country_id ? (string) $c->country_id : null,
            'cover_image'       => $c->cover_image ?? '',
            'avatar_image'      => $c->avatar_image ?? '',
            'images'            => $c->images ?? [],
            'is_private'        => (bool) $c->is_private,
            'settings'          => is_array($c->settings) && array_is_list($c->settings) && count($c->settings) === 0
                ? (object) []
                : ($c->settings ?: (object) []),
            'moderation_status' => $c->moderation_status ?: 'active',
            'memberCount'       => (int) ($c->memberCount ?? 0),
            'created_at'        => optional($c->created_at)?->toIso8601String(),
        ];
    }

    protected function mapMembership(?CommunityMember $m): ?array
    {
        if (! $m) {
            return null;
        }

        return [
            'id'           => $m->uuid,
            'community_id' => null, // filled by callers when needed via community uuid
            'customer_id'  => $m->customer_id,
            'role'         => $m->role,
            'status'       => $m->status,
            'last_read_at' => optional($m->last_read_at)?->toIso8601String(),
            'joined_at'    => optional($m->joined_at)?->toIso8601String(),
        ];
    }

    protected function communityUuidFromGroupKey(string $key): ?string
    {
        if (! str_starts_with($key, 'community__')) {
            return null;
        }
        $uuid = substr($key, strlen('community__'));

        return Str::isUuid($uuid) ? $uuid : null;
    }
}
