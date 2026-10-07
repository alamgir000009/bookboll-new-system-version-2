<?php

namespace Database\Seeders;

use App\Models\Listing;
use App\Models\Venue;
use Illuminate\Database\Seeder;
use Nnjeim\World\Models\City;
use Nnjeim\World\Models\Country;
use Nnjeim\World\Models\State;

/**
 * Point every existing venue at Doha, Qatar.
 *
 * Listings have no country/city columns of their own — marketplace and admin
 * location filters read the parent venue — so updating venues is what places
 * existing listings in Doha.
 *
 * The locations migration only backfills venues where country_id is NULL.
 * This seeder overwrites whatever is already stored, including wrong countries.
 *
 * Requires the world tables (run WorldSeeder first if countries/cities are empty):
 *
 *     php artisan db:seed --class=WorldSeeder
 *     php artisan db:seed --class=VenuesDohaQatarSeeder
 *
 * Idempotent. NOT part of DatabaseSeeder.
 */
class VenuesDohaQatarSeeder extends Seeder
{
    /** Approximate Doha city centre — used only when a venue has no coordinates. */
    private const DOHA_LAT = 25.2854000;

    private const DOHA_LNG = 51.5310000;

    public function run(): void
    {
        $qatar = Country::where('iso2', 'QA')->first();
        if (! $qatar) {
            $this->command?->error('Qatar (QA) not found in countries. Run: php artisan db:seed --class=WorldSeeder');

            return;
        }

        $dohaState = State::where('country_id', $qatar->id)
            ->where(function ($q) {
                $q->where('name', 'Doha')
                    ->orWhere('name', 'Ad Dawhah')
                    ->orWhere('name', 'Ad-Dawhah')
                    ->orWhere('name', 'Ad Dawḩah');
            })
            ->first();

        $cityQuery = City::where('country_id', $qatar->id)
            ->where(function ($q) {
                $q->where('name', 'Doha')
                    ->orWhere('name', 'Ad Dawhah')
                    ->orWhere('name', 'Ad-Dawhah')
                    ->orWhere('name', 'Ad Dawḩah');
            });

        $dohaCity = $dohaState
            ? (clone $cityQuery)->where('state_id', $dohaState->id)->first() ?: $cityQuery->first()
            : $cityQuery->first();

        if (! $dohaCity) {
            $this->command?->error('Doha city not found for Qatar. Re-run WorldSeeder and try again.');

            return;
        }

        $payload = [
            'country_id' => $qatar->id,
            'state_id'   => $dohaState?->id ?? $dohaCity->state_id,
            'city_id'    => $dohaCity->id,
        ];

        $venuesUpdated = 0;
        $coordsFilled  = 0;
        $addressFilled = 0;

        Venue::orderBy('id')->each(function (Venue $venue) use ($payload, &$venuesUpdated, &$coordsFilled, &$addressFilled) {
            $updates = $payload;

            if ($venue->latitude === null || $venue->longitude === null) {
                $updates['latitude']  = self::DOHA_LAT;
                $updates['longitude'] = self::DOHA_LNG;
                $coordsFilled++;
            }

            if (! $venue->address) {
                $updates['address'] = 'Doha, Qatar';
                $addressFilled++;
            }

            if (! $venue->timezone_override) {
                $updates['timezone_override'] = 'Asia/Qatar';
            }

            $venue->update($updates);
            $venuesUpdated++;
        });

        $listings = Listing::count();

        $this->command?->info("VenuesDohaQatarSeeder: {$venuesUpdated} venues → {$dohaCity->name}, {$qatar->name} (city_id={$dohaCity->id}).");
        $this->command?->info("  listings inherit venue location: {$listings}");
        $this->command?->info("  empty addresses filled: {$addressFilled}; empty coordinates filled: {$coordsFilled}");
    }
}
