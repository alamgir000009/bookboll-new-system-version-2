<?php

namespace App\Support;

use App\Models\Venue;
use Illuminate\Support\Facades\Schema;
use Nnjeim\World\Models\City;

/**
 * Per-city service modules.
 *
 * A super admin can switch a service off for a city; the matching creation flow
 * is then refused there. Anything already published is left alone — the switch
 * stops new supply, it does not retire existing supply.
 *
 * The dashboard checks this too (Listings.vue / ClassesSubscriptions.vue), but a
 * client-side check is only a courtesy: the rule is enforced here so posting
 * straight to the API is refused the same way.
 *
 * NULL / missing reads as ON, so a city seeded before the columns existed keeps
 * behaving exactly as it did.
 */
class CityModules
{
    public const LISTINGS    = 'module_listings';
    public const CLASSES     = 'module_classes';
    public const MATCHMAKING = 'module_matchmaking';

    /** Is $module enabled for the city a venue sits in? */
    public static function enabledForVenue($venueId, string $module): bool
    {
        if (! $venueId || ! Schema::hasColumn('cities', $module)) {
            return true;
        }

        $cityId = Venue::whereKey($venueId)->value('city_id');
        if (! $cityId) {
            return true;   // no city recorded — nothing to gate on
        }

        $value = City::whereKey($cityId)->value($module);

        return $value === null || (bool) $value;
    }

    /** The city's display name, for the refusal message. */
    public static function cityNameForVenue($venueId): string
    {
        $cityId = $venueId ? Venue::whereKey($venueId)->value('city_id') : null;

        return ($cityId ? City::whereKey($cityId)->value('name') : null) ?: 'this city';
    }

    /**
     * Refusal shaped like the dashboard's own message, or null when allowed.
     *
     * @return \Illuminate\Http\JsonResponse|null
     */
    public static function guard($venueId, string $module, string $what)
    {
        if (self::enabledForVenue($venueId, $module)) {
            return null;
        }

        $city = self::cityNameForVenue($venueId);
        $label = $module === self::CLASSES ? 'Memberships' : ($module === self::LISTINGS ? 'Listings' : 'Matchmaking');

        return sendError(
            "{$what} are disabled for {$city}. A Super Admin must enable the \"{$label}\" module for this city in Countries & Cities first.",
            [],
            422
        );
    }
}
