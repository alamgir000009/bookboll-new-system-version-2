<?php

namespace Database\Seeders;

use App\Models\ClassProgram;
use App\Models\Venue;
use App\Models\Schedule;
use App\Support\SupabaseRestClient;
use Illuminate\Database\Seeder;
use Nnjeim\World\Models\City;
use Nnjeim\World\Models\Country;
use Throwable;

/**
 * Import the Supabase `locations` that class programs point at, and link each
 * program to the resulting venue.
 *
 * ClassMembershipFromSupabaseSeeder matches venues BY NAME, so programs whose
 * venue was never imported end up with venue_id = NULL — the storefront then has
 * no venue name, address or opening hours to show. This fills that gap.
 *
 * IDEMPOTENT: venues are matched by name first, so re-running relinks instead of
 * duplicating.
 *
 *   php artisan db:seed --class=ProgramVenuesFromSupabaseSeeder
 */
class ProgramVenuesFromSupabaseSeeder extends Seeder
{
    public function run(): void
    {
        try {
            $client = SupabaseRestClient::fromConfig();
        } catch (Throwable $e) {
            $this->command?->error($e->getMessage());

            return;
        }

        $needed = ClassProgram::query()
            ->whereNull('venue_id')
            ->whereNotNull('location_id')
            ->pluck('location_id')
            ->unique()
            ->values();

        if ($needed->isEmpty()) {
            $this->command?->info('Every program already has a venue — nothing to do.');

            return;
        }

        $this->command?->info("Resolving {$needed->count()} venue(s) from Supabase…");

        $locations = collect($client->fetchAll('locations', 'name.asc'))->keyBy('id');
        $cities = collect($client->fetchAll('cities', 'name.asc'))->keyBy('id');
        $countries = collect($client->fetchAll('countries', 'name.asc'))->keyBy('id');
        $hours = collect($client->fetchAll('location_hours', 'day_of_week.asc'))->groupBy('location_id');

        $created = 0;
        $reused = 0;
        $linked = 0;
        $skipped = 0;
        $hourRows = 0;

        foreach ($needed as $locationId) {
            $loc = $locations->get($locationId);
            $name = trim((string) ($loc['name'] ?? ''));

            if ($name === '') {
                $this->command?->warn("No Supabase location for {$locationId} — skipped.");
                $skipped++;

                continue;
            }

            $venue = Venue::query()->whereRaw('LOWER(TRIM(name)) = ?', [mb_strtolower($name)])->first();

            if ($venue) {
                $reused++;
            } else {
                $venue = Venue::create($this->venueAttributes($loc, $name, $cities, $countries));
                $created++;
            }

            $hourRows += $this->importHours($venue, $hours->get($locationId, collect()));

            $linked += ClassProgram::query()
                ->whereNull('venue_id')
                ->where('location_id', $locationId)
                ->update(['venue_id' => $venue->id]);
        }

        $this->command?->info("Done. {$created} venue(s) created, {$reused} reused, {$linked} program(s) linked, {$hourRows} opening-hour row(s), {$skipped} skipped.");
    }

    /**
     * Weekly opening hours — drives the gym hours block on the program page.
     *
     * Venue hours are `schedules` rows with a null listing_id: the same table a
     * listing's own hours live in, and the one the booking engine reads.
     */
    protected function importHours(Venue $venue, $rows): int
    {
        $days = array_flip(Schedule::DAY_INDEX);   // 0..6 → 'sunday'…
        $written = 0;

        foreach ($rows as $row) {
            $day = $days[(int) ($row['day_of_week'] ?? -1)] ?? null;
            if (! $day) {
                continue;
            }

            $isOpen = (bool) ($row['is_open'] ?? false);

            Schedule::updateOrCreate(
                [
                    'venue_id'   => $venue->id,
                    'listing_id' => null,
                    'day'        => $day,
                ],
                [
                    'is_open'               => $isOpen,
                    'start_time'            => $isOpen ? ($row['open_time'] ?? null) : null,
                    'end_time'              => $isOpen ? ($row['close_time'] ?? null) : null,
                    'inherit_from_location' => true,
                ]
            );
            $written++;
        }

        return $written;
    }

    /** @return array<string, mixed> */
    protected function venueAttributes(array $loc, string $name, $cities, $countries): array
    {
        [$countryId, $stateId, $cityId] = $this->resolveGeo($loc, $cities, $countries);

        return [
            'name'               => $name,
            'status'             => Venue::ACTIVE,
            'approval_status'    => Venue::APPROVAL_APPROVED,
            'eligibility_status' => Venue::ELIGIBILITY_ELIGIBLE,
            'address'            => (string) ($loc['address'] ?? ''),
            'phone'              => (string) ($loc['phone'] ?? ''),
            'description'        => (string) ($loc['description'] ?? ''),
            'photo_url'          => (string) ($loc['photo_url'] ?? ''),
            'maps_url'           => (string) ($loc['google_maps_url'] ?? ''),
            'latitude'           => $loc['latitude'] ?? null,
            'longitude'          => $loc['longitude'] ?? null,
            'currency_override'  => $loc['currency_override'] ?? null,
            'timezone_override'  => $loc['timezone_override'] ?? null,
            'country_id'         => $countryId,
            'state_id'           => $stateId,
            'city_id'            => $cityId,
        ];
    }

    /**
     * Supabase stores city as a UUID; Laravel uses the world package. Resolve via
     * the city name + the country's ISO code.
     *
     * @return array{0: int|null, 1: int|null, 2: int|null}
     */
    protected function resolveGeo(array $loc, $cities, $countries): array
    {
        $city = $cities->get($loc['city_id'] ?? '');
        if (! $city) {
            return [null, null, null];
        }

        $iso = $countries->get($city['country_id'] ?? '')['iso_code'] ?? null;
        $country = $iso ? Country::where('iso2', $iso)->first() : null;
        if (! $country) {
            return [null, null, null];
        }

        $match = City::where('country_id', $country->id)
            ->where('name', $city['name'] ?? '')
            ->first();

        return [$country->id, $match->state_id ?? null, $match->id ?? null];
    }
}
