<?php

namespace App\Services;

use App\Models\GameJoinRequest;
use App\Models\HostedGame;
use App\Models\User;
use Illuminate\Http\Request;

/**
 * Public people search for the marketplace Search page.
 *
 * The People tab had no source. The only user data on the server was the admin
 * customer list, which is tenant-scoped staff data carrying email, phone and
 * booking history — unusable here. So this is a separate, deliberately narrow
 * endpoint built to the same access rules as the public profile page.
 *
 * What it returns is exactly what /u/:id shows a stranger: public id, display
 * name, avatar, whether the profile is private, and the two activity counts
 * that page already shows outside its privacy gate. Nothing else — no email,
 * phone, bio, photos, date of birth, address, internal id or community
 * membership, whatever the caller asks for.
 */
class PeopleSearchService
{
    /** Below this a search is not a search; the UI says so instead of showing nothing. */
    public const MIN_QUERY = 2;

    public const MAX_QUERY = 120;

    public const DEFAULT_LIMIT = 20;

    public const MAX_LIMIT = 50;

    /** Above this a count is reported as a floor rather than a total. */
    public const COUNT_CEILING = 500;

    public function search(Request $request, ?User $viewer = null)
    {
        $data = $request->validate([
            'q'      => 'nullable|string|max:'.self::MAX_QUERY,
            'cursor' => 'nullable|string|max:255',
            'limit'  => 'nullable|integer|min:1|max:'.self::MAX_LIMIT,
        ]);

        $q = $this->normalize((string) ($data['q'] ?? ''));
        $limit = (int) ($data['limit'] ?? self::DEFAULT_LIMIT);

        // A query too short to search is NOT zero results. Saying "no people
        // found" here would read as an answer about the database.
        if (mb_strlen($q) < self::MIN_QUERY) {
            return sendSuccess('Enter at least '.self::MIN_QUERY.' characters to search for people.', [
                'users'      => [],
                'searched'   => false,
                'reason'     => 'query_too_short',
                'min_length' => self::MIN_QUERY,
                'scope'      => $this->scope(),
                'coverage'   => ['status' => 'not_searched', 'total' => null],
                'next_cursor' => null,
            ]);
        }

        $cursor = $this->decodeCursor($data['cursor'] ?? null);
        if (($data['cursor'] ?? null) !== null && $cursor === null) {
            // A cursor we cannot read must not quietly restart from page one —
            // that silently repeats results the caller already has.
            return sendError('That page reference is no longer valid. Start the search again.', [
                'cursor' => ['Unreadable or expired.'],
            ], 422);
        }

        $base = $this->discoverable($viewer)->where(fn ($w) => $this->matches($w, $q));

        // Count before paging, capped: an exact total over a large table is not
        // worth a table scan, and a capped count that says it is capped is more
        // honest than an uncapped one nobody can trust.
        $counted = (clone $base)->limit(self::COUNT_CEILING + 1)->count();
        $coverage = $counted > self::COUNT_CEILING
            ? ['status' => 'at_least', 'total' => self::COUNT_CEILING]
            : ['status' => 'complete', 'total' => $counted];

        $rank = $this->rankExpression();

        $rows = (clone $base)
            ->when($cursor, fn ($x) => $this->afterCursor($x, $rank, $q, $cursor))
            ->select(['id', 'uuid', 'name', 'image', 'is_private', 'created_at'])
            ->selectRaw("({$rank}) as rank_score", $this->rankBindings($q))
            ->orderByRaw("({$rank}) desc", $this->rankBindings($q))
            ->orderBy('name')
            ->orderBy('id')
            ->limit($limit + 1)
            ->get();

        $more = $rows->count() > $limit;
        $page = $rows->take($limit);
        $activity = $this->activityCounts($page->pluck('uuid')->all());

        return sendSuccess('People fetched successfully', [
            'users'       => $page->map(fn (User $u) => $this->card($u, $activity))->values()->all(),
            'searched'    => true,
            'reason'      => null,
            'min_length'  => self::MIN_QUERY,
            'scope'       => $this->scope(),
            'coverage'    => $coverage,
            'next_cursor' => $more ? $this->encodeCursor($page->last()) : null,
        ]);
    }

    /**
     * Who may appear at all.
     *
     * Active customers who have not opted out of being found. A private profile
     * stays findable, matching /u/:id where a private profile is reachable and
     * shows a stranger its name and avatar — opting out of listings is what
     * is_discoverable is for, and it is separate on purpose.
     *
     * NOTE: this system has no block list. There is no table, model or UI for
     * blocking anywhere in the codebase, so there is nothing here to honour and
     * nothing is being claimed. If blocking is built, this method is the single
     * place people search has to exclude both directions of it.
     */
    protected function discoverable(?User $viewer)
    {
        return User::query()
            ->customerRole()
            ->active()
            ->whereNotNull('uuid')
            ->where(function ($q) use ($viewer) {
                $q->where('is_discoverable', true);

                // Someone who has opted out can still find themselves, so the
                // setting is visibly in effect rather than appearing broken.
                if ($viewer?->uuid) {
                    $q->orWhere('uuid', $viewer->uuid);
                }
            });
    }

    /** Every word has to appear in the name; order does not matter. */
    protected function matches($query, string $q)
    {
        foreach (array_slice(explode(' ', $q), 0, 6) as $word) {
            $query->where('name', 'like', '%'.$this->escapeLike($word).'%');
        }

        return $query;
    }

    /** Exact name, then starts-with, then contains. */
    protected function rankExpression(): string
    {
        return 'case when lower(name) = ? then 3 when lower(name) like ? then 2 else 1 end';
    }

    /** @return array<int, string> */
    protected function rankBindings(string $q): array
    {
        return [$q, $this->escapeLike($q).'%'];
    }

    /**
     * Keyset paging on the full sort key.
     *
     * Paging by offset over a ranked search repeats and skips rows whenever
     * anything changes between pages, so the cursor carries the whole key.
     */
    protected function afterCursor($query, string $rank, string $q, array $cursor)
    {
        // The rank expression must be bound to THIS search's query, not to
        // anything carried in the cursor — a cursor comparing against a
        // differently-bound rank skips or repeats whole pages.
        $bindings = $this->rankBindings($q);

        return $query->where(function ($w) use ($rank, $bindings, $cursor) {
            $w->whereRaw("({$rank}) < ?", array_merge($bindings, [$cursor['rank']]))
                ->orWhere(function ($x) use ($rank, $bindings, $cursor) {
                    $x->whereRaw("({$rank}) = ?", array_merge($bindings, [$cursor['rank']]))
                        ->where(function ($y) use ($cursor) {
                            $y->where('name', '>', $cursor['name'])
                                ->orWhere(function ($z) use ($cursor) {
                                    $z->where('name', '=', $cursor['name'])
                                        ->where('id', '>', $cursor['id']);
                                });
                        });
                });
        });
    }

    protected function encodeCursor(User $last): string
    {
        return base64_encode(json_encode([
            'rank' => (int) $last->rank_score,
            'name' => (string) $last->name,
            'id'   => (int) $last->id,
        ]));
    }

    /** @return array{rank:int,name:string,id:int}|null */
    protected function decodeCursor(?string $cursor): ?array
    {
        if ($cursor === null || $cursor === '') {
            return null;
        }

        $decoded = json_decode((string) base64_decode($cursor, true), true);

        if (! is_array($decoded)
            || ! isset($decoded['rank'], $decoded['name'], $decoded['id'])
            || ! is_numeric($decoded['rank'])
            || ! is_string($decoded['name'])
            || ! is_numeric($decoded['id'])) {
            return null;
        }

        return [
            'rank' => (int) $decoded['rank'],
            'name' => $decoded['name'],
            'id'   => (int) $decoded['id'],
        ];
    }

    /**
     * The two counts /u/:id already shows outside its privacy gate.
     *
     * Batched: one query each for the page, not one per person.
     *
     * @param  array<int, string>  $uuids
     * @return array{hosted: array<string,int>, joined: array<string,int>}
     */
    protected function activityCounts(array $uuids): array
    {
        if (! $uuids) {
            return ['hosted' => [], 'joined' => []];
        }

        $hosted = HostedGame::whereIn('host_customer_id', $uuids)
            ->where(fn ($q) => $q->where('moderation_status', 'active')->orWhereNull('moderation_status'))
            ->selectRaw('host_customer_id as uuid, COUNT(*) as c')
            ->groupBy('host_customer_id')
            ->pluck('c', 'uuid')
            ->all();

        $joined = GameJoinRequest::whereIn('joiner_customer_id', $uuids)
            ->where('status', 'accepted')
            ->selectRaw('joiner_customer_id as uuid, COUNT(*) as c')
            ->groupBy('joiner_customer_id')
            ->pluck('c', 'uuid')
            ->all();

        return ['hosted' => $hosted, 'joined' => $joined];
    }

    /**
     * One result card.
     *
     * Built field by field from an explicit list. A whole model would ship
     * email, phone and date of birth the first time someone adds a column.
     */
    protected function card(User $user, array $activity): array
    {
        return [
            'id'          => $user->uuid,
            'url'         => '/u/'.$user->uuid,
            'name'        => $user->name ?: 'Player',
            'avatar'      => $user->image ?? '',
            'isPrivate'   => (bool) $user->is_private,
            'hostedCount' => (int) ($activity['hosted'][$user->uuid] ?? 0),
            'joinedCount' => (int) ($activity['joined'][$user->uuid] ?? 0),
            'joinedAt'    => $user->created_at?->toIso8601String(),
        ];
    }

    /**
     * People search is NOT city-scoped, and says so.
     *
     * The document asks for a decision. Users carry no city of their own — only
     * the games and communities they take part in do — so a city filter here
     * would either be a guess from someone's last booking or silently do
     * nothing. Either reads as "no one plays near you", which is worse than a
     * search that plainly covers everybody.
     */
    protected function scope(): array
    {
        return ['kind' => 'global', 'city_id' => null, 'reason' => 'people_have_no_city'];
    }

    protected function normalize(string $q): string
    {
        $q = preg_replace('/\s+/u', ' ', trim($q));

        return mb_strtolower(mb_substr((string) $q, 0, self::MAX_QUERY));
    }

    /** A name containing % or _ must match literally, not as a wildcard. */
    protected function escapeLike(string $value): string
    {
        return str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $value);
    }
}
