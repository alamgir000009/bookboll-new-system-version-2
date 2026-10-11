<?php

namespace App\Console\Commands;

use App\Models\City as SoftDeletableCity;
use App\Models\Country as SoftDeletableCountry;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Take a country's cities out of circulation, keeping the ones named.
 *
 * Qatar is the case this was written for: fifteen cities in the picker, every single
 * venue in Doha, and a customer scrolling a list of fourteen places that have nothing
 * in them. The rest can go.
 *
 * It refuses by default if anything still points at a city it is about to retire. That
 * check is not a fixed list of tables — it reads information_schema for every column
 * named city_id in the schema, so a table added next year is audited too. The whole
 * risk here is the table nobody remembered; hardcoding the ones we know would bake
 * that risk in.
 *
 * Retiring is a soft delete, so it is reversible with --restore.
 */
class RetireCities extends Command
{
    protected $signature = 'geo:retire-cities
        {country : ISO2 code or country name, e.g. QA}
        {--keep=* : City names to leave active (repeat the flag)}
        {--dry-run : Report what would happen and change nothing}
        {--force : Retire even though rows still reference these cities}
        {--restore : Put this country\'s retired cities back instead}';

    protected $description = "Soft-delete a country's cities, keeping the ones you name";

    public function handle(): int
    {
        $country = $this->resolveCountry($this->argument('country'));

        if (! $country) {
            $this->error('No country matches "'.$this->argument('country').'".');

            return self::FAILURE;
        }

        $this->line("Country: <info>{$country->name}</info> (id {$country->id}, {$country->iso2})");

        return $this->option('restore')
            ? $this->restore($country)
            : $this->retire($country);
    }

    private function retire($country): int
    {
        $keep = array_map('mb_strtolower', (array) $this->option('keep'));

        $cities = SoftDeletableCity::where('country_id', $country->id)->orderBy('name')->get();

        if ($cities->isEmpty()) {
            $this->warn('No active cities to retire.');

            return self::SUCCESS;
        }

        $target = $cities->reject(fn ($c) => in_array(mb_strtolower($c->name), $keep, true));
        $kept = $cities->diff($target);

        $this->line('Keeping  : '.($kept->isEmpty() ? '(none)' : $kept->pluck('name')->implode(', ')));
        $this->line('Retiring : '.$target->count().' of '.$cities->count().' cities');

        if ($target->isEmpty()) {
            return self::SUCCESS;
        }

        if ($kept->isEmpty() && ! $this->option('force')) {
            $this->error('That would retire EVERY city in the country. Name one to --keep, or pass --force.');

            return self::FAILURE;
        }

        $references = $this->auditReferences($target->pluck('id')->all());

        if ($references !== []) {
            $this->newLine();
            $this->error('These rows still point at cities you are retiring:');
            foreach ($references as $where => $count) {
                $this->line("  {$where}: {$count}");
            }
            $this->newLine();

            if (! $this->option('force')) {
                $this->warn('Nothing changed. Move them to a city you are keeping first, or pass --force.');

                return self::FAILURE;
            }

            $this->warn('--force given: retiring anyway. Those rows will point at a retired city.');
        } else {
            $this->info('Nothing references them.');
        }

        if ($this->option('dry-run')) {
            $this->newLine();
            $this->comment('--dry-run: nothing was changed.');

            return self::SUCCESS;
        }

        $retired = SoftDeletableCity::whereIn('id', $target->pluck('id'))->delete();

        $this->newLine();
        $this->info("Retired {$retired} cities. Reverse with: php artisan geo:retire-cities {$country->iso2} --restore");

        return self::SUCCESS;
    }

    private function restore($country): int
    {
        $count = SoftDeletableCity::onlyTrashed()->where('country_id', $country->id)->restore();

        $this->info("Restored {$count} cities.");

        return self::SUCCESS;
    }

    /**
     * Every row in the schema pointing at one of these city ids.
     *
     * @param  list<int>  $cityIds
     * @return array<string, int>  "table.column" => row count, only where non-zero
     */
    private function auditReferences(array $cityIds): array
    {
        if ($cityIds === []) {
            return [];
        }

        $columns = DB::select(
            'SELECT TABLE_NAME AS t, COLUMN_NAME AS c
               FROM information_schema.COLUMNS
              WHERE TABLE_SCHEMA = ? AND COLUMN_NAME = ? AND TABLE_NAME <> ?
              ORDER BY TABLE_NAME',
            [DB::getDatabaseName(), 'city_id', 'cities']
        );

        $found = [];

        foreach ($columns as $column) {
            $count = DB::table($column->t)->whereIn($column->c, $cityIds)->count();
            if ($count > 0) {
                $found["{$column->t}.{$column->c}"] = $count;
            }
        }

        return $found;
    }

    private function resolveCountry(string $needle)
    {
        return SoftDeletableCountry::where('iso2', mb_strtoupper($needle))
            ->orWhere('name', $needle)
            ->first();
    }
}
