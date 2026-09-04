<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * hosted_games was seeded from Supabase and still holds its UUID ids for city,
 * country, venue, listing and booking. The host flow writes Laravel integer ids,
 * so the two formats would sit in one column and every filtered lookup would
 * silently miss — the same fault that made the communities page look empty.
 *
 * Cities and countries map cleanly (the seed only used Doha, Dubai and Cairo).
 * Venue/listing/booking ids do not: those Supabase rows were never migrated, and
 * `venues` has no uuid column to join back to. Only the two venues that survived
 * by name are remapped; the rest are cleared rather than left as broken pointers.
 * Legacy rows keep custom_location_name, which is what the UI shows anyway.
 */
return new class extends Migration
{
    /** Supabase city uuid => [city name, country iso2]. */
    private const CITIES = [
        '97064dd0-b0a7-4c81-be7e-488bb821a975' => ['Doha', 'QA'],
        'cba3f62e-4be0-483a-b8dc-42760c3cc153' => ['Dubai', 'AE'],
        'd128a349-5b01-4b48-8bad-b4c8d5bc475a' => ['Cairo', 'EG'],
    ];

    /** Supabase country uuid => iso2. */
    private const COUNTRIES = [
        '79cad528-bd7f-44f9-adbf-6193025843dd' => 'QA',
        'bb71572d-c19a-4e62-b567-002e3ba964e8' => 'AE',
        'e74dbece-9fb9-46e8-b139-752fa1d6020e' => 'EG',
    ];

    /** Supabase location uuid => venue name, for the venues that were migrated. */
    private const VENUES = [
        'b1000000-0000-0000-0000-000000000005' => 'Riyadh Padel Club — Al Olaya',
        'b1000000-0000-0000-0000-000000000006' => 'Cairo Football Arena — Heliopolis',
    ];

    public function up(): void
    {
        foreach (self::CITIES as $uuid => [$name, $iso]) {
            $countryId = DB::table('countries')->where('iso2', $iso)->value('id');
            $cityId    = $countryId ? DB::table('cities')->where('country_id', $countryId)->where('name', $name)->value('id') : null;

            if ($cityId) {
                DB::table('hosted_games')->where('city_id', $uuid)->update(['city_id' => $cityId]);
            }
        }

        foreach (self::COUNTRIES as $uuid => $iso) {
            $countryId = DB::table('countries')->where('iso2', $iso)->value('id');

            if ($countryId) {
                DB::table('hosted_games')->where('country_id', $uuid)->update(['country_id' => $countryId]);
            }
        }

        foreach (self::VENUES as $uuid => $name) {
            $venueId = DB::table('venues')->where('name', $name)->value('id');

            if ($venueId) {
                DB::table('hosted_games')->where('location_id', $uuid)->update(['location_id' => $venueId]);
            }
        }

        foreach (['city_id', 'country_id', 'location_id', 'listing_id', 'booking_id'] as $column) {
            DB::table('hosted_games')->whereRaw("`$column` NOT REGEXP '^[0-9]+$'")->update([$column => null]);
        }

        Schema::table('hosted_games', function (Blueprint $table) {
            $table->unsignedBigInteger('city_id')->nullable()->change();
            $table->unsignedBigInteger('country_id')->nullable()->change();
            $table->unsignedBigInteger('location_id')->nullable()->change();
            $table->unsignedBigInteger('listing_id')->nullable()->change();
            $table->unsignedBigInteger('booking_id')->nullable()->change();
        });
    }

    public function down(): void
    {
        Schema::table('hosted_games', function (Blueprint $table) {
            $table->uuid('city_id')->nullable()->change();
            $table->uuid('country_id')->nullable()->change();
            $table->uuid('location_id')->nullable()->change();
            $table->uuid('listing_id')->nullable()->change();
            $table->uuid('booking_id')->nullable()->change();
        });

        foreach (self::CITIES as $uuid => [$name, $iso]) {
            $countryId = DB::table('countries')->where('iso2', $iso)->value('id');
            $cityId    = $countryId ? DB::table('cities')->where('country_id', $countryId)->where('name', $name)->value('id') : null;

            if ($cityId) {
                DB::table('hosted_games')->where('city_id', $cityId)->update(['city_id' => $uuid]);
            }
        }

        foreach (self::COUNTRIES as $uuid => $iso) {
            $countryId = DB::table('countries')->where('iso2', $iso)->value('id');

            if ($countryId) {
                DB::table('hosted_games')->where('country_id', $countryId)->update(['country_id' => $uuid]);
            }
        }
    }
};
