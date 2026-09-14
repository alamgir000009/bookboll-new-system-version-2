<?php

namespace App\Services;

use App\Models\GameJoinRequest;
use App\Models\HostedGame;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Admin event moderation — hosted_games flag / remove / restore.
 */
class EventModerationService
{
    public function dashboard(Request $request)
    {
        $games = HostedGame::query()
            ->orderByDesc('created_at')
            ->get()
            ->map(fn (HostedGame $g) => $this->mapGame($g))
            ->values()
            ->all();

        $gameIds = array_column($games, 'id');
        $joinStats = [];
        if ($gameIds) {
            $rows = GameJoinRequest::query()
                ->whereIn('game_id', $gameIds)
                ->selectRaw("game_id, status, COUNT(*) as cnt")
                ->groupBy('game_id', 'status')
                ->get();
            foreach ($rows as $r) {
                $gid = (string) $r->game_id;
                if (! isset($joinStats[$gid])) {
                    $joinStats[$gid] = ['accepted' => 0, 'pending' => 0];
                }
                if ($r->status === 'accepted') {
                    $joinStats[$gid]['accepted'] = (int) $r->cnt;
                } elseif ($r->status === 'pending') {
                    $joinStats[$gid]['pending'] = (int) $r->cnt;
                }
            }
        }

        return sendSuccess('Event moderation dashboard fetched', [
            'games'      => $games,
            'join_stats' => $joinStats,
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
        $game = HostedGame::query()->find($id);
        if (! $game) {
            return sendError('Event not found', [], 404);
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

        $game->fill($patch);
        $game->save();

        // The storefront reads moderation_status straight off this row
        // (CustomerGameService filters removed events), so there is nothing to
        // mirror — the old Supabase dual-write has been dropped.
        $message = match ($status) {
            'flagged' => 'Event flagged for review',
            'removed' => 'Event removed from the site',
            default   => 'Event restored',
        };

        return sendSuccess($message, [
            'game' => $this->mapGame($game->fresh()),
        ]);
    }

    protected function mapGame(HostedGame $g): array
    {
        return [
            'id'                       => $g->id,
            'uuid'                     => $g->uuid,
            'host_customer_id'         => $g->host_customer_id ? (string) $g->host_customer_id : null,
            'host_name'                => $g->host_name ?? '',
            'host_phone'               => $g->host_phone ?? '',
            'host_whatsapp'            => $g->host_whatsapp ?? '',
            'location_id'              => $g->location_id ? (string) $g->location_id : null,
            'listing_id'               => $g->listing_id ? (string) $g->listing_id : null,
            'booking_id'               => $g->booking_id ? (string) $g->booking_id : null,
            'city_id'                  => $g->city_id ? (string) $g->city_id : null,
            'country_id'               => $g->country_id ? (string) $g->country_id : null,
            'title'                    => $g->title ?? '',
            'sport'                    => $g->sport ?? '',
            'game_type'                => $g->game_type ?? 'game',
            'game_date'                => optional($g->game_date)?->format('Y-m-d'),
            'start_time'               => $g->start_time ?? '',
            'end_time'                 => $g->end_time ?? '',
            'price_per_person'         => (float) $g->price_per_person,
            'currency'                 => $g->currency ?? '',
            'max_players'              => (int) $g->max_players,
            'skill_level'              => $g->skill_level ?? 'all',
            'description'              => $g->description ?? '',
            'status'                   => $g->status ?? 'open',
            'num_teams'                => (int) ($g->num_teams ?? 0),
            'format'                   => $g->format ?? 'single_elim',
            'prize_pot'                => (float) ($g->prize_pot ?? 0),
            'prize_note'               => $g->prize_note ?? '',
            'custom_location_name'     => $g->custom_location_name ?? '',
            'custom_location_address'  => $g->custom_location_address ?? '',
            'hide_location'            => (bool) $g->hide_location,
            'images'                   => $g->images ?? [],
            'moderation_status'        => $g->moderation_status ?: 'active',
            'moderation_reason'        => $g->moderation_reason,
            'moderated_at'             => optional($g->moderated_at)?->toIso8601String(),
            'moderated_by'             => $g->moderated_by,
            'signup_form'              => $g->signup_form ?? [],
            'request_deadline'         => optional($g->request_deadline)?->toIso8601String(),
            'approval_mode'            => $g->approval_mode ?? 'review',
            'charge'                   => $g->charge,
            'community_id'             => $g->community_id ? (string) $g->community_id : null,
            'lock_minutes_before'      => $g->lock_minutes_before,
            'play_format'              => $g->play_format ?? '',
            'courts_count'             => $g->courts_count,
            'rounds'                   => $g->rounds,
            'community_public'         => (bool) $g->community_public,
            'allow_waitlist'           => (bool) $g->allow_waitlist,
            'created_at'               => optional($g->created_at)?->toIso8601String(),
        ];
    }
}
