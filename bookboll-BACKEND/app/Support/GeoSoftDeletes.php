<?php

namespace App\Support;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Events\MigrationsEnded;
use Illuminate\Database\Events\MigrationsStarted;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Schema;
use Nnjeim\World\Models\City;
use Nnjeim\World\Models\Country;
use Nnjeim\World\Models\State;

/**
 * Hide soft-deleted geography rows from every query, including the ones this app
 * cannot change.
 *
 * Swapping thirty files over to App\Models\City would have been the idiomatic route,
 * and also the fragile one: a single missed import silently puts deleted cities back
 * in the picker, and the nnjeim/world package registers its OWN routes
 * (config/world.php `'routes' => true`) against its own models, which no amount of
 * editing this app can reach. A global scope on the vendor model covers all of it at
 * once. App\Models\City still exists for the write side — delete, restore, withTrashed
 * — where the SoftDeletes trait's own scope does the same job.
 *
 * Escape hatch: ->withoutGlobalScope(GeoSoftDeletes::SCOPE) on any query that genuinely
 * wants the trashed rows through the vendor model.
 */
final class GeoSoftDeletes
{
    public const SCOPE = 'geo_not_trashed';

    private const TABLES = [City::class => 'cities', Country::class => 'countries', State::class => 'states'];

    /** Tables confirmed to carry deleted_at. Only ever set to true — see columnExists(). */
    private static array $ready = [];

    private static bool $suspended = false;

    public static function register(): void
    {
        foreach (self::TABLES as $model => $table) {
            $model::addGlobalScope(self::SCOPE, function (Builder $query) use ($table) {
                if (self::$suspended || ! self::columnExists($table)) {
                    return;
                }

                $query->whereNull("{$table}.deleted_at");
            });
        }

        // Migrations run against the schema as it is mid-flight: countries is queried
        // by 2026_05_30_000001 long before this feature's own migration adds
        // deleted_at, and a fresh database rebuilds the table underneath us. Applying
        // a domain scope to those queries can only break them.
        Event::listen(MigrationsStarted::class, fn () => self::$suspended = true);
        Event::listen(MigrationsEnded::class, fn () => self::$suspended = false);
    }

    /**
     * Checked lazily, per query, rather than once at boot.
     *
     * Deploying this code before running the migration then degrades to "no soft
     * deletes yet" instead of a 500 on every request, and the scope starts working the
     * moment the migration lands without a restart. Only a TRUE answer is remembered:
     * caching a "no" would leave the scope off for the rest of the process and quietly
     * hand back deleted rows.
     */
    private static function columnExists(string $table): bool
    {
        if (! empty(self::$ready[$table])) {
            return true;
        }

        try {
            $exists = Schema::hasColumn($table, 'deleted_at');
        } catch (\Throwable) {
            // No database yet (a fresh container, artisan before migrate).
            return false;
        }

        return $exists ? self::$ready[$table] = true : false;
    }
}
