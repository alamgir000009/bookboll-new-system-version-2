<?php

namespace App\Services;

use App\Models\Community;
use App\Models\CommunityMember;
use App\Models\CustomerFollow;
use App\Models\CustomerPost;
use App\Models\GameJoinRequest;
use App\Models\HostedGame;
use App\Models\StreetCred;
use App\Models\User;
use Illuminate\Support\Collection;

class PublicProfileService
{
    public function __construct(
        protected CustomerSocialService $socialService,
    ) {}

    public function show(string $uuid, ?User $viewer = null)
    {
        $user = User::customerRole()
            ->active()
            ->byPublicUuid($uuid)
            ->with('assets')
            ->first();

        if (! $user) {
            return sendError('Profile not found.', [], 404);
        }

        $viewerUuid = $viewer?->uuid;
        $isSelf = $viewerUuid && $viewerUuid === $user->uuid;
        $canView = $this->socialService->canViewContent($viewerUuid, $user);

        $followState = 'none';
        if ($viewerUuid && ! $isSelf) {
            $followState = $this->socialService->followState($viewerUuid, $user->uuid);
        }

        $counts = $this->socialService->followCounts($user->uuid);

        $hostedCount = HostedGame::where('host_customer_id', $user->uuid)
            ->where(function ($q) {
                $q->where('moderation_status', 'active')->orWhereNull('moderation_status');
            })
            ->count();

        $joinedCount = GameJoinRequest::where('joiner_customer_id', $user->uuid)
            ->where('status', 'accepted')
            ->count();

        $profile = [
            'id'           => $user->uuid,
            'name'         => $user->name ?: 'Player',
            'joinedAt'     => $user->created_at?->toIso8601String(),
            'avatar'       => $user->image ?? '',
            'bio'          => $canView || $isSelf ? ($user->bio ?? '') : '',
            'photos'       => $canView || $isSelf ? $user->photos : [],
            'isPrivate'    => (bool) $user->is_private,
            'hostedCount'  => $hostedCount,
            'joinedCount'  => $joinedCount,
            'hostedOpen'   => $canView ? $this->hostedOpenGames($user) : [],
        ];

        $posts = $canView ? $this->loadPosts($user->uuid) : [];

        $pendingRequests = ($isSelf && $user->is_private)
            ? $this->socialService->pendingFollowRequests($user->uuid)
            : [];

        $streetCred = $this->streetCredCounts($user->uuid);

        $communities = ($canView || $isSelf)
            ? $this->userCommunities($user->uuid, $isSelf)
            : [];

        return sendSuccess('Profile fetched successfully', [
            'profile'         => $profile,
            'followState'     => $followState,
            'counts'          => $counts,
            'canView'         => $canView,
            'posts'           => $posts,
            'pendingRequests' => $pendingRequests,
            'streetCred'      => $streetCred,
            'communities'     => $communities,
        ]);
    }

    protected function loadPosts(string $authorUuid): array
    {
        return CustomerPost::where('author_uuid', $authorUuid)
            ->orderByDesc('id')
            ->get()
            ->map(fn(CustomerPost $p) => [
                'id'         => $p->uuid,
                'body'       => $p->body ?? '',
                'photos'     => array_values(array_filter($p->photos ?? [])),
                'created_at' => $p->created_at?->toIso8601String(),
            ])
            ->values()
            ->all();
    }

    protected function streetCredCounts(string $uuid): array
    {
        $rows = StreetCred::where('receiver_uuid', $uuid)->get(['badge']);

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

        usort($out, fn($a, $b) => $b['count'] <=> $a['count']);

        return $out;
    }

    protected function userCommunities(string $uuid, bool $isSelf): array
    {
        $memberships = CommunityMember::where('customer_id', $uuid)
            ->whereIn('status', ['accepted', 'pending'])
            ->get();

        if ($memberships->isEmpty()) {
            return [];
        }

        $communityIds = $memberships->pluck('community_id')->unique()->values();
        $communities = Community::whereIn('id', $communityIds)
            ->where(function ($q) {
                $q->where('moderation_status', 'active')->orWhereNull('moderation_status');
            })
            ->get()
            ->keyBy('id');

        $memberCounts = CommunityMember::whereIn('community_id', $communityIds)
            ->where('status', 'accepted')
            ->selectRaw('community_id, COUNT(*) as cnt')
            ->groupBy('community_id')
            ->pluck('cnt', 'community_id');

        $out = [];
        foreach ($memberships as $mem) {
            if ($mem->role !== 'owner' && $mem->status !== 'accepted') {
                continue;
            }

            $c = $communities->get($mem->community_id);
            if (! $c) {
                continue;
            }

            if ($c->is_private && ! $isSelf) {
                continue;
            }

            $out[] = [
                'id'          => $c->uuid,
                'name'        => $c->name,
                'cover_image' => $c->cover_image ?? '',
                'is_private'  => (bool) $c->is_private,
                'memberCount' => (int) ($memberCounts[$c->id] ?? 0),
                'membership'  => [
                    'role'   => $mem->role,
                    'status' => $mem->status,
                ],
            ];
        }

        usort($out, fn($a, $b) => (($a['membership']['role'] ?? '') === 'owner' ? 0 : 1)
            <=> (($b['membership']['role'] ?? '') === 'owner' ? 0 : 1)
            ?: strcmp($a['name'] ?? '', $b['name'] ?? ''));

        return $out;
    }

    protected function hostedOpenGames(User $host): array
    {
        $today = now()->format('Y-m-d');
        $nowHm = now()->format('H:i');

        $games = HostedGame::where('host_customer_id', $host->uuid)
            ->where('status', 'open')
            ->where(function ($q) {
                $q->where('moderation_status', 'active')->orWhereNull('moderation_status');
            })
            ->where(function ($q) use ($today) {
                $q->whereNull('game_date')
                    ->orWhere('game_date', '>=', $today);
            })
            ->orderBy('game_date')
            ->get()
            ->filter(function (HostedGame $g) use ($today, $nowHm) {
                if ($g->community_id && ! $g->community_public) {
                    return false;
                }
                if ($g->game_date && $g->game_date->format('Y-m-d') === $today && $g->end_time) {
                    $end = substr((string) $g->end_time, 0, 5);
                    if ($end !== '00:00' && $end < $nowHm) {
                        return false;
                    }
                }

                return true;
            });

        return $this->decorateGames($games, $host)->values()->all();
    }

    protected function decorateGames(Collection $games, User $host): Collection
    {
        if ($games->isEmpty()) {
            return collect();
        }

        $gameIds = $games->pluck('id');
        $requests = GameJoinRequest::whereIn('game_id', $gameIds)
            ->get(['game_id', 'status', 'joiner_customer_id']);

        $accepted = $requests->where('status', 'accepted');
        $peopleUuids = $accepted->pluck('joiner_customer_id')
            ->merge($games->pluck('host_customer_id'))
            ->filter()
            ->unique()
            ->values();

        $people = User::whereIn('uuid', $peopleUuids)
            ->get(['uuid', 'name', 'image'])
            ->keyBy('uuid');

        $commIds = $games->pluck('community_id')->filter()->unique();
        $communities = $commIds->isEmpty()
            ? collect()
            : Community::query()->whereIn('id', $commIds)->get()->keyBy('id');

        $person = fn(?string $uuid) => $people->get($uuid);

        return $games->map(function (HostedGame $g) use ($accepted, $person, $communities, $host) {
            $hostPerson = $person($g->host_customer_id) ?? $host;
            $going = $accepted->where('game_id', $g->id)
                ->map(fn($r) => $person($r->joiner_customer_id))
                ->filter();

            $comm = $g->community_id ? $communities->get($g->community_id) : null;

            return [
                'id'                   => $g->uuid,
                'uuid'                 => $g->uuid,
                'host_customer_id'     => $g->host_customer_id,
                'title'                => $g->title ?? '',
                'sport'                => $g->sport ?? '',
                'game_type'            => $g->game_type ?? 'game',
                'game_date'            => optional($g->game_date)?->format('Y-m-d'),
                'start_time'           => $g->start_time ?? '',
                'end_time'             => $g->end_time ?? '',
                'price_per_person'     => (float) $g->price_per_person,
                'currency'             => $g->currency ?? '',
                'max_players'          => (int) $g->max_players,
                'status'               => $g->status ?? 'open',
                'prize_pot'            => (float) ($g->prize_pot ?? 0),
                'custom_location_name' => $g->custom_location_name ?? '',
                'community_id'         => $comm?->uuid,
                'community_public'     => (bool) $g->community_public,
                'locationName'         => $g->custom_location_name ?: '',
                'locationAddress'      => $g->custom_location_address ?? '',
                'joinedCount'          => $going->count(),
                'hostName'             => $hostPerson->name ?? ($g->host_name ?: 'Player'),
                'hostAvatar'           => $hostPerson->image ?? '',
                'communityName'        => $comm?->name ?? '',
                'communityAvatar'      => $comm?->avatar_image ?? '',
                'goingAvatars'         => $going->take(5)->map(fn($p) => [
                    'name'   => $p->name ?? 'Player',
                    'avatar' => $p->image ?? '',
                ])->values()->all(),
                'goingNames'           => $going->take(2)->map(fn($p) => explode(' ', $p->name ?? '')[0] ?? '')->filter()->values()->all(),
            ];
        });
    }
}
