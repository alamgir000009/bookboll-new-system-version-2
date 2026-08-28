<?php

namespace App\Services;

use App\Models\Business;
use App\Models\Listing;
use App\Models\ProviderWallet;
use App\Models\Setting;
use App\Models\Venue;
use Illuminate\Http\Request;
use Nnjeim\World\Models\City;
use Nnjeim\World\Models\Country;

/**
 * Public marketplace catalog for apps/web (bookboll.com).
 *
 * Separate from ListingService::fetchListings / VenueService::all so the old
 * bookboll-FRONTEND payloads stay unchanged.
 */
class MarketplaceCatalogService
{
    public function listings(Request $request)
    {
        $scope = $this->resolveScope($request);
        if ($scope['empty']) {
            return sendSuccess('Listings fetched successfully', ['listings' => []]);
        }

        $venues = $this->venueQuery($scope)->with(['city:id,name'])->get();
        if ($venues->isEmpty()) {
            return sendSuccess('Listings fetched successfully', ['listings' => []]);
        }

        $venueById = $venues->keyBy('id');
        $listings = $this->listingQuery($scope)
            ->whereIn('venue_id', $venues->pluck('id'))
            ->with(['sportsCategory:id,name', 'pricingGroups', 'assets'])
            ->orderBy('name')
            ->get();

        $cards = $listings->map(function (Listing $listing) use ($venueById) {
            $venue = $venueById->get($listing->venue_id);
            if (! $venue) {
                return null;
            }

            return $this->listingCard($listing, $venue);
        })->filter()->values();

        return sendSuccess('Listings fetched successfully', ['listings' => $cards]);
    }

    public function venues(Request $request)
    {
        $scope = $this->resolveScope($request);
        if ($scope['empty']) {
            return sendSuccess('Venues fetched successfully', ['venues' => []]);
        }

        $venues = $this->venueQuery($scope)
            ->with(['city:id,name'])
            ->orderBy('name')
            ->get();

        if ($venues->isEmpty()) {
            return sendSuccess('Venues fetched successfully', ['venues' => []]);
        }

        $listings = $this->listingQuery($scope)
            ->whereIn('venue_id', $venues->pluck('id'))
            ->with(['sportsCategory:id,name', 'pricingGroups', 'assets'])
            ->get()
            ->groupBy('venue_id');

        $cards = $venues->map(function (Venue $venue) use ($listings, $scope) {
            $courts = $listings->get($venue->id) ?? collect();
            if (! $scope['whiteLabel'] && $courts->isEmpty()) {
                return null;
            }

            return $this->venueCard($venue, $courts);
        })->filter()->values();

        return sendSuccess('Venues fetched successfully', ['venues' => $cards]);
    }

    public function venue($id, Request $request)
    {
        $scope = $this->resolveScope($request);

        $venue = Venue::query()
            ->with(['city:id,name', 'country:id,name,iso2'])
            ->where('id', $id)
            ->where('status', Venue::ACTIVE)
            ->first();

        if (! $venue) {
            return sendSuccess('Venue fetched successfully', ['facility' => null]);
        }

        if ($scope['ownerId'] && (int) $venue->owner_user_id !== (int) $scope['ownerId']) {
            return sendSuccess('Venue fetched successfully', ['facility' => null]);
        }

        if (! $scope['whiteLabel'] && $this->ownerSuspended($venue->owner_user_id)) {
            return sendSuccess('Venue fetched successfully', ['facility' => null]);
        }

        $listings = $this->listingQuery($scope)
            ->where('venue_id', $venue->id)
            ->with(['sportsCategory:id,name', 'pricingGroups', 'assets'])
            ->orderBy('name')
            ->get();

        $photos = $this->photos($venue);
        $decorated = $listings->map(function (Listing $listing) use ($venue, $photos) {
            $courtPhotos = $this->photos($listing);
            $start = $this->startingPrice($listing);

            return array_merge($this->listingPayload($listing, $venue), [
                'photo'       => $courtPhotos[0] ?? ($photos[0] ?? ''),
                'startPrice'  => $start,
                'sport_category' => $this->sportName($listing),
            ]);
        })->values();

        return sendSuccess('Venue fetched successfully', [
            'facility' => [
                'location' => $this->locationPayload($venue),
                'photo'    => $photos[0] ?? '',
                'photos'   => $photos,
                'mapsUrl'  => $this->mapsUrl($venue),
                'listings' => $decorated,
            ],
        ]);
    }

    public function listing($id, Request $request)
    {
        $scope = $this->resolveScope($request);

        $listing = Listing::query()
            ->with([
                'venue.city:id,name',
                'venue.country:id,name,iso2',
                'sportsCategory:id,name',
                'pricingGroups',
                'assets',
                'addOns',
            ])
            ->where('id', $id)
            ->where('status', 'active')
            ->first();

        if (! $listing || ! $listing->venue || $listing->venue->status !== Venue::ACTIVE) {
            return sendSuccess('Listing fetched successfully', ['detail' => null]);
        }

        $venue = $listing->venue;

        if ($scope['ownerId'] && (int) $venue->owner_user_id !== (int) $scope['ownerId']) {
            return sendSuccess('Listing fetched successfully', ['detail' => null]);
        }

        if (! $this->listingVisible($listing, $scope['whiteLabel'])) {
            return sendSuccess('Listing fetched successfully', ['detail' => null]);
        }

        if (! $scope['whiteLabel'] && $this->ownerSuspended($venue->owner_user_id)) {
            return sendSuccess('Listing fetched successfully', ['detail' => null]);
        }

        $tiers = $listing->pricingGroups
            ->map(fn ($g) => [
                'name'  => $g->name,
                'price' => (float) ($g->price_per_hour ?? 0),
            ])
            ->filter(fn ($t) => $t['price'] > 0)
            ->sortBy('price')
            ->values();

        $addons = $listing->addOns
            ->filter(fn ($a) => ($a->status ?: 'active') !== 'inactive')
            ->map(fn ($a) => [
                'id'              => $a->id,
                'name'            => $a->name,
                'description'     => $a->description,
                'price'           => (float) ($a->price ?? 0),
                'image'           => $a->image,
                'status'          => $a->status,
                'kind'            => 'product',
                'charge_unit'     => 'per_item',
                'hours_extension' => (int) ($a->hours_extension ?? 0),
            ])
            ->values();

        $listingPhotos = $this->photos($listing);
        $venuePhotos = $this->photos($venue);
        $gallery = count($listingPhotos) ? $listingPhotos : $venuePhotos;
        $base = (float) ($listing->base_price ?? 0);
        $tierPrices = $tiers->pluck('price')->all();
        $cands = array_values(array_filter([$base, ...$tierPrices], fn ($p) => $p > 0));
        $startPrice = $cands ? min($cands) : $base;

        $holdMinutes = (int) (Setting::where('key', 'default_bookings_slot_lock_time')->value('value') ?: 10);

        return sendSuccess('Listing fetched successfully', [
            'detail' => [
                'listing'           => $this->listingPayload($listing, $venue),
                'location'          => $this->locationPayload($venue),
                'photo'             => $gallery[0] ?? '',
                'photos'            => $gallery,
                'mapsUrl'           => $this->mapsUrl($venue),
                'pricingTiers'      => $tiers,
                'addons'            => $addons,
                'paymentOptions'    => $this->paymentOptions($listing),
                'providerHasPayout' => $this->providerHasPayout($venue->owner_user_id),
                'startPrice'        => $startPrice,
                'holdMinutes'       => max(1, $holdMinutes),
            ],
        ]);
    }

    // ── Scope ────────────────────────────────────────────────────────────────

    protected function resolveScope(Request $request): array
    {
        $slug = strtolower(trim((string) $request->input('brand_slug', '')));
        $ownerId = null;
        $whiteLabel = false;

        if ($slug !== '') {
            $whiteLabel = true;
            $business = Business::query()
                ->where(function ($q) use ($slug) {
                    $q->whereRaw('LOWER(username) = ?', [$slug])
                        ->orWhereRaw('LOWER(brand_subdomain) = ?', [$slug]);
                })
                ->first();

            if (! $business) {
                return ['empty' => true, 'whiteLabel' => true, 'ownerId' => null, 'cityIds' => []];
            }

            $ownerId = $business->owner_id;
        }

        $cityIds = $this->resolveCityIds($request);

        return [
            'empty'      => false,
            'whiteLabel' => $whiteLabel,
            'ownerId'    => $ownerId,
            'cityIds'    => $cityIds,
        ];
    }

    protected function resolveCityIds(Request $request): ?array
    {
        $rawId = $request->input('city_id');
        if ($rawId !== null && $rawId !== '' && ctype_digit((string) $rawId)) {
            return [(int) $rawId];
        }

        $name = trim((string) $request->input('city', ''));
        if ($name === '') {
            return null;
        }

        $iso = strtoupper(trim((string) $request->input('country_iso', '')));
        $country = null;
        if ($iso !== '') {
            $country = Country::query()->where('iso2', $iso)->first();
        }

        $query = City::query()->where('name', $name);
        if ($country) {
            $query->where('country_id', $country->id);
        }

        $ids = $query->pluck('id')->all();
        if ($ids) {
            return $ids;
        }

        $fuzzy = City::query()->where('name', 'like', $name . '%')->limit(20);
        if ($country) {
            $fuzzy->where('country_id', $country->id);
        }

        return $fuzzy->pluck('id')->all();
    }

    protected function venueQuery(array $scope)
    {
        $query = Venue::query()->where('status', Venue::ACTIVE);

        if (is_array($scope['cityIds'])) {
            if ($scope['cityIds'] === []) {
                $query->whereRaw('1 = 0');
            } else {
                $query->whereIn('city_id', $scope['cityIds']);
            }
        }

        if ($scope['ownerId']) {
            $query->where('owner_user_id', $scope['ownerId']);
        } elseif (! $scope['whiteLabel']) {
            $suspended = $this->suspendedOwnerIds();
            if ($suspended) {
                $query->where(function ($q) use ($suspended) {
                    $q->whereNull('owner_user_id')
                        ->orWhereNotIn('owner_user_id', $suspended);
                });
            }
        }

        return $query;
    }

    protected function listingQuery(array $scope)
    {
        $query = Listing::query()->where('status', 'active');

        if ($scope['whiteLabel']) {
            $query->where(function ($q) {
                $q->where('show_on_provider_site', true)
                    ->orWhereNull('show_on_provider_site');
            });
        } else {
            $query->where(function ($q) {
                $q->where('show_on_marketplace', true)
                    ->orWhereNull('show_on_marketplace');
            })->where(function ($q) {
                $q->where('approval_status', Listing::APPROVAL_APPROVED)
                    ->orWhereNull('approval_status');
            })->where(function ($q) {
                $q->whereNull('eligibility_status')
                    ->orWhere('eligibility_status', '!=', Listing::ELIGIBILITY_PAUSED);
            });
        }

        return $query;
    }

    protected function listingVisible(Listing $listing, bool $whiteLabel): bool
    {
        if ($whiteLabel) {
            return $listing->show_on_provider_site !== false;
        }

        $approved = ($listing->approval_status ?: Listing::APPROVAL_APPROVED) === Listing::APPROVAL_APPROVED;
        $paused = ($listing->eligibility_status ?: Listing::ELIGIBILITY_ELIGIBLE) === Listing::ELIGIBILITY_PAUSED;

        return $listing->show_on_marketplace !== false && $approved && ! $paused;
    }

    protected function suspendedOwnerIds(): array
    {
        static $ids = null;
        if ($ids === null) {
            $ids = Business::query()
                ->where('status', Business::STATUS_SUSPENDED)
                ->pluck('owner_id')
                ->filter()
                ->all();
        }

        return $ids;
    }

    protected function ownerSuspended(?int $ownerId): bool
    {
        if (! $ownerId) {
            return false;
        }

        return in_array($ownerId, $this->suspendedOwnerIds(), true);
    }

    // ── Cards / payloads ─────────────────────────────────────────────────────

    protected function listingCard(Listing $listing, Venue $venue): array
    {
        $photos = $this->photos($listing);
        $venuePhotos = $this->photos($venue);

        return [
            'id'         => $listing->id,
            'locationId' => $listing->venue_id,
            'name'       => $listing->name,
            'photo'      => $photos[0] ?? ($venuePhotos[0] ?? ''),
            'price'      => $this->startingPrice($listing),
            'slotMins'   => $this->slotMinutes($listing),
            'pinText'    => $venue->name ?: '',
            'mapsUrl'    => $this->mapsUrl($venue),
            'lat'        => $venue->latitude !== null ? (float) $venue->latitude : null,
            'lng'        => $venue->longitude !== null ? (float) $venue->longitude : null,
            'category'   => $listing->category ?: 'sports',
            'sport'      => $this->sportName($listing),
            'sortOrder'  => (int) ($listing->sort_order ?? 9999),
            'createdAt'  => optional($listing->created_at)?->toIso8601String(),
        ];
    }

    protected function venueCard(Venue $venue, $courts): array
    {
        $prices = $courts->map(fn (Listing $l) => $this->startingPrice($l))->filter(fn ($p) => $p !== null && $p > 0);
        $sports = $courts->map(fn (Listing $l) => $this->sportName($l))->filter()->unique()->values();
        $categories = $courts->map(fn (Listing $l) => $l->category ?: 'sports')->unique()->values();
        $photos = $this->photos($venue);

        return [
            'id'         => $venue->id,
            'name'       => $venue->name,
            'photo'      => $photos[0] ?? '',
            'price'      => $prices->isNotEmpty() ? $prices->min() : null,
            'sportsText' => $sports->implode(' · '),
            'mapsUrl'    => $this->mapsUrl($venue),
            'lat'        => $venue->latitude !== null ? (float) $venue->latitude : null,
            'lng'        => $venue->longitude !== null ? (float) $venue->longitude : null,
            'categories' => $categories->all(),
            'subcats'    => $sports->all(),
            'sortOrder'  => (int) ($venue->sort_order ?? 9999),
            'createdAt'  => optional($venue->created_at)?->toIso8601String(),
        ];
    }

    protected function locationPayload(Venue $venue): array
    {
        $cityName = $venue->city?->name;

        return [
            'id'                 => $venue->id,
            'name'               => $venue->name,
            'address'            => $venue->address,
            'description'        => $venue->description,
            'phone'              => $venue->phone,
            'city_id'            => $venue->city_id,
            'city_name'          => $cityName,
            'city'               => $cityName,
            'country_id'         => $venue->country_id,
            'state_id'           => $venue->state_id,
            'owner_user_id'      => $venue->owner_user_id,
            'latitude'           => $venue->latitude !== null ? (float) $venue->latitude : null,
            'longitude'          => $venue->longitude !== null ? (float) $venue->longitude : null,
            'currency_override'  => $venue->currency_override,
            'timezone_override'  => $venue->timezone_override,
            'google_maps_url'    => $venue->maps_url,
            'maps_url'           => $venue->maps_url,
            'photo_url'          => $this->publicUrl($venue->photo_url),
            'photos'             => $this->photos($venue),
            'status'             => $venue->status,
            'sort_order'         => $venue->sort_order,
        ];
    }

    protected function listingPayload(Listing $listing, Venue $venue): array
    {
        $photos = $this->photos($listing);

        return [
            'id'                      => $listing->id,
            'name'                    => $listing->name,
            'venue_id'                => $listing->venue_id,
            'location_id'             => $listing->venue_id,
            'base_price'              => (float) ($listing->base_price ?? 0),
            'slot_duration'           => $this->slotMinutes($listing),
            'buffer_time'             => (int) ($listing->buffer_time ?? 0),
            'capacity'                => (int) ($listing->capacity ?? 1),
            'description'             => $listing->description,
            'category'                => $listing->category ?: 'sports',
            'sport_category'          => $this->sportName($listing),
            'listing_type'            => $listing->listing_type,
            'photos'                  => $photos,
            'photo_url'               => $photos[0] ?? '',
            'amenities'               => $listing->amenities ?? [],
            'tags'                    => $listing->tags ?? [],
            'payment_options'         => $listing->payment_options,
            'online_payment'          => (bool) $listing->online_payment,
            'max_bookings_per_slot'   => (int) ($listing->max_bookings_per_slot ?? 1),
            'max_multiple_bookings'   => $listing->max_multiple_bookings,
            'allow_multiple_bookings' => (bool) $listing->allow_multiple_bookings,
            'extension_pricing'       => $listing->extension_pricing,
            'extension_value'         => $listing->extension_value !== null ? (float) $listing->extension_value : null,
            'extension_max_slots'     => $listing->extension_max_slots !== null ? (int) $listing->extension_max_slots : null,
            'commission_rate'         => $listing->commission_rate !== null ? (float) $listing->commission_rate : null,
            'loyalty_points'          => $listing->loyalty_points,
            'show_on_marketplace'     => $listing->show_on_marketplace,
            'show_on_provider_site'   => $listing->show_on_provider_site,
            'approval_status'         => $listing->approval_status,
            'eligibility_status'      => $listing->eligibility_status,
            'sort_order'              => $listing->sort_order,
            'created_at'              => optional($listing->created_at)?->toIso8601String(),
        ];
    }

    protected function paymentOptions(Listing $listing): array
    {
        $po = is_array($listing->payment_options) ? $listing->payment_options : [];
        $deposit = is_array($po['deposit'] ?? null) ? $po['deposit'] : [];

        return [
            'cash_on_arrival'         => (bool) ($po['cash_on_arrival'] ?? false),
            'deposit_enabled'         => (bool) ($po['deposit_enabled'] ?? ($deposit['enabled'] ?? ! empty($deposit))),
            'deposit_amount'          => (float) ($po['deposit_amount'] ?? ($deposit['amount'] ?? 0)),
            'deposit_percentage'      => (float) ($po['deposit_percentage'] ?? ($deposit['percentage'] ?? 20)),
            'online_payment_enabled'  => (bool) ($listing->online_payment || ($po['online_payment_enabled'] ?? false)),
            'site_deposit_enabled'    => (bool) ($po['site_deposit_enabled'] ?? false),
            'site_deposit_percentage' => (float) ($po['site_deposit_percentage'] ?? 10),
            'site_online_enabled'     => (bool) ($po['site_online_enabled'] ?? false),
            'site_cash_enabled'       => (bool) ($po['site_cash_enabled'] ?? false),
        ];
    }

    /**
     * Vue marketplace expects minutes. Legacy booking listings stored hours (1–12);
     * the admin dashboard stores minutes (15/30/45/60/90/120).
     */
    protected function slotMinutes(Listing $listing): int
    {
        return $listing->slotDurationMinutes();
    }

    protected function startingPrice(Listing $listing): ?float
    {
        $cands = [(float) ($listing->base_price ?? 0)];
        foreach ($listing->pricingGroups ?? [] as $g) {
            $cands[] = (float) ($g->price_per_hour ?? 0);
        }
        $cands = array_values(array_filter($cands, fn ($p) => $p > 0));

        return $cands ? min($cands) : null;
    }

    protected function sportName(Listing $listing): string
    {
        return (string) ($listing->sport_category ?: $listing->sportsCategory?->name ?: '');
    }

    protected function photos($row): array
    {
        $out = [];
        if (is_array($row->photos ?? null)) {
            $out = array_values(array_filter($row->photos));
        }
        if (! $out && is_array($row->images_urls ?? null)) {
            $out = array_values(array_filter($row->images_urls));
        }
        if (! $out && ! empty($row->photo_url)) {
            $out = [$row->photo_url];
        }
        if (! $out && $row instanceof Listing) {
            $fromAssets = $row->relationLoaded('assets')
                ? $row->assets->map(fn ($a) => $a->url)->filter()->all()
                : [];
            if ($fromAssets) {
                $out = $fromAssets;
            }
        }

        return array_values(array_filter(array_map(fn ($p) => $this->publicUrl($p), $out)));
    }

    protected function publicUrl(?string $path): string
    {
        if (! $path) {
            return '';
        }
        if (str_starts_with($path, 'http://') || str_starts_with($path, 'https://') || str_contains($path, '/storage/')) {
            return $path;
        }

        return url('storage/' . ltrim($path, '/'));
    }

    protected function mapsUrl(?Venue $venue): string
    {
        if (! $venue) {
            return '';
        }
        if ($venue->maps_url) {
            return $venue->maps_url;
        }
        if ($venue->latitude !== null && $venue->longitude !== null) {
            return 'https://www.google.com/maps/search/?api=1&query=' . $venue->latitude . ',' . $venue->longitude;
        }
        $q = trim(implode(', ', array_filter([$venue->name, $venue->address])));

        return $q !== '' ? 'https://www.google.com/maps/search/?api=1&query=' . urlencode($q) : '';
    }

    protected function providerHasPayout(?int $ownerId): bool
    {
        if (! $ownerId) {
            return false;
        }

        $wallets = ProviderWallet::query()->where('owner_user_id', $ownerId)->get();
        if ($wallets->isEmpty()) {
            return false;
        }

        $migrated = $wallets->contains(fn ($w) => $w->purpose !== null);
        $ofPurpose = $migrated
            ? $wallets->filter(fn ($w) => ($w->purpose ?: 'subscription') === 'bookings')
            : $wallets;

        return $ofPurpose->contains(function ($w) {
            return $w->setup_method
                && $w->setup_status === 'active'
                && ! $w->payout_paused;
        });
    }
}
