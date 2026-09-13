<?php

namespace App\Support;

use App\Models\Booking;
use App\Models\ClassProgram;
use App\Models\ClassSubscription;
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
    /**
     * The customers this user may see — a direct port of the dashboard's
     * `relevantCustomerIds`, and the single definition shared by the Customers list
     * and the marketing composer. If those two ever disagreed, a provider would see
     * people they cannot email, or email people they cannot see.
     *
     * A customer is in scope when they have BOOKED at a venue in scope, or SUBSCRIBED
     * to a programme in scope. Subscriptions match on email because that is the only
     * link class_subscriptions carries.
     *
     * @return array<int, int>|null  null = unrestricted (global admin); [] = nobody
     */
    public static function customerIdsInScope(?User $user): ?array
    {
        if (! $user) {
            return [];
        }

        $venueIds = self::allowedVenueIds($user);
        if ($venueIds === null) {
            return null;   // super admin / platform owner — the whole marketplace
        }

        $ownerId = self::ownerUserId($user);

        // ── Booked at a venue in scope ──
        $ids = $venueIds
            ? Booking::whereIn('venue_id', $venueIds)->whereNotNull('user_id')->distinct()->pluck('user_id')
            : collect();

        // ── Subscribed to a programme in scope ──
        // A programme whose venue was removed still belongs to its owner, so the owner
        // fallback keeps it in scope; venue alone would silently drop those members.
        $programs = ClassProgram::query()
            ->where(function ($q) use ($venueIds, $ownerId, $user) {
                if ($venueIds) {
                    $q->whereIn('venue_id', $venueIds);
                }
                if ($ownerId !== null) {
                    $q->orWhere('mapped_owner_user_id', $ownerId)
                        ->orWhere('owner_user_id', (string) $ownerId);
                } else {
                    foreach (self::jurisdictionOwnerIds($user) as $oid) {
                        $q->orWhere('mapped_owner_user_id', $oid)->orWhere('owner_user_id', (string) $oid);
                    }
                }
            })
            ->pluck('id');

        if ($programs->isNotEmpty()) {
            $emails = ClassSubscription::whereIn('program_id', $programs)
                ->whereNotNull('customer_email')->where('customer_email', '!=', '')
                ->distinct()->pluck('customer_email')
                ->map(fn ($e) => mb_strtolower(trim($e)))->filter()->unique();

            if ($emails->isNotEmpty()) {
                $ids = $ids->merge(
                    User::whereIn(DB::raw('LOWER(email)'), $emails->all())->pluck('id')
                );
            }
        }

        return $ids->map(fn ($i) => (int) $i)->unique()->values()->all();
    }

    /**
     * Providers a PLATFORM user administers — those with a venue in their jurisdiction,
     * plus (the dashboard's fallback) those whose own country is in it, so a provider
     * who has not added a venue yet is still administered by the right country admin.
     *
     * @return array<int, int>
     */
    public static function jurisdictionOwnerIds(?User $user): array
    {
        $venueIds = self::allowedVenueIds($user);
        if ($venueIds === null) {
            return [];   // unrestricted — the caller does not need an owner filter
        }

        $byVenue = $venueIds
            ? Venue::whereIn('id', $venueIds)->whereNotNull('owner_user_id')->distinct()->pluck('owner_user_id')
            : collect();

        $countryIds = self::jurisdictionCountryIds($user);
        $byCountry = ($countryIds === null || ! $countryIds)
            ? collect()
            : User::whereIn('country_id', $countryIds)
                ->whereHas('roles', fn ($q) => $q->where('name', 'provider_admin'))
                ->pluck('id');

        return $byVenue->merge($byCountry)->map(fn ($i) => (int) $i)->unique()->values()->all();
    }

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
     * May this user act on this one venue?
     *
     * THE authority for per-venue permission. Services used to ask
     * `DB::table('user_venue')` directly, which only knows about explicitly
     * assigned venues — so a provider who OWNED a venue (venues.owner_user_id,
     * which is what allowedVenueIds uses) was refused by those checks. The venue
     * appeared in their list and then every write on it came back 403.
     */
    public static function canTouchVenue(?User $user, int $venueId): bool
    {
        if (! $user || $venueId <= 0) {
            return false;
        }

        $ids = self::allowedVenueIds($user);

        // null = unrestricted (global admin); [] = nothing allowed.
        return $ids === null ? true : in_array($venueId, $ids, true);
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
