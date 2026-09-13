<?php

namespace Database\Seeders\Concerns;

use App\Models\Listing;
use App\Models\Venue;
use App\Support\SupabaseRestClient;
use Nnjeim\World\Models\City;
use Nnjeim\World\Models\Country;

/**
 * Translate Supabase UUIDs into the local integer ids.
 *
 * Supabase keys geography, venues and listings by UUID. The
 * `convert_*_ids_to_laravel` migrations turned the matching columns into
 * unsigned bigints, so writing the raw UUID now fails with
 * "Data truncated for column 'city_id'" and the whole row is lost.
 *
 * Geography resolves by NAME through the ISO country code, the same way
 * ProgramVenuesFromSupabaseSeeder already did it; venues and listings resolve
 * by name, which is how ClassMembership and ListingsApprovals match them.
 * Anything that cannot be resolved returns null rather than guessing.
 */
trait ResolvesSupabaseIds
{
    /** @var array<string, array<string, mixed>> */
    protected array $sbCities = [];
    /** @var array<string, array<string, mixed>> */
    protected array $sbCountries = [];
    /** @var array<string, string> supabase location uuid → name */
    protected array $sbLocations = [];
    /** @var array<string, string> supabase listing uuid → name */
    protected array $sbListings = [];

    protected array $cacheCity = [];
    protected array $cacheCountry = [];
    protected array $cacheVenue = [];
    protected array $cacheListing = [];
    protected array $cacheBooking = [];

    /** Load the Supabase lookup tables once per seeder run. */
    protected function loadSupabaseMaps(SupabaseRestClient $client, bool $withVenues = false): void
    {
        foreach ($client->fetchAll('cities', 'name.asc', 1000, ['id.asc']) as $r) {
            if (! empty($r['id'])) {
                $this->sbCities[(string) $r['id']] = $r;
            }
        }
        foreach ($client->fetchAll('countries', 'name.asc', 500, ['id.asc']) as $r) {
            if (! empty($r['id'])) {
                $this->sbCountries[(string) $r['id']] = $r;
            }
        }
        if (! $withVenues) {
            return;
        }
        foreach ($client->fetchAll('locations', 'name.asc', 1000, ['id.asc']) as $r) {
            if (! empty($r['id'])) {
                $this->sbLocations[(string) $r['id']] = (string) ($r['name'] ?? '');
            }
        }
        foreach ($client->fetchAll('listings', 'name.asc', 1000, ['id.asc']) as $r) {
            if (! empty($r['id'])) {
                $this->sbListings[(string) $r['id']] = (string) ($r['name'] ?? '');
            }
        }
    }

    /** Supabase country uuid → local countries.id */
    protected function localCountryId(?string $uuid): ?int
    {
        $uuid = (string) $uuid;
        if ($uuid === '') {
            return null;
        }
        if (array_key_exists($uuid, $this->cacheCountry)) {
            return $this->cacheCountry[$uuid];
        }
        $iso = $this->sbCountries[$uuid]['iso_code'] ?? null;
        $id = $iso ? Country::where('iso2', $iso)->value('id') : null;

        return $this->cacheCountry[$uuid] = $id ? (int) $id : null;
    }

    /** Supabase city uuid → local cities.id (matched by name inside its country) */
    protected function localCityId(?string $uuid): ?int
    {
        $uuid = (string) $uuid;
        if ($uuid === '') {
            return null;
        }
        if (array_key_exists($uuid, $this->cacheCity)) {
            return $this->cacheCity[$uuid];
        }
        $city = $this->sbCities[$uuid] ?? null;
        if (! $city) {
            return $this->cacheCity[$uuid] = null;
        }
        $countryId = $this->localCountryId((string) ($city['country_id'] ?? ''));
        $id = $countryId
            ? City::where('country_id', $countryId)->where('name', $city['name'] ?? '')->value('id')
            : null;

        return $this->cacheCity[$uuid] = $id ? (int) $id : null;
    }

    /** Supabase location uuid → local venues.id (matched by name) */
    protected function localVenueId(?string $uuid): ?int
    {
        $uuid = (string) $uuid;
        if ($uuid === '') {
            return null;
        }
        if (array_key_exists($uuid, $this->cacheVenue)) {
            return $this->cacheVenue[$uuid];
        }
        $name = $this->sbLocations[$uuid] ?? null;
        $id = $name ? Venue::where('name', $name)->value('id') : null;

        return $this->cacheVenue[$uuid] = $id ? (int) $id : null;
    }

    /** Supabase booking uuid → local bookings.id (bookings carry their uuid) */
    protected function localBookingId(?string $uuid): ?int
    {
        $uuid = (string) $uuid;
        if ($uuid === '') {
            return null;
        }
        if (array_key_exists($uuid, $this->cacheBooking)) {
            return $this->cacheBooking[$uuid];
        }
        $id = \App\Models\Booking::where('uuid', $uuid)->value('id');

        return $this->cacheBooking[$uuid] = $id ? (int) $id : null;
    }

    /** Supabase listing uuid → local listings.id (matched by name) */
    protected function localListingId(?string $uuid): ?int
    {
        $uuid = (string) $uuid;
        if ($uuid === '') {
            return null;
        }
        if (array_key_exists($uuid, $this->cacheListing)) {
            return $this->cacheListing[$uuid];
        }
        $name = $this->sbListings[$uuid] ?? null;
        $id = $name ? Listing::where('name', $name)->value('id') : null;

        return $this->cacheListing[$uuid] = $id ? (int) $id : null;
    }
}
