<?php

namespace App\Support;

use App\Models\City;
use App\Models\Country;

/**
 * The place a community is based, as names rather than ids.
 *
 * Communities store `city_id` and `country_id` but no relations, and every
 * reader (the community page, the moderation queue) wants "Doha, Qatar" next
 * to the name. Resolved here, once per id per request: a list of a hundred
 * communities in three cities costs three city lookups, not a hundred. The
 * country falls back to the city's own country when only the city was saved.
 */
class CommunityPlace
{
    /** @var array<string, string|null> */
    protected static array $cityNames = [];

    /** @var array<string, int|null> */
    protected static array $cityCountries = [];

    /** @var array<string, string|null> */
    protected static array $countryNames = [];

    /** @return array{city_name: string, country_name: string} */
    public static function names($cityId, $countryId): array
    {
        $cityName = $cityId ? self::cityName((int) $cityId) : '';
        if (! $countryId && $cityId) {
            $countryId = self::cityCountryId((int) $cityId);
        }
        $countryName = $countryId ? self::countryName((int) $countryId) : '';

        return ['city_name' => $cityName, 'country_name' => $countryName];
    }

    protected static function cityName(int $id): string
    {
        $key = (string) $id;
        if (! array_key_exists($key, self::$cityNames)) {
            self::$cityNames[$key] = $id > 0 ? City::whereKey($id)->value('name') : null;
        }

        return (string) (self::$cityNames[$key] ?? '');
    }

    protected static function cityCountryId(int $id): ?int
    {
        $key = (string) $id;
        if (! array_key_exists($key, self::$cityCountries)) {
            $value = $id > 0 ? City::whereKey($id)->value('country_id') : null;
            self::$cityCountries[$key] = $value ? (int) $value : null;
        }

        return self::$cityCountries[$key];
    }

    protected static function countryName(int $id): string
    {
        $key = (string) $id;
        if (! array_key_exists($key, self::$countryNames)) {
            self::$countryNames[$key] = $id > 0 ? Country::whereKey($id)->value('name') : null;
        }

        return (string) (self::$countryNames[$key] ?? '');
    }

    /** Tests and long-running workers: forget what was looked up. */
    public static function flush(): void
    {
        self::$cityNames = [];
        self::$cityCountries = [];
        self::$countryNames = [];
    }
}
