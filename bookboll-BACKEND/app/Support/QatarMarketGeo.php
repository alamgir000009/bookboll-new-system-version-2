<?php

namespace App\Support;

use Illuminate\Database\Eloquent\Builder;
use Nnjeim\World\Models\City;
use Nnjeim\World\Models\Country;

/**
 * Qatar is small enough that Doha, Al Rayyan, Lusail, Al Wakrah, etc. are one
 * marketplace. Customer catalog filters are city-strict everywhere else; here
 * a city in QA expands to the whole country so GPS / picker city does not hide
 * nearby courts.
 */
class QatarMarketGeo
{
    public const ISO = 'QA';

    public static function countryId(): ?int
    {
        static $id = false;
        if ($id === false) {
            $found = Country::query()->where('iso2', self::ISO)->value('id');
            $id = $found !== null ? (int) $found : null;
        }

        return $id;
    }

    /** @return list<int> */
    public static function allCityIds(): array
    {
        static $ids = null;
        if ($ids !== null) {
            return $ids;
        }

        $countryId = self::countryId();
        $ids = $countryId
            ? City::query()->where('country_id', $countryId)->pluck('id')->map(fn ($id) => (int) $id)->all()
            : [];

        return $ids;
    }

    public static function touchesQatar(array $cityIds): bool
    {
        $qatarId = self::countryId();
        if (! $qatarId || $cityIds === []) {
            return false;
        }

        return City::query()
            ->whereIn('id', $cityIds)
            ->where('country_id', $qatarId)
            ->exists();
    }

    /**
     * @param  list<int>|null  $cityIds
     * @return list<int>|null
     */
    public static function expandCityIds(?array $cityIds): ?array
    {
        if ($cityIds === null) {
            return null;
        }
        if ($cityIds === []) {
            return [];
        }

        $normalized = array_values(array_unique(array_map('intval', $cityIds)));
        if (! self::touchesQatar($normalized)) {
            return $normalized;
        }

        $all = self::allCityIds();

        return $all ?: $normalized;
    }

    /** @return list<int> */
    public static function expandCityId(int $cityId): array
    {
        return self::expandCityIds([$cityId]) ?: [$cityId];
    }

    /**
     * Venue catalog filter: exact cities normally; for Qatar, every Qatar city
     * plus any venue whose country_id is QA (covers a stale/missing city_id).
     *
     * @param  list<int>  $cityIds  Unexpanded ids from the request.
     */
    public static function constrainVenues(Builder $query, array $cityIds): void
    {
        $expanded = self::expandCityIds($cityIds) ?: $cityIds;
        $qatarId = self::countryId();

        if ($qatarId && self::touchesQatar($cityIds)) {
            $query->where(function (Builder $q) use ($expanded, $qatarId) {
                $q->whereIn('city_id', $expanded)
                    ->orWhere('country_id', $qatarId);
            });

            return;
        }

        $query->whereIn('city_id', $expanded);
    }
}
