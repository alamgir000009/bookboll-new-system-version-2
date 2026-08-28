<?php

namespace App\Services;

use App\Models\CustomerFollow;
use App\Models\CustomerPost;
use App\Models\User;
use Illuminate\Support\Str;

class CustomerSocialService
{
    public function canViewContent(?string $viewerUuid, User $target): bool
    {
        if (! $target->is_private) {
            return true;
        }
        if ($viewerUuid && $viewerUuid === $target->uuid) {
            return true;
        }
        if (! $viewerUuid) {
            return false;
        }

        return $this->followState($viewerUuid, $target->uuid) === 'following';
    }

    public function followState(string $viewerUuid, string $targetUuid): string
    {
        if ($viewerUuid === $targetUuid) {
            return 'none';
        }

        $row = CustomerFollow::where('follower_uuid', $viewerUuid)
            ->where('following_uuid', $targetUuid)
            ->first();

        if (! $row) {
            return 'none';
        }

        return $row->status === CustomerFollow::STATUS_ACCEPTED ? 'following' : 'pending';
    }

    public function followCounts(string $targetUuid): array
    {
        return [
            'followers' => CustomerFollow::where('following_uuid', $targetUuid)
                ->where('status', CustomerFollow::STATUS_ACCEPTED)
                ->count(),
            'following' => CustomerFollow::where('follower_uuid', $targetUuid)
                ->where('status', CustomerFollow::STATUS_ACCEPTED)
                ->count(),
        ];
    }

    public function follow(User $viewer, string $targetUuid)
    {
        if (! $viewer->uuid) {
            return sendError('Your profile is not ready yet. Please try again.');
        }
        if ($viewer->uuid === $targetUuid) {
            return sendError('You cannot follow yourself.');
        }

        $target = User::customerRole()->active()->byPublicUuid($targetUuid)->first();
        if (! $target) {
            return sendError('Profile not found.', [], 404);
        }

        $status = $target->is_private
            ? CustomerFollow::STATUS_PENDING
            : CustomerFollow::STATUS_ACCEPTED;

        CustomerFollow::updateOrCreate(
            ['follower_uuid' => $viewer->uuid, 'following_uuid' => $targetUuid],
            ['status' => $status]
        );

        return sendSuccess('Follow updated', [
            'state' => $status === CustomerFollow::STATUS_ACCEPTED ? 'following' : 'pending',
        ]);
    }

    public function unfollow(User $viewer, string $targetUuid)
    {
        if (! $viewer->uuid) {
            return sendError('Your profile is not ready yet. Please try again.');
        }

        CustomerFollow::where('follower_uuid', $viewer->uuid)
            ->where('following_uuid', $targetUuid)
            ->delete();

        return sendSuccess('Unfollowed', ['state' => 'none']);
    }

    public function followList(string $ownerUuid, string $kind, ?User $viewer = null)
    {
        $owner = User::customerRole()->active()->byPublicUuid($ownerUuid)->first();
        if (! $owner) {
            return sendError('Profile not found.', [], 404);
        }

        $viewerUuid = $viewer?->uuid;
        $isSelf = $viewerUuid && $viewerUuid === $ownerUuid;
        if (! $this->canViewContent($viewerUuid, $owner) && ! $isSelf) {
            return sendError('This profile is private.', [], 403);
        }

        if (! in_array($kind, ['followers', 'following'], true)) {
            return sendError('Invalid list kind.');
        }

        $onCol = $kind === 'followers' ? 'following_uuid' : 'follower_uuid';
        $pickCol = $kind === 'followers' ? 'follower_uuid' : 'following_uuid';

        $uuids = CustomerFollow::where($onCol, $ownerUuid)
            ->where('status', CustomerFollow::STATUS_ACCEPTED)
            ->pluck($pickCol);

        $people = User::whereIn('uuid', $uuids)
            ->get(['uuid', 'name', 'image'])
            ->map(fn(User $u) => [
                'id'         => $u->uuid,
                'full_name'  => $u->name ?: 'Player',
                'avatar_url' => $u->image ?? '',
            ])
            ->values()
            ->all();

        return sendSuccess('List fetched', ['people' => $people]);
    }

    public function pendingFollowRequests(string $ownerUuid): array
    {
        $reqs = CustomerFollow::where('following_uuid', $ownerUuid)
            ->where('status', CustomerFollow::STATUS_PENDING)
            ->orderByDesc('id')
            ->get();

        if ($reqs->isEmpty()) {
            return [];
        }

        $followerUuids = $reqs->pluck('follower_uuid');
        $people = User::whereIn('uuid', $followerUuids)
            ->get(['uuid', 'name', 'image'])
            ->keyBy('uuid');

        return $reqs->map(function (CustomerFollow $r) use ($people) {
            $p = $people->get($r->follower_uuid);

            return [
                'id'        => $r->id,
                'createdAt' => $r->created_at?->toIso8601String(),
                'follower'  => [
                    'id'         => $r->follower_uuid,
                    'full_name'  => $p?->name ?: 'Player',
                    'avatar_url' => $p?->image ?? '',
                ],
            ];
        })->values()->all();
    }

    public function respondFollowRequest(User $owner, int $requestId, bool $accept)
    {
        if (! $owner->uuid) {
            return sendError('Your profile is not ready yet. Please try again.');
        }

        $row = CustomerFollow::where('id', $requestId)
            ->where('following_uuid', $owner->uuid)
            ->where('status', CustomerFollow::STATUS_PENDING)
            ->first();

        if (! $row) {
            return sendError('Follow request not found.', [], 404);
        }

        if ($accept) {
            $row->update(['status' => CustomerFollow::STATUS_ACCEPTED]);
        } else {
            $row->delete();
        }

        return sendSuccess($accept ? 'Request accepted' : 'Request declined');
    }

    public function createPost(User $author, ?string $body, array $photos = [])
    {
        if (! $author->uuid) {
            return sendError('Your profile is not ready yet. Please try again.');
        }

        $clean = trim((string) $body);
        $pics = array_values(array_filter($photos));

        if ($clean === '' && $pics === []) {
            return sendError('Write something or add a photo.');
        }

        $post = CustomerPost::create([
            'uuid'        => (string) Str::uuid(),
            'author_uuid' => $author->uuid,
            'body'        => $clean,
            'photos'      => $pics,
        ]);

        return sendSuccess('Post created', [
            'post' => [
                'id'         => $post->uuid,
                'body'       => $post->body ?? '',
                'photos'     => $post->photos ?? [],
                'created_at' => $post->created_at?->toIso8601String(),
            ],
        ]);
    }

    public function deletePost(User $author, string $postUuid)
    {
        if (! $author->uuid) {
            return sendError('Your profile is not ready yet. Please try again.');
        }

        $post = CustomerPost::where('uuid', $postUuid)
            ->where('author_uuid', $author->uuid)
            ->first();

        if (! $post) {
            return sendError('Post not found.', [], 404);
        }

        $post->delete();

        return sendSuccess('Post deleted');
    }
}
