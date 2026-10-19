<?php

namespace App\Services;

use App\Models\Listing;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Nnjeim\World\Models\City;
use Nnjeim\World\Models\Country;

/**
 * Category ranking by what visitors actually choose.
 *
 * Listing counts measure inventory, not demand — an operator with forty padel
 * courts and one football pitch is not evidence that padel is more wanted. So
 * ordering comes from explicit category selections instead.
 *
 * Counts are scoped: the marketplace and each provider storefront keep their
 * own, because activity on one must never reorder the other.
 */
class CategoryPopularityService
{
    /** One server-defined window, so Home and the catalogue agree. */
    public const WINDOW_DAYS = 30;

    /** Repeats from the same visitor inside this are one selection. */
    public const DEDUPE_MINUTES = 60;

    /** Most clicks one visitor may contribute in the window, per scope. */
    public const VISITOR_CAP = 200;

    /**
     * Record one explicit category selection.
     *
     * Everything about the request is checked server-side. A client cannot
     * send a count, cannot name a category that is not in the catalogue, and
     * cannot make its own repeats count by varying the payload.
     */
    public function record(Request $request)
    {
        $data = $request->validate([
            'category'    => 'required|string|max:64',
            'type'        => 'nullable|string|max:96',
            'source'      => 'required|string|in:home,catalog',
            'city_id'     => 'nullable|integer',
            'city'        => 'nullable|string|max:120',
            'country_iso' => 'nullable|string|size:2',
            'brand_slug'  => 'nullable|string|max:64',
        ]);

        $category = trim($data['category']);
        $type     = trim((string) ($data['type'] ?? ''));

        // Only categories the catalogue actually offers. Without this the table
        // becomes whatever anyone cares to POST.
        if (! $this->categoryExists($category, $type)) {
            return sendError('Unknown category.', ['category' => ['Not in the catalogue.']], 422);
        }

        [$cityId, $countryId] = $this->canonicalGeography($data);
        $brand = $this->canonicalBrand($data['brand_slug'] ?? null);
        $visitor = $this->visitorHash($request);

        // A bot or a dashboard preview is not a visitor choosing something.
        if ($this->looksAutomated($request)) {
            return sendSuccess('Recorded', ['counted' => false]);
        }

        $scope = fn ($q) => $q->where('category', $category)->where('type', $type)
            ->where('visitor_hash', $visitor)
            ->when($cityId, fn ($x) => $x->where('city_id', $cityId), fn ($x) => $x->whereNull('city_id'))
            ->when($brand, fn ($x) => $x->where('brand_slug', $brand), fn ($x) => $x->whereNull('brand_slug'));

        // The same person clicking the same chip repeatedly is one opinion.
        $recent = $scope(DB::table('category_clicks'))
            ->where('created_at', '>=', now()->subMinutes(self::DEDUPE_MINUTES))
            ->exists();

        if ($recent) {
            return sendSuccess('Recorded', ['counted' => false]);
        }

        // And one person cannot manufacture a ranking by clicking all day.
        $fromVisitor = DB::table('category_clicks')
            ->where('visitor_hash', $visitor)
            ->where('created_at', '>=', now()->subDays(self::WINDOW_DAYS))
            ->count();

        if ($fromVisitor >= self::VISITOR_CAP) {
            return sendSuccess('Recorded', ['counted' => false]);
        }

        DB::table('category_clicks')->insert([
            'category'     => $category,
            'type'         => $type,
            'source'       => $data['source'],
            'city_id'      => $cityId,
            'country_id'   => $countryId,
            'brand_slug'   => $brand,
            'visitor_hash' => $visitor,
            'created_at'   => now(),
        ]);

        return sendSuccess('Recorded', ['counted' => true]);
    }

    /**
     * Click counts for a catalogue response.
     *
     * Falls back from city to country when a new city has nothing yet, and
     * SAYS which scope it used — a deliberate fallback that is reported, never
     * counts quietly borrowed from an unrelated place.
     *
     * @return array{popularity: array, scope: array}
     */
    public function forScope(?int $cityId, ?int $countryId, ?string $brandSlug): array
    {
        $since = now()->subDays(self::WINDOW_DAYS);
        $brand = $this->canonicalBrand($brandSlug);

        $query = fn () => DB::table('category_clicks')
            ->where('created_at', '>=', $since)
            ->when($brand, fn ($q) => $q->where('brand_slug', $brand), fn ($q) => $q->whereNull('brand_slug'));

        $rows = [];
        $resolved = ['city_id' => $cityId, 'country_id' => $countryId, 'fallback' => null];

        if ($cityId) {
            $rows = $this->tally($query()->where('city_id', $cityId));
        }

        if (! $rows && $countryId) {
            // A city nobody has used yet borrows its country's ranking, and the
            // response says so rather than implying the city produced it.
            $rows = $this->tally($query()->where('country_id', $countryId));
            $resolved['fallback'] = $cityId ? 'country' : null;
        }

        if (! $rows && ! $cityId && ! $countryId) {
            $rows = $this->tally($query());
        }

        return ['popularity' => $rows, 'scope' => $resolved];
    }

    /** @return array<int, array{category:string,type:string,click_count:int}> */
    protected function tally($query): array
    {
        return $query->selectRaw('category, type, COUNT(*) as click_count')
            ->groupBy('category', 'type')
            ->orderByDesc('click_count')
            ->orderBy('category')
            ->get()
            ->map(fn ($r) => [
                'category'    => (string) $r->category,
                'type'        => (string) $r->type,
                'click_count' => (int) $r->click_count,
            ])->values()->all();
    }

    /** Is this a category the catalogue actually offers? */
    protected function categoryExists(string $category, string $type): bool
    {
        return Listing::query()
            ->where('status', 'active')
            ->where('category', $category)
            ->when($type !== '', fn ($q) => $q->where(function ($w) use ($type) {
                // The sport as the catalogue card names it: the listing's own
                // label, or the catalogue row it points at.
                $w->where('sport_category', $type)
                    ->orWhereHas('sportsCategory', fn ($c) => $c->where('name', $type));
            }))
            ->exists();
    }

    /**
     * City and country resolved from whatever the client sent, never trusted
     * as given — a city id is only kept if it really belongs to the country.
     *
     * @return array{0: ?int, 1: ?int}
     */
    protected function canonicalGeography(array $data): array
    {
        $country = null;
        if (! empty($data['country_iso'])) {
            $country = Country::where('iso2', strtoupper($data['country_iso']))->first();
        }

        $city = null;
        if (! empty($data['city_id'])) {
            $city = City::find((int) $data['city_id']);
        } elseif (! empty($data['city'])) {
            $city = City::where('name', $data['city'])
                ->when($country, fn ($q) => $q->where('country_id', $country->id))
                ->first();
        }

        // A city in a different country than the one claimed is not a scope.
        if ($city && $country && (int) $city->country_id !== (int) $country->id) {
            $city = null;
        }

        return [
            $city?->id,
            $city?->country_id ?: $country?->id,
        ];
    }

    /** Null means the marketplace itself. */
    protected function canonicalBrand(?string $slug): ?string
    {
        $slug = strtolower(trim((string) $slug));

        return $slug === '' ? null : $slug;
    }

    /**
     * A salted, truncated fingerprint of where the request came from.
     *
     * Enough to collapse one person's repeats; not enough to identify them,
     * and never stored in reversible form. Salted with the app key so the
     * table alone cannot be correlated back to an address.
     */
    protected function visitorHash(Request $request): string
    {
        return hash('sha256', implode('|', [
            config('app.key'),
            $request->ip(),
            substr((string) $request->userAgent(), 0, 180),
        ]));
    }

    /** Obvious automation, which is not a visitor expressing a preference. */
    protected function looksAutomated(Request $request): bool
    {
        $ua = strtolower((string) $request->userAgent());

        if ($ua === '') {
            return true;
        }

        foreach (['bot', 'crawler', 'spider', 'headless', 'curl', 'wget', 'python-requests'] as $needle) {
            if (str_contains($ua, $needle)) {
                return true;
            }
        }

        // A provider previewing their own site is not public demand.
        return $request->boolean('preview');
    }
}
