<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Schema;
use Nnjeim\World\Models\City;
use Nnjeim\World\Models\Country;

/**
 * Which switched-on countries have no city anyone can pick.
 *
 * A country enabled for the marketplace with no active cities is a dead end:
 * the location picker offers it, asks for a city, and has nothing to show. That
 * is what happened with Pitcairn Island. The public countries response now
 * reports `cities_count` so the picker can say so honestly, but the underlying
 * fix is to seed the intended cities or switch the country off — this is how
 * you find which ones need it.
 *
 * Read-only: it changes nothing.
 */
class AuditCityCoverage extends Command
{
    protected $signature = 'geo:audit-city-coverage {--all : List every active country, not only the empty ones}';

    protected $description = 'Report active countries that have no active cities to choose from';

    public function handle(): int
    {
        $countries = Country::query()->where('status', 1)->orderBy('name')->get(['id', 'name', 'iso2']);

        if ($countries->isEmpty()) {
            $this->warn('No active countries.');

            return self::SUCCESS;
        }

        $cities = City::query()->whereIn('country_id', $countries->pluck('id'));
        if (Schema::hasColumn('cities', 'status')) {
            $cities->where('status', 1);
        }
        $counts = $cities->selectRaw('country_id, COUNT(*) as cnt')->groupBy('country_id')->pluck('cnt', 'country_id');

        $all   = (bool) $this->option('all');
        $rows  = [];
        $empty = 0;

        foreach ($countries as $country) {
            $count = (int) ($counts[$country->id] ?? 0);
            if ($count === 0) {
                $empty++;
            }
            if ($all || $count === 0) {
                $rows[] = [$country->id, $country->iso2, $country->name, $count];
            }
        }

        if ($rows) {
            $this->table(['id', 'iso', 'country', 'active cities'], $rows);
        }

        $this->info("{$empty} of {$countries->count()} active countries have no active cities.");

        if ($empty > 0) {
            $this->line('Seed the intended cities for these, or set status = 0 so the picker stops offering them.');
        }

        return self::SUCCESS;
    }
}
