<?php

namespace App\Services;

use App\Models\Community;
use App\Support\CommunityPlace;
use App\Models\User;
use App\Models\CommunityMember;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Admin community moderation — flag / remove / restore.
 */
class CommunityModerationService
{
    public function dashboard(Request $request)
    {
        $communities = Community::query()
            ->orderByDesc('created_at')
            ->get()
            ->map(fn (Community $c) => $this->mapCommunity($c))
            ->values()
            ->all();

        $ids = array_column($communities, 'id');
        $memberStats = [];
        if ($ids) {
            $rows = CommunityMember::query()
                ->whereIn('community_id', $ids)
                ->where('status', 'accepted')
                ->selectRaw('community_id, COUNT(*) as cnt')
                ->groupBy('community_id')
                ->get();
            foreach ($rows as $r) {
                $memberStats[(string) $r->community_id] = [
                    'accepted' => (int) $r->cnt,
                ];
            }
        }

        return sendSuccess('Community moderation dashboard fetched', [
            'communities'  => $communities,
            'member_stats' => $memberStats,
        ]);
    }

    public function flag(Request $request, string $id)
    {
        $data = $request->validate([
            'reason' => 'required|string|max:5000',
        ]);

        return $this->applyModeration($id, 'flagged', $data['reason']);
    }

    public function remove(Request $request, string $id)
    {
        $data = $request->validate([
            'reason' => 'required|string|max:5000',
        ]);

        return $this->applyModeration($id, 'removed', $data['reason']);
    }

    public function restore(Request $request, string $id)
    {
        return $this->applyModeration($id, 'active', null);
    }

    protected function applyModeration(string $id, string $status, ?string $reason)
    {
        $community = Community::query()->find($id);
        if (! $community) {
            return sendError('Community not found', [], 404);
        }

        $user = Auth::user();
        $moderatedBy = $user
            ? (string) ($user->id ?? $user->name ?? 'admin')
            : 'admin';

        $patch = [
            'moderation_status' => $status,
            'moderation_reason' => $reason,
            'moderated_at'      => now(),
            'moderated_by'      => $moderatedBy,
        ];

        $community->fill($patch);
        $community->save();

        // The storefront reads moderation_status from this row
        // (CustomerCommunityService hides removed communities), so no mirror is needed.
        $message = match ($status) {
            'flagged' => 'Community flagged for review',
            'removed' => 'Community removed from the site',
            default   => 'Community restored',
        };
        return sendSuccess($message, [
            'community'       => $this->mapCommunity($community->fresh()),
        ]);
    }

    protected function mapCommunity(Community $c): array
    {
        return [
            'id'                => $c->id,
            'uuid'              => $c->uuid,
            'owner_customer_id' => $c->owner_customer_id ? (string) $c->owner_customer_id : null,
            // Resolved here so the moderation table doesn't need a second lookup
            // just to show who runs the community.
            'owner_name'        => $c->owner_customer_id
                ? (User::where('uuid', $c->owner_customer_id)->value('name') ?: 'Player')
                : 'Player',
            'name'              => $c->name ?? '',
            'description'       => $c->description ?? '',
            'sport'             => $c->sport ?? '',
            'sports'            => $c->sports ?? [],
            'city_id'           => $c->city_id ? (string) $c->city_id : null,
            'country_id'        => $c->country_id ? (string) $c->country_id : null,
            // Names beside the ids: "Doha, Qatar" is what a reader needs.
            ...CommunityPlace::names($c->city_id, $c->country_id),
            'cover_image'       => $c->cover_image ?? '',
            'images'            => $c->images ?? [],
            'is_private'        => (bool) $c->is_private,
            'settings'          => $c->settings ?? [],
            'moderation_status' => $c->moderation_status ?: 'active',
            'moderation_reason' => $c->moderation_reason,
            'moderated_at'      => optional($c->moderated_at)?->toIso8601String(),
            'moderated_by'      => $c->moderated_by,
            'avatar_image'      => $c->avatar_image,
            'created_at'        => optional($c->created_at)?->toIso8601String(),
        ];
    }
}
