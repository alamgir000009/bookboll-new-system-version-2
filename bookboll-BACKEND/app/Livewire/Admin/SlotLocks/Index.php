<?php

namespace App\Livewire\Admin\SlotLocks;

use App\Models\SlotLock;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithPagination;

/**
 * Slot holds, and the means to let one go.
 *
 * A lock is placed when someone opens checkout and is released when they pay,
 * cancel, or it lapses — `default_bookings_slot_lock_time` minutes later. While
 * it is active nobody else can take that slot, so a hold that sticks is a
 * sellable slot taken off the market with no booking to show for it. That is
 * what this page is for: see who is holding what, and release it.
 *
 * Releasing is a DELETE on the row, exactly what the customer's own "unlock"
 * does. The slot returns to sale on the next availability check.
 *
 * Expired rows are shown but marked: they already block nothing, because every
 * availability check goes through SlotLock::active(). They are waiting for
 * `slot-locks:clear`, which runs every five minutes.
 */
#[Layout('layouts.admin')]
#[Title('Slot Locks')]
class Index extends Component
{
    use WithPagination;

    private const SORTABLE = ['locked_until', 'date', 'created_at'];

    /** How many lock ids a single audit entry names before it just says "and more". */
    private const AUDIT_SAMPLE_SIZE = 200;

    #[Url(except: '')]
    public string $q = '';

    #[Url(except: '')]
    public string $venue_id = '';

    #[Url(except: '')]
    public string $listing_id = '';

    #[Url(except: '')]
    public string $provider_id = '';

    #[Url(except: '')]
    public string $customer_id = '';

    /** 'active', 'expired', or '' for both. */
    #[Url(except: '')]
    public string $status = '';

    /** The slot's own date, not when the lock was taken. */
    #[Url(except: '')]
    public string $date = '';

    /** 'guest' or 'customer' — who is holding it. */
    #[Url(except: '')]
    public string $holder = '';

    #[Url(except: 25)]
    public int $records_per_page = 25;

    #[Url(except: 'locked_until')]
    public string $sort = 'locked_until';

    #[Url(except: 'desc')]
    public string $direction = 'desc';

    public array $selected = [];

    public ?int $inspecting = null;

    public ?string $statusMessage = null;

    public ?string $errorMessage = null;

    public function updated(string $property): void
    {
        if (in_array($property, ['q', 'venue_id', 'listing_id', 'provider_id', 'customer_id', 'status', 'date', 'holder', 'records_per_page', 'sort', 'direction'], true)) {
            // Narrowing the venue invalidates a listing chosen under the old one.
            if ($property === 'venue_id') {
                $this->listing_id = '';
            }

            $this->selected = [];
            $this->resetPage();
        }
    }

    public function clearFilters(): void
    {
        $this->reset(['q', 'venue_id', 'listing_id', 'provider_id', 'customer_id', 'status', 'date', 'holder']);
        $this->records_per_page = 25;
        $this->sort = 'locked_until';
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

    public function inspect(int $lockId): void
    {
        $this->inspecting = $lockId;
    }

    public function closeInspector(): void
    {
        $this->inspecting = null;
    }

    /* ---------------------------------------------------------------------
     | Releasing
     --------------------------------------------------------------------- */

    public function release(int $lockId): void
    {
        $lock = SlotLock::query()->with('listing:id,name')->find($lockId);

        if ($lock === null) {
            $this->failed('That hold has already been released.');

            return;
        }

        $label = ($lock->listing?->name ?? 'listing #'.$lock->listing_id)
            .' · '.$lock->date?->toDateString().' '.$lock->start_time;

        $this->applyRelease(SlotLock::query()->whereKey($lockId), "one hold ({$label})");
    }

    public function releaseSelected(): void
    {
        $ids = array_values(array_filter(array_map('intval', $this->selected)));

        if ($ids === []) {
            $this->failed('Tick at least one hold first.');

            return;
        }

        $this->applyRelease(SlotLock::query()->whereIn('id', $ids), count($ids).' selected hold(s)');
    }

    /** Everything on one listing — the "this court is stuck" answer. */
    public function releaseAllForListing(int $listingId): void
    {
        $this->applyRelease(
            SlotLock::query()->where('listing_id', $listingId),
            "every hold on listing #{$listingId}"
        );
    }

    /** Lapsed rows only. Blocks nothing, so this is always safe. */
    public function releaseExpired(): void
    {
        $this->applyRelease(SlotLock::query()->expired(), 'every expired hold');
    }

    public function releaseMatchingFilters(): void
    {
        if (! $this->hasActiveFilters()) {
            $this->failed('Set a filter first, or use "Release every hold" to clear them all.');

            return;
        }

        $this->applyRelease($this->filteredQuery(), 'every hold matching the active filters');
    }

    public function releaseAll(): void
    {
        $this->applyRelease(SlotLock::query(), 'every hold in the system');
    }

    /**
     * The one path every scope above goes through. Mass deletes do not fire
     * model events, so LogsModelActivity records nothing here — the audit entry
     * is written explicitly instead.
     */
    private function applyRelease(Builder $query, string $scope): void
    {
        $total = $query->clone()->count();

        if ($total === 0) {
            $this->failed('No holds matched, so nothing was released.');

            return;
        }

        $activeFreed = $query->clone()->active()->count();
        $sampleIds = $query->clone()->limit(self::AUDIT_SAMPLE_SIZE)->pluck('id')->all();

        $released = $query->clone()->delete();

        activity('slot_lock')
            ->causedBy(Auth::user())
            ->withProperties([
                'released_count' => $released,
                'active_freed' => $activeFreed,
                'scope' => $scope,
                'lock_ids' => $sampleIds,
                'lock_ids_truncated' => $released > count($sampleIds),
                'filters' => $this->activeFilters(),
            ])
            ->log("Released {$released} slot hold(s) — {$scope}");

        $this->forgetReleasedSelections();

        if ($this->inspecting !== null && ! SlotLock::query()->whereKey($this->inspecting)->exists()) {
            $this->inspecting = null;
        }

        $message = "Released {$released} hold(s) — {$scope}.";

        $message .= $activeFreed > 0
            ? " {$activeFreed} slot(s) are back on sale."
            : ' All of them had already lapsed, so nothing changed for customers.';

        $this->statusMessage = $message;
        $this->errorMessage = null;
        $this->resetPage();
    }

    private function forgetReleasedSelections(): void
    {
        if ($this->selected === []) {
            return;
        }

        $surviving = SlotLock::query()
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
        $query = SlotLock::query();

        if ($this->q !== '') {
            $term = '%'.addcslashes($this->q, '%_\\').'%';

            $query->where(function (Builder $builder) use ($term) {
                $builder->where('ip_address', 'like', $term)
                    ->orWhere('session_id', 'like', $term)
                    ->orWhereHas('user', fn (Builder $u) => $u->where('name', 'like', $term)
                        ->orWhere('email', 'like', $term)
                        ->orWhere('phone', 'like', $term))
                    ->orWhereHas('listing', fn (Builder $l) => $l->where('name', 'like', $term)
                        ->orWhereHas('venue', fn (Builder $v) => $v->where('name', 'like', $term)));
            });
        }

        if ($this->listing_id !== '' && ctype_digit($this->listing_id)) {
            $query->where('listing_id', (int) $this->listing_id);
        }

        if ($this->venue_id !== '' && ctype_digit($this->venue_id)) {
            $query->whereHas('listing', fn (Builder $l) => $l->where('venue_id', (int) $this->venue_id));
        }

        if ($this->provider_id !== '' && ctype_digit($this->provider_id)) {
            $query->whereHas('listing.venue', fn (Builder $v) => $v->where('owner_user_id', (int) $this->provider_id));
        }

        if ($this->customer_id !== '' && ctype_digit($this->customer_id)) {
            $query->where('user_id', (int) $this->customer_id);
        }

        if ($this->status === 'active') {
            $query->active();
        } elseif ($this->status === 'expired') {
            $query->expired();
        }

        if ($this->holder === 'guest') {
            $query->whereNull('user_id');
        } elseif ($this->holder === 'customer') {
            $query->whereNotNull('user_id');
        }

        if ($this->date !== '' && strtotime($this->date) !== false) {
            $query->whereDate('date', $this->date);
        }

        return $query;
    }

    private function hasActiveFilters(): bool
    {
        return $this->activeFilters() !== [];
    }

    /** @return array<string, string> */
    private function activeFilters(): array
    {
        return array_filter([
            'search' => $this->q,
            'venue' => $this->venue_id,
            'listing' => $this->listing_id,
            'provider' => $this->provider_id,
            'customer' => $this->customer_id,
            'status' => $this->status,
            'holder' => $this->holder,
            'date' => $this->date,
        ], fn (string $value) => $value !== '');
    }

    /**
     * Dropdown options are derived from the holds that actually exist, not from
     * the full venue/listing/customer tables — the table is transient and small,
     * and an option that matches nothing is just noise.
     */
    private function filterOptions(): array
    {
        $locks = SlotLock::query()
            ->with([
                'listing:id,name,venue_id',
                'listing.venue:id,name,owner_user_id',
                'listing.venue.owner:id,name,email',
                'user:id,name,email',
            ])
            ->get(['id', 'listing_id', 'user_id']);

        $venues = [];
        $listings = [];
        $providers = [];
        $customers = [];

        foreach ($locks as $lock) {
            $listing = $lock->listing;
            $venue = $listing?->venue;
            $owner = $venue?->owner;

            if ($listing) {
                $listings[$listing->id] = $listing->name ?? 'Listing #'.$listing->id;
            }

            if ($venue) {
                $venues[$venue->id] = $venue->name ?? 'Venue #'.$venue->id;
            }

            if ($owner) {
                $providers[$owner->id] = $owner->name ?: $owner->email;
            }

            if ($lock->user) {
                $customers[$lock->user->id] = $lock->user->name ?: $lock->user->email;
            }
        }

        // A listing list filtered to the chosen venue, so the two selects agree.
        if ($this->venue_id !== '' && ctype_digit($this->venue_id)) {
            $listings = $locks
                ->filter(fn ($lock) => (int) $lock->listing?->venue_id === (int) $this->venue_id)
                ->mapWithKeys(fn ($lock) => [$lock->listing->id => $lock->listing->name ?? 'Listing #'.$lock->listing->id])
                ->all();
        }

        asort($venues);
        asort($listings);
        asort($providers);
        asort($customers);

        return compact('venues', 'listings', 'providers', 'customers');
    }

    private function statistics(): array
    {
        $now = now();

        $row = DB::table('slot_locks')
            ->selectRaw(
                'COUNT(*) AS total,
                 SUM(CASE WHEN locked_until > ? THEN 1 ELSE 0 END) AS active,
                 SUM(CASE WHEN locked_until <= ? THEN 1 ELSE 0 END) AS expired,
                 SUM(CASE WHEN user_id IS NULL THEN 1 ELSE 0 END) AS guests,
                 COUNT(DISTINCT listing_id) AS listings',
                [$now, $now]
            )
            ->first();

        return [
            'total' => (int) ($row->total ?? 0),
            'active' => (int) ($row->active ?? 0),
            'expired' => (int) ($row->expired ?? 0),
            'guests' => (int) ($row->guests ?? 0),
            'listings' => (int) ($row->listings ?? 0),
        ];
    }

    public function render()
    {
        $recordsPerPage = min(100, max(1, $this->records_per_page));
        $sort = in_array($this->sort, self::SORTABLE, true) ? $this->sort : 'locked_until';
        $direction = $this->direction === 'asc' ? 'asc' : 'desc';

        $locks = $this->filteredQuery()
            ->with([
                'listing:id,name,venue_id',
                'listing.venue:id,name,owner_user_id',
                'listing.venue.owner:id,name,email',
                'user:id,name,email,phone',
            ])
            ->orderBy($sort, $direction)
            ->orderBy('id', 'desc')
            ->paginate($recordsPerPage);

        $inspected = $this->inspecting === null
            ? null
            : SlotLock::query()
                ->with([
                    'listing:id,name,venue_id',
                    'listing.venue:id,name,owner_user_id',
                    'listing.venue.owner:id,name,email',
                    'user:id,name,email,phone',
                ])
                ->find($this->inspecting);

        return view('livewire.admin.slot-locks.index', $this->filterOptions() + [
            'locks' => $locks,
            'inspected' => $inspected,
            'stats' => $this->statistics(),
            'recordsPerPage' => $recordsPerPage,
            'activeFilters' => $this->activeFilters(),
            'filteredTotal' => $locks->total(),
        ]);
    }
}
