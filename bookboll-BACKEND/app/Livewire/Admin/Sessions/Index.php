<?php

namespace App\Livewire\Admin\Sessions;

use App\Models\PersonalAccessToken;
use App\Models\User;
use App\Support\ApiSessionClients;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithPagination;

/**
 * Every API session this app is currently honouring, and the means to end any
 * of them.
 *
 * config('sanctum.expiration') is null, so a token stays valid until something
 * deletes it — signing out of the app is the only thing that ever did, and a
 * device that is lost, sold, or stolen never does. This page is that something.
 *
 * Revoking is a DELETE on the token row: the next request carrying it fails
 * authentication. There is no partial state and nothing to undo, so each scope
 * below confirms first, and an admin's own sessions are held back by default.
 */
#[Layout('layouts.admin')]
#[Title('Active Sessions')]
class Index extends Component
{
    use WithPagination;

    /**
     * A coarse, SQL-expressible stand-in for UserAgentDetails, which cannot run
     * inside a query. Narrow families only — "Linux" would swallow every
     * Android agent, so it is not offered.
     *
     * @var array<string, array{label: string, patterns: string[]}>
     */
    private const PLATFORMS = [
        'ios' => ['label' => 'iOS / iPadOS', 'patterns' => ['%iPhone%', '%iPad%', '%iPod%', '%CFNetwork%']],
        'android' => ['label' => 'Android', 'patterns' => ['%Android%', '%okhttp%']],
        'windows' => ['label' => 'Windows', 'patterns' => ['%Windows NT%']],
        'macos' => ['label' => 'macOS', 'patterns' => ['%Macintosh%', '%Mac OS X%']],
    ];

    private const SORTABLE = ['last_used_at', 'created_at'];

    /** How many token ids a single audit entry names before it just says "and more". */
    private const AUDIT_SAMPLE_SIZE = 200;

    #[Url(except: '')]
    public string $q = '';

    #[Url(except: '')]
    public string $client = '';

    #[Url(except: '')]
    public string $status = '';

    #[Url(except: '')]
    public string $platform = '';

    #[Url(except: '')]
    public string $user_id = '';

    #[Url(except: 25)]
    public int $records_per_page = 25;

    #[Url(except: 'last_used_at')]
    public string $sort = 'last_used_at';

    #[Url(except: 'desc')]
    public string $direction = 'desc';

    /** Token ids ticked on the current page. */
    public array $selected = [];

    /**
     * Holds back the signed-in admin's own API sessions from every bulk scope.
     * On by default so "revoke everything" cannot sign the person doing it out
     * of the dashboard app they are about to need.
     */
    public bool $protectOwnSessions = true;

    /** Token id whose full detail panel is open. */
    public ?int $inspecting = null;

    public ?string $statusMessage = null;

    public ?string $errorMessage = null;

    public function updated(string $property): void
    {
        if (in_array($property, ['q', 'client', 'status', 'platform', 'user_id', 'records_per_page', 'sort', 'direction'], true)) {
            $this->selected = [];
            $this->resetPage();
        }
    }

    public function clearFilters(): void
    {
        $this->reset(['q', 'client', 'status', 'platform', 'user_id']);
        $this->records_per_page = 25;
        $this->sort = 'last_used_at';
        $this->direction = 'desc';
        $this->selected = [];
        $this->resetPage();
    }

    public function sortBy(string $column): void
    {
        if (! in_array($column, self::SORTABLE, true)) {
            return;
        }

        if ($this->sort === $column) {
            $this->direction = $this->direction === 'asc' ? 'desc' : 'asc';
        } else {
            $this->sort = $column;
            $this->direction = 'desc';
        }

        $this->resetPage();
    }

    /** Narrow the list to one person — the "view this user's other sessions" path. */
    public function filterToUser(int $userId): void
    {
        $this->user_id = (string) $userId;
        $this->selected = [];
        $this->resetPage();
    }

    public function inspect(int $tokenId): void
    {
        $this->inspecting = $tokenId;
    }

    public function closeInspector(): void
    {
        $this->inspecting = null;
    }

    /* ---------------------------------------------------------------------
     | Revocation
     --------------------------------------------------------------------- */

    public function revoke(int $tokenId): void
    {
        $token = PersonalAccessToken::query()->whereKey($tokenId)->first();

        if ($token === null) {
            $this->failed('That session has already ended.');

            return;
        }

        $label = $token->clientLabel().' · '.($token->user?->email ?? 'user #'.$token->tokenable_id);

        $this->applyRevocation(
            PersonalAccessToken::query()->whereKey($tokenId),
            "one session ({$label})",
            respectProtection: false,
        );
    }

    public function revokeSelected(): void
    {
        $ids = array_values(array_filter(array_map('intval', $this->selected)));

        if ($ids === []) {
            $this->failed('Tick at least one session first.');

            return;
        }

        $this->applyRevocation(
            PersonalAccessToken::query()->whereIn('id', $ids),
            count($ids).' selected session(s)',
        );
    }

    /** Every session belonging to one person — the lost-phone answer. */
    public function revokeAllForUser(int $userId): void
    {
        $user = User::query()->whereKey($userId)->first();

        if ($user === null) {
            $this->failed('That user no longer exists.');

            return;
        }

        $this->applyRevocation(
            PersonalAccessToken::query()->where('tokenable_id', $userId),
            "every session for {$user->email}",
        );
    }

    /** Everything the current filters are showing, not just the current page. */
    public function revokeMatchingFilters(): void
    {
        if (! $this->hasActiveFilters()) {
            $this->failed('Set a filter first, or use "Revoke every session" to clear them all.');

            return;
        }

        $this->applyRevocation($this->filteredQuery(), 'every session matching the active filters');
    }

    public function revokeAll(): void
    {
        $this->applyRevocation(PersonalAccessToken::query(), 'every session in the system');
    }

    /**
     * The single path every scope above goes through: hold back the admin's own
     * sessions, delete, record what happened, then report it.
     */
    private function applyRevocation(Builder $query, string $scope, bool $respectProtection = true): void
    {
        $ownId = (int) Auth::id();
        $protecting = $respectProtection && $this->protectOwnSessions && $ownId > 0;

        if ($protecting) {
            $query->where('tokenable_id', '!=', $ownId);
        }

        $affectedUsers = $query->clone()->distinct()->count('tokenable_id');

        if ($affectedUsers === 0) {
            $this->failed($protecting
                ? 'Nothing to revoke — the only matching sessions are your own, which are protected.'
                : 'No sessions matched, so nothing was revoked.');

            return;
        }

        // A sample rather than the full list: "revoke everything" can span every
        // token in the table, and an audit entry carrying thousands of ids helps
        // nobody. The scope and the filters below are what make it reproducible.
        $sampleIds = $query->clone()->limit(self::AUDIT_SAMPLE_SIZE)->pluck('id')->all();

        // Deleted through the query itself, so the statement is one DELETE with
        // the filters attached rather than an id list of unbounded length.
        $deleted = $query->clone()->delete();

        activity('api-session')
            ->causedBy(Auth::user())
            ->withProperties([
                'revoked_count' => $deleted,
                'affected_users' => $affectedUsers,
                'scope' => $scope,
                'token_ids' => $sampleIds,
                'token_ids_truncated' => $deleted > count($sampleIds),
                'own_sessions_protected' => $protecting,
                'filters' => $this->activeFilters(),
            ])
            ->log("Revoked {$deleted} API session(s) — {$scope}");

        $this->forgetRevokedSelections();

        if ($this->inspecting !== null && ! PersonalAccessToken::query()->whereKey($this->inspecting)->exists()) {
            $this->inspecting = null;
        }

        $message = "Revoked {$deleted} session(s) across {$affectedUsers} user(s) — {$scope}.";

        if ($protecting && $ownId > 0) {
            $message .= ' Your own sessions were left untouched.';
        }

        $this->statusMessage = $message;
        $this->errorMessage = null;
        $this->resetPage();
    }

    /**
     * Drop ticks whose sessions are gone. Bounded by the page size, so this is
     * one small query no matter how wide the revocation was.
     */
    private function forgetRevokedSelections(): void
    {
        if ($this->selected === []) {
            return;
        }

        $surviving = PersonalAccessToken::query()
            ->whereIn('id', array_map('intval', $this->selected))
            ->pluck('id')
            ->all();

        $this->selected = array_map('strval', $surviving);
    }

    private function failed(string $message): void
    {
        $this->errorMessage = $message;
        $this->statusMessage = null;
    }

    /* ---------------------------------------------------------------------
     | Query
     --------------------------------------------------------------------- */

    private function filteredQuery(): Builder
    {
        $query = PersonalAccessToken::query()
            // Only users hold tokens here, but the column is polymorphic and a
            // future tokenable must not silently appear in this list.
            ->where('tokenable_type', User::class);

        if ($this->q !== '') {
            $term = '%'.addcslashes($this->q, '%_\\').'%';

            $query->where(function (Builder $builder) use ($term) {
                $builder->where('ip_address', 'like', $term)
                    ->orWhere('last_used_ip', 'like', $term)
                    ->orWhere('user_agent', 'like', $term)
                    ->orWhere('last_used_user_agent', 'like', $term)
                    ->orWhere('name', 'like', $term)
                    ->orWhereHas('user', function (Builder $user) use ($term) {
                        $user->where('name', 'like', $term)
                            ->orWhere('email', 'like', $term)
                            ->orWhere('phone', 'like', $term);
                    });
            });
        }

        if ($this->client !== '' && ApiSessionClients::isKnown($this->client)) {
            $query->where('name', $this->client);
        }

        if ($this->status !== '' && array_key_exists($this->status, PersonalAccessToken::statusOptions())) {
            $query->withStatus($this->status);
        }

        if (isset(self::PLATFORMS[$this->platform])) {
            $patterns = self::PLATFORMS[$this->platform]['patterns'];

            $query->where(function (Builder $builder) use ($patterns) {
                foreach ($patterns as $pattern) {
                    $builder->orWhereRaw('COALESCE(last_used_user_agent, user_agent) LIKE ?', [$pattern]);
                }
            });
        }

        if ($this->user_id !== '' && ctype_digit($this->user_id)) {
            $query->where('tokenable_id', (int) $this->user_id);
        }

        return $query;
    }

    private function hasActiveFilters(): bool
    {
        return $this->activeFilters() !== [];
    }

    /** @return array<string, string> only the filters that are actually narrowing the list */
    private function activeFilters(): array
    {
        return array_filter([
            'search' => $this->q,
            'client' => $this->client,
            'status' => $this->status,
            'platform' => $this->platform,
            'user_id' => $this->user_id,
        ], fn (string $value) => $value !== '');
    }

    /**
     * One pass over the table for the headline counts. Six separate count
     * queries on a table this size would be six index scans per page view.
     */
    private function statistics(): array
    {
        $now = now();
        $activeSince = PersonalAccessToken::activeSince();

        $row = DB::table('personal_access_tokens')
            ->where('tokenable_type', User::class)
            ->selectRaw(
                'COUNT(*) AS total,
                 COUNT(DISTINCT tokenable_id) AS users,
                 SUM(CASE WHEN expires_at IS NOT NULL AND expires_at <= ? THEN 1 ELSE 0 END) AS expired,
                 SUM(CASE WHEN (expires_at IS NULL OR expires_at > ?) AND last_used_at >= ? THEN 1 ELSE 0 END) AS active,
                 SUM(CASE WHEN (expires_at IS NULL OR expires_at > ?) AND last_used_at IS NOT NULL AND last_used_at < ? THEN 1 ELSE 0 END) AS idle,
                 SUM(CASE WHEN (expires_at IS NULL OR expires_at > ?) AND last_used_at IS NULL THEN 1 ELSE 0 END) AS unused',
                [$now, $now, $activeSince, $now, $activeSince, $now]
            )
            ->first();

        return [
            'total' => (int) ($row->total ?? 0),
            'users' => (int) ($row->users ?? 0),
            'active' => (int) ($row->active ?? 0),
            'idle' => (int) ($row->idle ?? 0),
            'unused' => (int) ($row->unused ?? 0),
            'expired' => (int) ($row->expired ?? 0),
        ];
    }

    public function render()
    {
        $recordsPerPage = min(100, max(1, $this->records_per_page));
        $sort = in_array($this->sort, self::SORTABLE, true) ? $this->sort : 'last_used_at';
        $direction = $this->direction === 'asc' ? 'asc' : 'desc';

        $query = $this->filteredQuery()
            ->with(['user:id,name,email,phone,status', 'user.roles'])
            // Nulls sort unhelpfully across drivers, and a token that has never
            // been used should never outrank one in use. Push them to the end.
            ->orderByRaw($sort.' IS NULL')
            ->orderBy($sort, $direction)
            ->orderBy('id', 'desc');

        $sessions = $query->paginate($recordsPerPage);

        $inspected = $this->inspecting === null
            ? null
            : PersonalAccessToken::query()->with(['user:id,name,email,phone,status', 'user.roles'])->find($this->inspecting);

        return view('livewire.admin.sessions.index', [
            'sessions' => $sessions,
            'inspected' => $inspected,
            'stats' => $this->statistics(),
            'clientOptions' => ApiSessionClients::options(),
            'statusOptions' => PersonalAccessToken::statusOptions(),
            'platformOptions' => array_map(fn (array $p) => $p['label'], self::PLATFORMS),
            'recordsPerPage' => $recordsPerPage,
            'activeFilters' => $this->activeFilters(),
            'activeWindowLabel' => PersonalAccessToken::activeWindowLabel(),
            'filteredTotal' => $sessions->total(),
            'currentUserId' => (int) Auth::id(),
        ]);
    }
}
