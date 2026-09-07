<?php

namespace App\Support;

use App\Models\User;
use App\Models\Venue;
use Illuminate\Support\Facades\DB;
use Nnjeim\World\Models\City;

/**
 * Which rows a signed-in admin may see — the server-side twin of the dashboard's
 * lib/currentUser.js (ownership) and lib/jurisdiction.js (geography).
 *
 * Two different models, by design:
 *
 *   PROVIDER roles are scoped by OWNERSHIP. A provider_admin owns venues via
 *   venues.owner_user_id; their team inherits through users.owner_user_id.
 *   Managers/Staff may additionally be PINNED to specific venues; an owner never is.
 *
 *   PLATFORM roles are scoped by JURISDICTION — their assigned countries (falling
 *   back to their own country), with platform_staff optionally pinned to venues.
 *   super_admin (and legacy `admin`) see everything.
 *
 * Venue pins come from either users.assigned_location_ids (what the dashboard
 * writes) or the older user_venue pivot, so existing assignments keep working.
 */
class AccessScope
{
    /** The provider business this user belongs to, or null for platform-side users. */
    public static function ownerUserId(?User $user): ?int
    {
        if (! $user) {
            return null;
        }
        if ($user->hasRole('provider_admin')) {
            return (int) $user->id;
        }

        return $user->owner_user_id ? (int) $user->owner_user_id : null;
    }

    /** Sees everything, unscoped. */
    public static function isGlobal(?User $user): bool
    {
        return (bool) $user?->hasAnyRole(Capabilities::globalRoles());
    }

    public static function isPlatform(?User $user): bool
    {
        return (bool) $user?->hasAnyRole(Capabilities::platformRoles());
    }

    public static function isProviderScoped(?User $user): bool
    {
        return (bool) $user?->hasAnyRole(Capabilities::providerRoles());
    }

    /**
     * Only Managers/Staff are narrowed by venue pins — an Owner sees their whole
     * business, and platform admins their whole jurisdiction (currentUser.js
     * scopeNarrows()).
     */
    public static function pinsApply(?User $user): bool
    {
        if (! $user || self::isGlobal($user)) {
            return false;
        }

        return ! $user->hasAnyRole(['country_admin', 'provider_admin']);
    }

    /**
     * Countries a platform user administers.
     *
     * @return int[]|null null = every country (super admin / provider-side)
     */
    public static function jurisdictionCountryIds(?User $user): ?array
    {
        if (! $user || self::isGlobal($user) || ! self::isPlatform($user)) {
            return null;
        }

        $assigned = array_values(array_filter((array) ($user->assigned_country_ids ?? [])));
        if ($assigned) {
            return array_map('intval', $assigned);
        }

        return $user->country_id ? [(int) $user->country_id] : [];
    }

    /**
     * Venue ids explicitly pinned to this user, from either source.
     *
     * @return int[]
     */
    public static function pinnedVenueIds(?User $user): array
    {
        if (! $user) {
            return [];
        }

        $ids = array_map('intval', array_values(array_filter((array) ($user->assigned_location_ids ?? []))));

        $pivot = DB::table('user_venue')->where('user_id', $user->id)->pluck('venue_id')->all();

        return array_values(array_unique(array_merge($ids, array_map('intval', $pivot))));
    }

    /**
     * The venues this user may touch.
     *
     * @return int[]|null null = unrestricted; [] = nothing
     */
    public static function allowedVenueIds(?User $user): ?array
    {
        if (! $user) {
            return [];
        }
        if (self::isGlobal($user)) {
            return null;
        }

        $pins = self::pinnedVenueIds($user);

        // ── Provider side: everything their business owns, then any pins ──
        $ownerId = self::ownerUserId($user);
        if ($ownerId !== null) {
            $owned = Venue::where('owner_user_id', $ownerId)->pluck('id')->map(fn ($i) => (int) $i)->all();

            if (self::pinsApply($user) && $pins) {
                return array_values(array_intersect($owned, $pins));
            }

            return $owned;
        }

        // ── Platform side: jurisdiction, or explicit pins for platform_staff ──
        if (self::isPlatform($user)) {
            if ($user->hasRole('platform_staff') && $pins) {
                return $pins;
            }

            $countryIds = self::jurisdictionCountryIds($user);
            if ($countryIds === null) {
                return null;
            }
            if (! $countryIds) {
                return [];
            }

            return Venue::query()
                ->where(function ($q) use ($countryIds) {
                    $q->whereIn('country_id', $countryIds)
                        ->orWhereIn('city_id', City::whereIn('country_id', $countryIds)->select('id'));
                })
                ->pluck('id')->map(fn ($i) => (int) $i)->all();
        }

        // A user with no business and no platform role sees only their pins.
        return $pins;
    }

    /**
     * Constrain a query to the venues this user may see.
     *
     * @param  string  $column  the venue foreign key on the query's table
     */
    public static function applyVenue($query, string $column = 'venue_id', ?User $user = null)
    {
        $user = $user ?: auth()->user();
        if (! $user) {
            return $query->whereRaw('1 = 0');
        }

        $ids = self::allowedVenueIds($user);
        if ($ids === null) {
            return $query;
        }
        if (! $ids) {
            return $query->whereRaw('1 = 0');
        }

        return $query->whereIn($column, $ids);
    }

    /**
     * Listing pins (currentUser.js scopeListings) — empty means no restriction
     * beyond the venue scope.
     *
     * @return int[]
     */
    public static function pinnedListingIds(?User $user): array
    {
        if (! $user || ! self::pinsApply($user)) {
            return [];
        }

        return array_map('intval', array_values(array_filter((array) ($user->assigned_listing_ids ?? []))));
    }

    /** @return int[] */
    public static function pinnedProgramIds(?User $user): array
    {
        if (! $user || ! self::pinsApply($user)) {
            return [];
        }

        return array_map('intval', array_values(array_filter((array) ($user->assigned_program_ids ?? []))));
    }
}
