<?php

namespace App\Console\Commands;

use App\Support\CommunityPermissions;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Community events whose host had no right to post them there.
 *
 * Until CustomerGameService gained a hosting check, neither creating an event
 * with a community_id nor attaching an existing event to a community verified
 * anything about the host's relationship to it. Any signed-in user could put an
 * event on any community's page.
 *
 * Read only, deliberately and permanently. Some of these events have players
 * who paid for a seat, so what to do about each one is a judgement call with
 * money attached — not something a command should decide while nobody is
 * looking. It prints; you choose.
 */
class AuditCommunityEventHosts extends Command
{
    protected $signature = 'communities:audit-event-hosts
                            {--all : Include events whose host does hold the role, for comparison}';

    protected $description = 'List community events hosted by someone without permission to host there (read only)';

    public function handle(): int
    {
        $rows = DB::table('hosted_games as g')
            ->join('communities as c', 'c.id', '=', 'g.community_id')
            ->leftJoin('community_members as m', function ($join) {
                $join->on('m.community_id', '=', 'g.community_id')
                    ->on('m.customer_id', '=', 'g.host_customer_id');
            })
            ->leftJoin('users as u', 'u.uuid', '=', 'g.host_customer_id')
            ->whereNotNull('g.community_id')
            ->orderByDesc('g.id')
            ->get([
                'g.id', 'g.uuid', 'g.title', 'g.game_date', 'g.status', 'g.price_per_person',
                'c.name as community_name', 'c.owner_customer_id',
                'g.host_customer_id', 'u.name as host_name', 'u.email as host_email',
                'm.role as membership_role', 'm.status as membership_status',
            ]);

        if ($rows->isEmpty()) {
            $this->info('No events are posted to a community at all.');

            return self::SUCCESS;
        }

        $assessed = $rows->map(function ($row) {
            // Mirrors CommunityPermissions::role() without hydrating models for
            // every row: owner wins, then an accepted membership, else visitor.
            $role = (string) $row->owner_customer_id === (string) $row->host_customer_id
                ? 'owner'
                : (($row->membership_status === 'accepted' && in_array($row->membership_role, CommunityPermissions::ROLES, true))
                    ? $row->membership_role
                    : 'visitor');

            return (object) ['row' => $row, 'role' => $role, 'allowed' => CommunityPermissions::canHostEvent($role)];
        });

        $offenders = $assessed->reject(fn ($item) => $item->allowed)->values();
        $shown = $this->option('all') ? $assessed : $offenders;

        if ($shown->isEmpty()) {
            $this->info('Every community event was hosted by an owner, admin or moderator. Nothing to review.');

            return self::SUCCESS;
        }

        $seatsSold = $this->seatsSoldFor($shown->pluck('row.id')->all());

        $this->table(
            ['Event', 'Title', 'Community', 'Host', 'Role', 'Date', 'Status', 'Seat price', 'Seats sold'],
            $shown->map(fn ($item) => [
                $item->row->uuid,
                Str::limit((string) $item->row->title, 28) ?: '—',
                Str::limit((string) $item->row->community_name, 22),
                $item->row->host_name ?: ($item->row->host_email ?: $item->row->host_customer_id),
                $item->allowed ? $item->role : '<fg=red>'.$item->role.'</>',
                $item->row->game_date ?: '—',
                $item->row->status,
                number_format((float) $item->row->price_per_person, 2),
                $seatsSold[$item->row->id] ?? 0,
            ])->all()
        );

        $this->newLine();
        $this->warn($offenders->count().' of '.$assessed->count().' community event(s) were hosted without permission.');

        $paid = $offenders->filter(fn ($item) => ($seatsSold[$item->row->id] ?? 0) > 0);

        if ($paid->isNotEmpty()) {
            $this->error($paid->count().' of those have players who joined. Removing or unlinking those affects real people — decide per event.');
        }

        $this->line('Nothing has been changed. New events are blocked by CommunityPermissions::canHostEvent().');

        return self::SUCCESS;
    }

    /** @return array<int, int> game id => accepted joins */
    private function seatsSoldFor(array $gameIds): array
    {
        if ($gameIds === []) {
            return [];
        }

        return DB::table('game_join_requests')
            ->whereIn('game_id', $gameIds)
            ->where('status', 'accepted')
            ->selectRaw('game_id, COUNT(*) AS seats')
            ->groupBy('game_id')
            ->pluck('seats', 'game_id')
            ->map(fn ($v) => (int) $v)
            ->all();
    }
}
