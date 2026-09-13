<?php

namespace App\Services;

use App\Models\Listing;
use App\Support\AccessScope;
use App\Support\CityModules;
use App\Support\HourPeriods;
use Nnjeim\World\Models\City;
use App\Models\PricingCell;
use App\Models\PricingGroup;
use App\Models\Schedule;
use App\Models\SportsCategory;
use App\Models\Venue;
use App\Support\PremiumPlan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Admin dashboard (apps/admin Listings.vue) CRUD for listings.
 * Keeps legacy ListingService /admin/listings routes untouched.
 */
class ListingDashboardService
{
    public const DAY_NAMES = [
        0 => 'sunday',
        1 => 'monday',
        2 => 'tuesday',
        3 => 'wednesday',
        4 => 'thursday',
        5 => 'friday',
        6 => 'saturday',
    ];

    public function __construct(protected Listing $listingModel)
    {}

    /**
     * The Listings table — filtered, sorted and paginated ON THE SERVER.
     *
     * The page used to pull every listing and every venue and do all of this in the
     * browser. That is fine at 36 listings and impossible at thousands, so each of
     * the page's filters is expressed as SQL here.
     *
     * Rows carry their venue's name, city and country so the table can render and
     * group without a second lookup list.
     */
    public function index($request)
    {
        $f = $this->listingFilters($request);
        $query = $this->scopedListingQuery($request, $f);

        // Export wants every matching row; the table wants one page.
        if ($request->boolean('export')) {
            $rows = $query->limit(self::EXPORT_MAX)->get();

            return sendSuccess('Listings dashboard fetched', [
                'listings'  => $this->mapListingRows($rows),
                'truncated' => $rows->count() >= self::EXPORT_MAX,
            ]);
        }

        // No pagination asked for → the old whole-list shape, so callers that still
        // want everything (the editor's venue picker, the grouped view's counts)
        // keep working unchanged.
        if (! $request->filled('page') && ! $request->filled('per_page')) {
            return sendSuccess('Listings dashboard fetched', [
                'listings' => $this->mapListingRows($query->get()),
                // The grouped view lands here (it pages over venues, not listings)
                // and still needs the scope-wide numbers for its Sport dropdown
                // and its "export everything" count.
                'scope_total'   => $this->scopeBaseQuery($request)->count(),
                'sport_options' => $this->sportOptions($request),
            ]);
        }

        $perPage = min(max((int) $request->input('per_page', 25), 1), 200);
        $page = $query->paginate($perPage, ['*'], 'page', max(1, (int) $request->input('page', 1)));

        return sendSuccess('Listings dashboard fetched', [
            'listings'   => $this->mapListingRows(collect($page->items())),
            'pagination' => [
                'current_page' => $page->currentPage(),
                'last_page'    => $page->lastPage(),
                'per_page'     => $page->perPage(),
                'total'        => $page->total(),
                'from'         => $page->firstItem(),
                'to'           => $page->lastItem(),
            ],
            // Unfiltered count in scope — the export dialog offers both numbers.
            // Built from the bare scope: `scopedListingQuery` also reads venue_id /
            // venue_ids straight off the request, so reusing it here would have
            // counted only the venues on the current grouped page.
            'scope_total'   => $this->scopeBaseQuery($request)->count(),
            // The sport filter's options. The page used to derive these from the
            // full listing array it held; now that it holds one page, the server
            // has to supply them or the dropdown shrinks as you page.
            'sport_options' => $this->sportOptions($request),
        ]);
    }

    private const EXPORT_MAX = 20000;

    /**
     * Listings this user may see, with NO filters applied — the denominator for
     * "export everything" and for the filter dropdowns' options.
     */
    protected function scopeBaseQuery($request)
    {
        $query = $this->listingModel::query();

        AccessScope::applyVenue($query, 'venue_id', auth()->user());
        $pinned = AccessScope::pinnedListingIds(auth()->user());
        if ($pinned) {
            $query->whereIn('id', $pinned);
        }
        if ($request->filled('owner_user_id')) {
            $ownerId = $request->owner_user_id;
            $query->whereHas('venue', fn ($q) => $q->where('owner_user_id', $ownerId));
        }

        return $query;
    }

    /**
     * Distinct sport values per category across the whole scope, so the Sport
     * dropdown offers the same options it did when the page held every listing.
     *
     * @return array<int, array{category: string, sport: string}>
     */
    protected function sportOptions($request): array
    {
        // DISTINCT over three columns, so this stays bounded by the number of
        // sports (a few dozen) however many listings exist.
        $rows = $this->scopeBaseQuery($request)
            ->select('category', 'sport_category', 'sports_category_id')
            ->distinct()
            ->get();

        $names = SportsCategory::whereIn('id', $rows->pluck('sports_category_id')->filter()->unique())
            ->pluck('name', 'id');

        return $rows
            ->map(fn ($r) => [
                'category' => (string) ($r->category ?? 'sports'),
                // Same fallback the table displays, so every option is selectable.
                'sport'    => trim((string) ($r->sport_category ?: ($names[$r->sports_category_id] ?? ''))),
            ])
            ->filter(fn ($r) => $r['sport'] !== '')
            ->unique(fn ($r) => mb_strtolower($r['category'].'|'.$r['sport']))
            ->values()
            ->all();
    }

    /** @return array<string, mixed> */
    protected function listingFilters($request): array
    {
        $arr = function ($v) {
            if (is_array($v)) return array_values(array_filter($v, fn ($x) => $x !== '' && $x !== null));
            if (is_string($v) && $v !== '') return array_values(array_filter(explode(',', $v)));
            return [];
        };

        return [
            'search'     => trim((string) $request->input('search', '')),
            'country_id' => $arr($request->input('country_id')),
            'city_id'    => $arr($request->input('city_id')),
            'category'   => (string) $request->input('category', ''),
            'sport'      => (string) $request->input('sport', ''),
            'placement'  => (string) $request->input('placement', ''),
            'visibility' => (string) $request->input('visibility', ''),
            'sort'       => (string) $request->input('sort', ''),
            'sort_dir'   => strtolower((string) $request->input('sort_dir', 'asc')) === 'desc' ? 'desc' : 'asc',
        ];
    }

    protected function scopedListingQuery($request, array $f)
    {
        $query = $this->listingModel::query()->with(['venue:id,name,city_id,owner_user_id', 'sportsCategory:id,name']);

        // Row scope: provider ownership, jurisdiction, venue pins and listing pins —
        // the same rule every other admin surface uses.
        AccessScope::applyVenue($query, 'venue_id', auth()->user());
        $pinned = AccessScope::pinnedListingIds(auth()->user());
        if ($pinned) {
            $query->whereIn('id', $pinned);
        }

        if ($request->filled('owner_user_id')) {
            $ownerId = $request->owner_user_id;
            $query->whereHas('venue', fn ($q) => $q->where('owner_user_id', $ownerId));
        }
        if ($request->filled('venue_id') || $request->filled('location_id')) {
            $query->where('venue_id', $request->venue_id ?? $request->location_id);
        }
        // The grouped view asks for exactly the venues on the current card page.
        if ($request->filled('venue_ids')) {
            $ids = array_filter(explode(',', (string) $request->input('venue_ids')));
            $query->whereIn('venue_id', $ids ?: [0]);
        }
        if ($request->filled('status')) {
            $query->where('status', $request->status);
        }

        $this->applyListingFilters($query, $f);
        $this->applyListingSort($query, $f);

        return $query;
    }

    /**
     * Venue ids that still have at least one listing matching the content filters
     * (search, category, sport, placement).
     *
     * The grouped Listings view pages over VENUE cards but hides a card whose
     * listings all filtered out, so the venue query has to know this BEFORE it
     * paginates — otherwise "10 per page" renders as two cards and the pager
     * counts venues the user can never see.
     *
     * @return \Illuminate\Support\Collection<int, int>
     */
    public function matchingVenueIds($request)
    {
        $f = $this->listingFilters($request);

        // Same row scope as scopedListingQuery — a venue must not surface because
        // of a listing this admin cannot see.
        $query = $this->scopeBaseQuery($request);

        $this->applyListingFilters($query, $f);

        return $query->distinct()->pluck('venue_id')->filter()->values();
    }

    protected function applyListingFilters($query, array $f): void
    {
        if ($f['search'] !== '') {
            $term = '%'.$f['search'].'%';
            $query->where(function ($q) use ($term) {
                $q->where('listings.name', 'LIKE', $term)
                    ->orWhere('sport_category', 'LIKE', $term)
                    // Most listings leave the column empty and show the related
                    // category's name instead; the page searches what it displays.
                    ->orWhere(fn ($w) => $w
                        ->tap($this->sportFromRelation())
                        ->whereHas('sportsCategory', fn ($s) => $s->where('name', 'LIKE', $term)))
                    ->orWhere('description', 'LIKE', $term)
                    ->orWhereHas('venue', fn ($v) => $v->where('name', 'LIKE', $term));
            });
        }

        if ($f['category'] !== '' && $f['category'] !== 'all') {
            $query->where('category', $f['category']);
        }
        if ($f['sport'] !== '' && $f['sport'] !== 'all') {
            $sport = mb_strtolower($f['sport']);
            $query->where(function ($q) use ($sport) {
                $q->whereRaw('LOWER(listings.sport_category) = ?', [$sport])
                    ->orWhere(fn ($w) => $w
                        ->tap($this->sportFromRelation())
                        ->whereHas('sportsCategory', fn ($s) => $s->whereRaw('LOWER(name) = ?', [$sport])));
            });
        }

        // Geography lives on the venue, so both filters resolve to a venue-id set.
        if ($f['city_id']) {
            $query->whereHas('venue', fn ($v) => $v->whereIn('city_id', $f['city_id']));
        }
        if ($f['country_id']) {
            $cityIds = City::whereIn('country_id', $f['country_id'])->pluck('id');
            $query->whereHas('venue', fn ($v) => $v->whereIn('city_id', $cityIds ?: [0]));
        }

        // Placement mirrors the page's own three states EXACTLY (lib/approvalState.js):
        //   rowState is 'active' unless eligibility is 'paused' or approval is
        //   'pending' — `status` plays no part, and a NULL column reads as the
        //   permissive default ('eligible' / 'approved'), which a plain `!=` in SQL
        //   would wrongly exclude.
        $onMarket = fn ($q) => $q->where(fn ($w) => $w->whereNull('show_on_marketplace')->orWhere('show_on_marketplace', true));
        $notPaused = fn ($q) => $q->where(fn ($w) => $w->whereNull('eligibility_status')->orWhere('eligibility_status', '!=', Listing::ELIGIBILITY_PAUSED));
        $notPending = fn ($q) => $q->where(fn ($w) => $w->whereNull('approval_status')->orWhere('approval_status', '!=', Listing::APPROVAL_PENDING));

        // Who may book it: staff, customers, both, or nobody. Mirrors the
        // Listing::VISIBILITY_* pairs exactly.
        match ($f['visibility']) {
            Listing::VISIBILITY_BOTH          => $query->where('show_on_admin', true)->where('show_on_customer', true),
            Listing::VISIBILITY_ADMIN_ONLY    => $query->where('show_on_admin', true)->where('show_on_customer', false),
            Listing::VISIBILITY_CUSTOMER_ONLY => $query->where('show_on_admin', false)->where('show_on_customer', true),
            Listing::VISIBILITY_INACTIVE      => $query->where('show_on_admin', false)->where('show_on_customer', false),
            default                           => null,
        };

        match ($f['placement']) {
            'live'       => $query->tap($onMarket)->tap($notPaused)->tap($notPending),
            'review'     => $query->tap($onMarket)->where(fn ($q) => $q
                                ->where('eligibility_status', Listing::ELIGIBILITY_PAUSED)
                                ->orWhere('approval_status', Listing::APPROVAL_PENDING)),
            'off_market' => $query->where('show_on_marketplace', false),
            default      => null,
        };
    }

    /** Rows whose sport is shown from the relation because the column is empty. */
    protected function sportFromRelation(): callable
    {
        return fn ($q) => $q->where(fn ($e) => $e
            ->whereNull('listings.sport_category')
            ->orWhere('listings.sport_category', ''));
    }

    protected function applyListingSort($query, array $f): void
    {
        match ($f['sort']) {
            'name'           => $query->orderBy('listings.name', $f['sort_dir']),
            'base_price'     => $query->orderBy('base_price', $f['sort_dir']),
            // Order by the sport the table actually shows, not the mostly-empty
            // column. A correlated subquery rather than a join: a join would make
            // `id`, `name` and `category` ambiguous for every other clause here.
            'sport_category' => $query->orderByRaw(
                "COALESCE(NULLIF(listings.sport_category, ''), "
                ."(SELECT name FROM sports_categories WHERE sports_categories.id = listings.sports_category_id)) "
                .$f['sort_dir']
            ),
            // The page's default is the provider's own drag-ordering.
            default          => $query->orderBy('sort_order')->orderBy('created_at'),
        };
        $query->orderBy('listings.id');   // stable paging
    }

    /**
     * Map rows and attach the venue name, city and country the table shows and
     * groups by — without these the page still needs the whole venue list.
     *
     * @return array<int, array<string, mixed>>
     */
    protected function mapListingRows($rows): array
    {
        $cityIds = collect($rows)->map(fn ($l) => $l->venue?->city_id)->filter()->unique();
        $cityCountry = $cityIds->isEmpty()
            ? collect()
            : City::whereIn('id', $cityIds)->pluck('country_id', 'id');

        return collect($rows)->map(function (Listing $l) use ($cityCountry) {
            $cityId = $l->venue?->city_id;

            return array_merge($this->mapDashboardListing($l), [
                'location_name' => $l->venue?->name,
                'city_id'       => $cityId ? (string) $cityId : null,
                'country_id'    => $cityId && isset($cityCountry[$cityId]) ? (string) $cityCountry[$cityId] : null,
            ]);
        })->values()->all();
    }

    public function show($id)
    {
        $listing = $this->listingModel::with([
            'schedules',
            'pricingGroups',
            'addOns',
            'resources',
            'venue',
        ])->find($id);

        if (! $listing) {
            return sendError('Listing not found', [], 404);
        }
        if ($err = $this->assertAccess((int) $listing->venue_id)) {
            return $err;
        }

        $cells = PricingCell::where('listing_id', $listing->id)->get()
            ->map(fn (PricingCell $c) => $this->mapCell($c))->values()->all();

        return sendSuccess('Listing fetched', [
            'listing'         => $this->mapDashboardListing($listing),
            'hours'           => $this->mapHoursFromSchedules($listing),
            'pricing_groups'  => $listing->pricingGroups->sortBy('sort_order')->values()
                ->map(fn (PricingGroup $g) => $this->mapGroup($g))->all(),
            'pricing_cells'   => $cells,
            'payment'         => $this->mapPaymentOut($listing),
            'addons'          => $listing->addOns->values()->map(fn ($a, $i) => [
                'id'         => (string) $a->id,
                'addon_id'   => (string) $a->id,
                'sort_order' => $i,
            ])->all(),
            'resources'       => $listing->resources->map(fn ($r) => [
                'id'          => (string) $r->id,
                'resource_id' => (string) $r->id,
                'quantity'    => (int) ($r->pivot->quantity ?? 1),
            ])->values()->all(),
        ]);
    }

    public function store($request)
    {
        $validated = $this->validatePayload($request, true);
        if ($validated instanceof \Illuminate\Http\JsonResponse) {
            return $validated;
        }

        $venueId = (int) ($validated['location_id'] ?? $validated['venue_id'] ?? 0);
        if ($err = $this->assertAccess($venueId)) {
            return $err;
        }
        // The city may have the Listings module switched off (Countries & Cities).
        if ($err = CityModules::guard($venueId, CityModules::LISTINGS, 'Booking listings')) {
            return $err;
        }
        if ($err = $this->guardOffMarketplace($request, $validated, null)) {
            return $err;
        }

        DB::beginTransaction();
        try {
            $attrs = $this->normalizeListingAttrs($validated);
            $attrs['venue_id'] = $venueId;
            $attrs['sort_order'] = (int) ($this->listingModel->where('venue_id', $venueId)->max('sort_order') ?? -1) + 1;
            if (empty($attrs['approval_status'])) {
                $attrs['approval_status'] = Listing::APPROVAL_PENDING;
            }
            if (empty($attrs['eligibility_status'])) {
                $attrs['eligibility_status'] = Listing::ELIGIBILITY_ELIGIBLE;
            }
            if (empty($attrs['status'])) {
                $attrs['status'] = 'active';
            }
            // MySQL listings.payment_options has no default — seed before insert; syncChildren may overwrite.
            if (! array_key_exists('payment_options', $attrs) || $attrs['payment_options'] === null) {
                $paySeed = $this->mapPaymentIn($validated['payment'] ?? []);
                $attrs['online_payment'] = $paySeed['online_payment'];
                $attrs['payment_options'] = $paySeed['payment_options'];
            }

            $listing = $this->listingModel::create($attrs);
            $this->syncChildren($listing, $validated);

            DB::commit();

            return $this->show($listing->id);
        } catch (\Throwable $e) {
            DB::rollBack();
            Log::error('listing dashboardStore failed: ' . $e->getMessage(), ['exception' => $e]);

            return sendError('Failed to create listing');
        }
    }

    public function update($request, $id)
    {
        $listing = $this->listingModel::find($id);
        if (! $listing) {
            return sendError('Listing not found', [], 404);
        }
        if ($err = $this->assertAccess((int) $listing->venue_id)) {
            return $err;
        }

        $validated = $this->validatePayload($request, false);
        if ($validated instanceof \Illuminate\Http\JsonResponse) {
            return $validated;
        }

        $venueId = (int) ($validated['location_id'] ?? $validated['venue_id'] ?? $listing->venue_id);
        if ($venueId !== (int) $listing->venue_id) {
            if ($err = $this->assertAccess($venueId)) {
                return $err;
            }
        }
        if ($err = $this->guardOffMarketplace($request, $validated, $listing)) {
            return $err;
        }

        DB::beginTransaction();
        try {
            $attrs = $this->normalizeListingAttrs($validated);
            $attrs['venue_id'] = $venueId;
            $listing->update($attrs);
            $this->syncChildren($listing->fresh(), $validated);

            DB::commit();

            return $this->show($listing->id);
        } catch (\Throwable $e) {
            DB::rollBack();
            Log::error('listing dashboardUpdate failed: ' . $e->getMessage(), ['exception' => $e]);

            return sendError('Failed to update listing');
        }
    }

    public function destroy($id)
    {
        $listing = $this->listingModel::find($id);
        if (! $listing) {
            return sendError('Listing not found', [], 404);
        }
        if ($err = $this->assertAccess((int) $listing->venue_id)) {
            return $err;
        }

        DB::beginTransaction();
        try {
            PricingCell::where('listing_id', $listing->id)->delete();
            PricingGroup::where('listing_id', $listing->id)->delete();
            Schedule::where('listing_id', $listing->id)->delete();
            $listing->addOns()->detach();
            $listing->resources()->detach();
            $listing->delete();
            DB::commit();

            return sendSuccess('Listing deleted');
        } catch (\Throwable $e) {
            DB::rollBack();
            Log::error('listing dashboardDestroy failed: ' . $e->getMessage(), ['exception' => $e]);

            return sendError('Failed to delete listing. It may have bookings linked to it.');
        }
    }

    public function patch($request, $id)
    {
        $listing = $this->listingModel::find($id);
        if (! $listing) {
            return sendError('Listing not found', [], 404);
        }
        if ($err = $this->assertAccess((int) $listing->venue_id)) {
            return $err;
        }

        $data = $request->validate([
            'status'                 => 'sometimes|in:active,inactive',
            'show_on_admin'          => 'sometimes|boolean',
            'show_on_customer'       => 'sometimes|boolean',
            'eligibility_status'     => 'sometimes|in:eligible,paused,ineligible',
            'admin_note'             => 'nullable|string',
            'previous_snapshot'      => 'nullable|array',
            'show_on_marketplace'    => 'sometimes|boolean',
            'show_on_provider_site'  => 'sometimes|boolean',
            'sort_order'             => 'sometimes|integer|min:0',
            'approval_status'        => 'sometimes|in:pending,approved,rejected',
            'pending_content'        => 'nullable|array',
        ]);

        if ($err = $this->guardOffMarketplace($request, $data, $listing)) {
            return $err;
        }

        $listing->update($data);

        return sendSuccess('Listing patched', [
            'listing' => $this->mapDashboardListing($listing->fresh()),
        ]);
    }

    public function sort($request)
    {
        $request->validate([
            'updates'              => 'required|array|min:1',
            'updates.*.id'         => 'required|integer|exists:listings,id',
            'updates.*.sort_order' => 'required|integer|min:0',
        ]);

        foreach ($request->input('updates') as $update) {
            $listing = $this->listingModel::find($update['id']);
            if (! $listing) {
                continue;
            }
            if ($err = $this->assertAccess((int) $listing->venue_id)) {
                return $err;
            }
        }

        DB::beginTransaction();
        try {
            foreach ($request->input('updates') as $update) {
                $this->listingModel::where('id', $update['id'])->update(['sort_order' => $update['sort_order']]);
            }
            DB::commit();

            return sendSuccess('Sort order updated');
        } catch (\Throwable $e) {
            DB::rollBack();

            return sendError('Failed to update sort order');
        }
    }

    protected function syncChildren(Listing $listing, array $validated): void
    {
        if (array_key_exists('hours', $validated)) {
            $this->syncHours($listing, $validated['hours'] ?? []);
        }
        if (array_key_exists('pricing_groups', $validated) || array_key_exists('pricing_cells', $validated)) {
            $this->syncPricing($listing, $validated['pricing_groups'] ?? [], $validated['pricing_cells'] ?? []);
        }
        if (array_key_exists('payment', $validated)) {
            $pay = $this->mapPaymentIn($validated['payment'] ?? []);
            $listing->update([
                'online_payment'  => $pay['online_payment'],
                'payment_options' => $pay['payment_options'],
            ]);
        }
        if (array_key_exists('addons', $validated)) {
            $ids = collect($validated['addons'] ?? [])
                ->pluck('addon_id')
                ->filter()
                ->map(fn ($id) => (int) $id)
                ->unique()
                ->values()
                ->all();
            $listing->addOns()->sync($ids);
        }
        if (array_key_exists('resources', $validated)) {
            $sync = [];
            foreach ($validated['resources'] ?? [] as $row) {
                $rid = (int) ($row['resource_id'] ?? 0);
                if (! $rid) {
                    continue;
                }
                $sync[$rid] = ['quantity' => max(1, (int) ($row['quantity'] ?? 1))];
            }
            $listing->resources()->sync($sync);
        }
    }

    protected function syncHours(Listing $listing, array $hoursUi): void
    {
        Schedule::where('listing_id', $listing->id)->delete();
        $venueId = (int) $listing->venue_id;

        foreach ($hoursUi as $day) {
            $dow = (int) ($day['day_of_week'] ?? -1);
            $dayName = self::DAY_NAMES[$dow] ?? null;
            if (! $dayName) {
                continue;
            }
            $isOpen = (bool) ($day['is_open'] ?? false);
            $inherit = (bool) ($day['inherit_from_location'] ?? false);

            if (! $isOpen) {
                Schedule::create([
                    'venue_id'              => $venueId,
                    'listing_id'            => $listing->id,
                    'day'                   => $dayName,
                    'is_open'               => false,
                    'start_time'            => null,
                    'end_time'              => null,
                    'inherit_from_location' => $inherit,
                ]);
                continue;
            }

            $periods = $day['periods'] ?? [];
            if (! $periods && isset($day['open_time'])) {
                $periods = [['open_time' => $day['open_time'], 'close_time' => $day['close_time'] ?? '23:00']];
            }
            if (! $periods) {
                $periods = [['open_time' => '09:00', 'close_time' => '23:00']];
            }

            foreach ($periods as $p) {
                Schedule::create([
                    'venue_id'              => $venueId,
                    'listing_id'            => $listing->id,
                    'day'                   => $dayName,
                    'is_open'               => true,
                    'start_time'            => $this->normalizeHm($p['open_time'] ?? '09:00'),
                    'end_time'              => $this->normalizeHm($p['close_time'] ?? '23:00'),
                    'inherit_from_location' => $inherit,
                ]);
            }
        }
    }

    protected function syncPricing(Listing $listing, array $groups, array $cells): void
    {
        PricingCell::where('listing_id', $listing->id)->delete();
        PricingGroup::where('listing_id', $listing->id)->delete();

        $gidMap = [];
        foreach (array_values($groups) as $idx => $g) {
            $created = PricingGroup::create([
                'listing_id'     => $listing->id,
                'name'           => $g['name'] ?? ('Group ' . ($idx + 1)),
                'color'          => $g['color'] ?? null,
                'price_per_hour' => (float) ($g['price'] ?? $g['price_per_hour'] ?? 0),
                'start_time'     => $this->normalizeHm($g['start_time'] ?? '18:00'),
                'end_time'       => $this->normalizeHm($g['end_time'] ?? '22:00'),
                'days'           => $this->normalizeGroupDays($g['days'] ?? null),
                'currency'       => $g['currency'] ?? 'AED',
                'sort_order'     => (int) ($g['sort_order'] ?? $idx),
            ]);
            $oldId = isset($g['id']) ? (string) $g['id'] : null;
            if ($oldId !== null && $oldId !== '') {
                $gidMap[$oldId] = $created->id;
            }
            $gidMap['__idx_' . $idx] = $created->id;
        }

        foreach ($cells as $c) {
            $pgId = $c['pricing_group_id'] ?? null;
            if ($pgId !== null && $pgId !== '') {
                $pgId = $gidMap[(string) $pgId] ?? (is_numeric($pgId) ? (int) $pgId : null);
            } else {
                $pgId = null;
            }
            PricingCell::create([
                'listing_id'       => $listing->id,
                'day_of_week'      => (int) ($c['day_of_week'] ?? 0),
                'slot_time'        => $this->normalizeHm($c['slot_time'] ?? '00:00'),
                'pricing_group_id' => $pgId,
                'direct_price'     => isset($c['direct_price']) && $c['direct_price'] !== null && $c['direct_price'] !== ''
                    ? (float) $c['direct_price']
                    : null,
            ]);
        }
    }

    protected function normalizeGroupDays($days): array
    {
        if (is_array($days)) {
            return $days;
        }
        if (is_string($days) && $days !== '') {
            return array_values(array_filter(array_map('trim', explode(',', $days))));
        }

        return ['1', '2', '3', '4', '5'];
    }

    protected function mapHoursFromSchedules(Listing $listing): array
    {
        $byDay = [];
        foreach ($listing->schedules as $row) {
            $dow = $row->day_of_week;
            if ($dow === null) {
                continue;
            }
            if (! isset($byDay[$dow])) {
                $byDay[$dow] = [
                    'day_of_week'           => $dow,
                    'is_open'               => false,
                    'periods'               => [],
                    'inherit_from_location' => (bool) $row->inherit_from_location,
                ];
            }
            if ($row->is_open) {
                $byDay[$dow]['is_open'] = true;
                $byDay[$dow]['periods'][] = [
                    'open_time'  => $this->fmtHm($row->start_time) ?? '09:00',
                    'close_time' => $this->fmtHm($row->end_time) ?? '23:00',
                ];
            }
            if ($row->inherit_from_location) {
                $byDay[$dow]['inherit_from_location'] = true;
            }
        }

        $out = [];
        for ($i = 0; $i < 7; $i++) {
            $day = $byDay[$i] ?? [
                'day_of_week'           => $i,
                'is_open'               => false,
                'periods'               => [],
                'inherit_from_location' => false,
            ];
            // Stored one row per period; imported listings often carry a row per
            // half hour, which the editor would draw as 48 lines a day. Merge for
            // display — the stored rows and the bookable window are unchanged.
            $day['periods'] = HourPeriods::merge($day['periods']);
            $out[] = $day;
        }

        return $out;
    }

    protected function mapDashboardListing(Listing $l): array
    {
        $photos = $l->photos;
        if (! is_array($photos)) {
            $photos = [];
        }
        if (! $photos && $l->photo_url) {
            $photos = [$l->photo_url];
        }

        return [
            'id'                      => (string) $l->id,
            'location_id'             => $l->venue_id ? (string) $l->venue_id : null,
            'venue_id'                => $l->venue_id ? (string) $l->venue_id : null,
            'name'                    => $l->name,
            'description'             => $l->description ?? '',
            'sport_category'          => $l->sport_category ?: ($l->sportsCategory?->name ?? ''),
            'category'                => $l->category ?? 'sports',
            'base_price'              => (float) ($l->base_price ?? 0),
            'slot_duration'           => (int) ($l->slot_duration ?? 60),
            'buffer_time'             => (int) ($l->buffer_time ?? 0),
            'start_time_mode'         => $l->start_time_mode ?? 'auto',
            'fixed_start_times'       => $l->fixed_start_times ?? [],
            'capacity'                => (int) ($l->capacity ?? 0),
            'status'                  => $l->status ?? 'active',
            // Per-surface visibility. `status` stays in the payload and is still
            // derived from these two, so older readers are unaffected.
            'show_on_admin'           => (bool) ($l->show_on_admin ?? true),
            'show_on_customer'        => (bool) ($l->show_on_customer ?? true),
            'visibility'              => $l->visibility ?? Listing::VISIBILITY_BOTH,
            'loyalty_points'          => (int) ($l->loyalty_points ?? 0),
            'booking_color'           => $l->calendar_color ?? '#10b981',
            'listing_type'            => $l->listing_type ?? 'per_space',
            'max_bookings_per_slot'   => (int) ($l->max_bookings_per_slot ?? 1),
            'commission_rate'         => $l->commission_rate !== null ? (float) $l->commission_rate : null,
            'photo_url'               => $l->photo_url ?? ($photos[0] ?? ''),
            'photos'                  => $photos,
            'approval_status'         => $l->approval_status ?? Listing::APPROVAL_APPROVED,
            'eligibility_status'      => $l->eligibility_status ?? Listing::ELIGIBILITY_ELIGIBLE,
            'admin_note'              => $l->admin_note ?? '',
            'previous_snapshot'       => $l->previous_snapshot,
            'pending_content'         => $l->pending_content,
            'show_on_marketplace'     => $l->show_on_marketplace !== false,
            'show_on_provider_site'   => $l->show_on_provider_site !== false,
            'extension_pricing'       => $l->extension_pricing ?? 'none',
            'extension_value'         => (float) ($l->extension_value ?? 0),
            'extension_max_slots'     => (int) ($l->extension_max_slots ?? 1),
            'booking_hold_minutes'    => (int) ($l->booking_hold_minutes ?? 0),
            'sort_order'              => (int) ($l->sort_order ?? 0),
            'submitted_at'            => $l->submitted_at?->toIso8601String(),
            'submitted_by_name'       => $l->submitted_by_name,
            'created_at'              => $l->created_at?->toIso8601String(),
        ];
    }

    protected function mapGroup(PricingGroup $g): array
    {
        $days = $g->days;
        if (is_array($days)) {
            $daysStr = implode(',', $days);
        } else {
            $daysStr = (string) ($days ?? '1,2,3,4,5');
        }

        return [
            'id'         => (string) $g->id,
            'listing_id' => (string) $g->listing_id,
            'name'       => $g->name,
            'color'      => $g->color,
            'price'      => (float) ($g->price_per_hour ?? 0),
            'start_time' => $g->start_time,
            'end_time'   => $g->end_time,
            'days'       => $daysStr,
            'sort_order' => (int) ($g->sort_order ?? 0),
        ];
    }

    protected function mapCell(PricingCell $c): array
    {
        return [
            'id'               => (string) $c->id,
            'listing_id'       => (string) $c->listing_id,
            'day_of_week'      => (int) $c->day_of_week,
            'slot_time'        => $this->normalizeHm($c->slot_time),
            'pricing_group_id' => $c->pricing_group_id ? (string) $c->pricing_group_id : null,
            'direct_price'     => $c->direct_price !== null ? (float) $c->direct_price : null,
        ];
    }

    protected function mapPaymentOut(Listing $l): array
    {
        $po = is_array($l->payment_options) ? $l->payment_options : [];
        $deposit = is_array($po['deposit'] ?? null) ? $po['deposit'] : [];

        return [
            'cash_on_arrival'         => (bool) ($po['cash_on_arrival'] ?? false),
            'deposit_enabled'         => (bool) ($po['deposit_enabled'] ?? ($deposit['enabled'] ?? ! empty($deposit))),
            'deposit_amount'          => (float) ($po['deposit_amount'] ?? ($deposit['amount'] ?? 0)),
            'deposit_percentage'      => (float) ($po['deposit_percentage'] ?? ($deposit['percentage'] ?? 20)),
            'online_payment_enabled'  => (bool) ($l->online_payment || ($po['online_payment_enabled'] ?? false)),
            'site_deposit_enabled'    => (bool) ($po['site_deposit_enabled'] ?? false),
            'site_deposit_percentage' => (float) ($po['site_deposit_percentage'] ?? 10),
            'site_online_enabled'     => (bool) ($po['site_online_enabled'] ?? false),
            'site_cash_enabled'       => (bool) ($po['site_cash_enabled'] ?? false),
        ];
    }

    protected function mapPaymentIn(array $pay): array
    {
        $pct = (float) ($pay['deposit_percentage'] ?? 20);
        $enabled = (bool) ($pay['deposit_enabled'] ?? false);

        return [
            'online_payment'  => (bool) ($pay['online_payment_enabled'] ?? false),
            'payment_options' => [
                'cash_on_arrival'         => (bool) ($pay['cash_on_arrival'] ?? false),
                'deposit_enabled'         => $enabled,
                'deposit_amount'          => (float) ($pay['deposit_amount'] ?? 0),
                'deposit_percentage'      => $pct,
                'online_payment_enabled'  => (bool) ($pay['online_payment_enabled'] ?? false),
                'deposit'                 => [
                    'enabled'    => $enabled,
                    'amount'     => (float) ($pay['deposit_amount'] ?? 0),
                    'percentage' => $pct,
                ],
                'site_deposit_enabled'    => (bool) ($pay['site_deposit_enabled'] ?? false),
                'site_deposit_percentage' => (float) ($pay['site_deposit_percentage'] ?? 10),
                'site_online_enabled'     => (bool) ($pay['site_online_enabled'] ?? false),
                'site_cash_enabled'       => (bool) ($pay['site_cash_enabled'] ?? false),
            ],
        ];
    }


    /**
     * Hiding a listing from the Bookboll Marketplace and selling it only on your own
     * site is the `own_site_only` Premium feature (Listings.vue gates the toggle).
     * Turning it back ON — going public again — is always free.
     */
    protected function guardOffMarketplace($request, array $data, ?Listing $listing)
    {
        // `boolean` validation accepts false, 0 and "0" without casting, so read the
        // intent rather than the literal.
        if (! array_key_exists('show_on_marketplace', $data)
            || filter_var($data['show_on_marketplace'], FILTER_VALIDATE_BOOLEAN) !== false) {
            return null;
        }
        if ($listing && $listing->show_on_marketplace === false) {
            return null;   // already off the marketplace — not a new use of the feature
        }
        // auth() to match assertAccess() above — same user, one source of truth.
        if (PremiumPlan::entitled($request->user() ?? auth()->user())) {
            return null;
        }

        return PremiumPlan::denied('own_site_only');
    }

    protected function normalizeListingAttrs(array $v): array
    {
        $photos = $v['photos'] ?? [];
        if (! is_array($photos)) {
            $photos = [];
        }
        $photoUrl = $v['photo_url'] ?? ($photos[0] ?? '');

        $attrs = [
            'name'                  => $v['name'] ?? '',
            'description'           => $v['description'] ?? '',
            'sport_category'        => $v['sport_category'] ?? null,
            'category'              => $v['category'] ?? 'sports',
            'base_price'            => $v['base_price'] ?? 0,
            'slot_duration'         => $v['slot_duration'] ?? 60,
            'buffer_time'           => $v['buffer_time'] ?? 0,
            'start_time_mode'       => $v['start_time_mode'] ?? 'auto',
            'fixed_start_times'     => $v['fixed_start_times'] ?? [],
            'capacity'              => $v['capacity'] ?? 0,
            'status'                => $v['status'] ?? 'active',
            'loyalty_points'        => $v['loyalty_points'] ?? 0,
            'calendar_color'        => $v['booking_color'] ?? ($v['calendar_color'] ?? '#10b981'),
            'listing_type'          => $v['listing_type'] ?? 'per_space',
            'max_bookings_per_slot' => $v['max_bookings_per_slot'] ?? 1,
            'commission_rate'       => array_key_exists('commission_rate', $v) ? $v['commission_rate'] : null,
            'photo_url'             => $photoUrl ?: '',
            'photos'                => $photos,
            'approval_status'       => $v['approval_status'] ?? null,
            'eligibility_status'    => $v['eligibility_status'] ?? null,
            'admin_note'            => $v['admin_note'] ?? null,
            'previous_snapshot'     => array_key_exists('previous_snapshot', $v) ? $v['previous_snapshot'] : null,
            'pending_content'       => array_key_exists('pending_content', $v) ? $v['pending_content'] : null,
            'show_on_marketplace'   => array_key_exists('show_on_marketplace', $v) ? (bool) $v['show_on_marketplace'] : true,
            'show_on_provider_site' => array_key_exists('show_on_provider_site', $v) ? (bool) $v['show_on_provider_site'] : true,
            // Per-surface visibility. Dropped below when the client sent neither,
            // so a partial update never silently changes who can book a listing.
            'show_on_admin'         => array_key_exists('show_on_admin', $v) ? (bool) $v['show_on_admin'] : true,
            'show_on_customer'      => array_key_exists('show_on_customer', $v) ? (bool) $v['show_on_customer'] : true,
            'extension_pricing'     => $v['extension_pricing'] ?? 'none',
            'extension_value'       => $v['extension_value'] ?? 0,
            'extension_max_slots'   => $v['extension_max_slots'] ?? 1,
            'booking_hold_minutes'  => $v['booking_hold_minutes'] ?? null,
            'submitted_at'          => array_key_exists('submitted_at', $v) ? $v['submitted_at'] : null,
            'submitted_by_name'     => array_key_exists('submitted_by_name', $v) ? $v['submitted_by_name'] : null,
        ];

        // `status` is derived from the two flags by Listing::booted(). When the
        // client sends visibility, its own `status` is stale by definition, so it
        // is dropped rather than written and immediately overwritten.
        if (array_key_exists('show_on_admin', $v) || array_key_exists('show_on_customer', $v)) {
            unset($attrs['status']);
        }

        // On partial updates, omit nullable fields the client did not send so we
        // do not wipe previous_snapshot / submitted_* / etc.
        foreach ([
            'previous_snapshot', 'pending_content', 'submitted_at', 'submitted_by_name',
            'admin_note', 'approval_status', 'eligibility_status', 'commission_rate',
            'booking_hold_minutes', 'sport_category',
            'show_on_admin', 'show_on_customer',
        ] as $optionalKey) {
            if (! array_key_exists($optionalKey, $v)) {
                unset($attrs[$optionalKey]);
            }
        }

        return array_filter(
            $attrs,
            fn ($val, $key) => $val !== null || in_array($key, [
                'commission_rate', 'pending_content', 'previous_snapshot', 'admin_note',
                'submitted_at', 'submitted_by_name', 'approval_status', 'eligibility_status',
            ], true),
            ARRAY_FILTER_USE_BOTH
        );
    }

    protected function validatePayload($request, bool $creating)
    {
        $nameRule = $creating ? 'required|string|max:255' : 'sometimes|required|string|max:255';
        $locRule = $creating ? 'required' : 'sometimes|required';

        return $request->validate([
            'name'                    => $nameRule,
            'location_id'             => $locRule . '|integer|exists:venues,id',
            'venue_id'                => 'nullable|integer|exists:venues,id',
            'description'             => 'nullable|string',
            'sport_category'          => 'nullable|string|max:255',
            'category'                => 'nullable|string|max:64',
            'base_price'              => 'nullable|numeric|min:0',
            'slot_duration'           => 'nullable|integer|min:5',
            'buffer_time'             => 'nullable|integer|min:0',
            'start_time_mode'         => 'nullable|in:auto,fixed',
            'fixed_start_times'       => 'nullable|array',
            'capacity'                => 'nullable|integer|min:0',
            'status'                  => 'nullable|in:active,inactive',
            // A listing is bookable per SURFACE now, not simply active/inactive.
            // Omitting both leaves the existing flags alone (the model seeds them
            // from `status` for callers that predate them).
            'show_on_admin'           => 'sometimes|boolean',
            'show_on_customer'        => 'sometimes|boolean',
            'loyalty_points'          => 'nullable|integer|min:0',
            'booking_color'           => 'nullable|string|max:32',
            'listing_type'            => 'nullable|string|max:64',
            'max_bookings_per_slot'   => 'nullable|integer|min:1',
            'commission_rate'         => 'nullable|numeric',
            'photo_url'               => 'nullable|string|max:1024',
            'photos'                  => 'nullable|array',
            'approval_status'         => 'nullable|in:pending,approved,rejected',
            'eligibility_status'      => 'nullable|in:eligible,paused,ineligible',
            'admin_note'              => 'nullable|string',
            'previous_snapshot'       => 'nullable|array',
            'pending_content'         => 'nullable|array',
            'show_on_marketplace'     => 'nullable|boolean',
            'show_on_provider_site'   => 'nullable|boolean',
            'extension_pricing'       => 'nullable|string|max:32',
            'extension_value'         => 'nullable|numeric',
            'extension_max_slots'     => 'nullable|integer|min:1',
            'booking_hold_minutes'    => 'nullable|integer|min:0',
            'submitted_at'            => 'nullable|date',
            'submitted_by_name'       => 'nullable|string|max:255',
            'hours'                   => 'nullable|array',
            'pricing_groups'          => 'nullable|array',
            'pricing_cells'           => 'nullable|array',
            'payment'                 => 'nullable|array',
            'addons'                  => 'nullable|array',
            'resources'               => 'nullable|array',
        ]);
    }

    protected function assertAccess(int $venueId)
    {
        $user = auth()->user();
        if (! $user) {
            return sendError('Unauthorized', [], 401);
        }
        if (! Venue::find($venueId)) {
            return sendError('Venue not found', [], 404);
        }

        // One authority for "may I touch this venue": ownership, explicit
        // assignment and platform jurisdiction all live in AccessScope. Asking
        // the user_venue pivot directly missed venues a provider OWNS.
        if (AccessScope::canTouchVenue($user, $venueId)) {
            return null;
        }

        return sendError('You do not have access to this venue.', [], 403);
    }

    protected function normalizeHm(?string $t): string
    {
        if (! $t) {
            return '09:00';
        }
        $t = trim($t);
        if (strlen($t) >= 5) {
            return substr($t, 0, 5);
        }

        return $t;
    }

    protected function fmtHm($value): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }
        if ($value instanceof \DateTimeInterface) {
            return $value->format('H:i');
        }
        $s = (string) $value;

        return strlen($s) >= 5 ? substr($s, 0, 5) : $s;
    }
}
