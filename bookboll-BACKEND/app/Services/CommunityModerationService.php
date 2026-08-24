<?php

namespace App\Services;

use App\Models\Community;
use App\Models\CommunityMember;
use App\Support\SupabaseRestClient;
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

        $supabaseOk = true;
        $supabaseError = null;
        try {
            $client = SupabaseRestClient::fromConfig();
            $client->updateById('communities', $id, [
                'moderation_status' => $status,
                'moderation_reason' => $reason,
                'moderated_at'      => optional($community->moderated_at)?->toIso8601String() ?? now()->toIso8601String(),
                'moderated_by'      => $moderatedBy,
            ]);
        } catch (Throwable $e) {
            $supabaseOk = false;
            $supabaseError = $e->getMessage();
            Log::warning('Community moderation Supabase dual-write failed: ' . $e->getMessage(), [
                'community_id' => $id,
                'status'       => $status,
            ]);
        }

        $message = match ($status) {
            'flagged' => 'Community flagged for review',
            'removed' => 'Community removed from the site',
            default   => 'Community restored',
        };
        if (! $supabaseOk) {
            $message .= ' (Laravel saved; Supabase sync failed — storefront may be stale)';
        }

        return sendSuccess($message, [
            'community'       => $this->mapCommunity($community->fresh()),
            'supabase_synced' => $supabaseOk,
            'supabase_error'  => $supabaseError,
        ]);
    }

    protected function mapCommunity(Community $c): array
    {
        return [
            'id'                => (string) $c->id,
            'owner_customer_id' => $c->owner_customer_id ? (string) $c->owner_customer_id : null,
            'name'              => $c->name ?? '',
            'description'       => $c->description ?? '',
            'sport'             => $c->sport ?? '',
            'sports'            => $c->sports ?? [],
            'city_id'           => $c->city_id ? (string) $c->city_id : null,
            'country_id'        => $c->country_id ? (string) $c->country_id : null,
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
