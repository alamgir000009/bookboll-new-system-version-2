<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Soft deletes for the geography tables (countries / states / cities).
 *
 * These three are owned by the nnjeim/world package and carry its reference data, so
 * a row removed here is gone for good and nothing would notice: there is not a single
 * foreign key anywhere in the schema pointing at them. venues.city_id,
 * hosted_games.city_id and communities.city_id are plain integers, so a hard delete
 * leaves rows pointing at an id that no longer exists — silently, with no error at
 * write time and no way back.
 *
 * `deleted_at` makes removal reversible, which is the point: a city taken out of the
 * picker today can be put back tomorrow with the venues still attached to it.
 *
 * Note the table already has a `status` flag, honoured by the customer-facing
 * endpoints (MarketplaceGeoService::activeCitiesQuery). That is the right tool for
 * "hide this city"; `deleted_at` is for "this row should not exist", and the two are
 * deliberately independent.
 */
return new class extends Migration
{
    private const TABLES = ['countries', 'states', 'cities'];

    public function up(): void
    {
        foreach (self::TABLES as $table) {
            if (! Schema::hasTable($table) || Schema::hasColumn($table, 'deleted_at')) {
                continue;
            }

            Schema::table($table, function (Blueprint $t) use ($table) {
                $t->softDeletes();
                // Every read adds `deleted_at is null`, including the city picker and
                // the catalog resolver, so it is worth an index on tables this size
                // (cities carries ~150k rows).
                $t->index('deleted_at', "{$table}_deleted_at_index");
            });
        }
    }

    public function down(): void
    {
        foreach (self::TABLES as $table) {
            if (! Schema::hasTable($table) || ! Schema::hasColumn($table, 'deleted_at')) {
                continue;
            }

            Schema::table($table, function (Blueprint $t) use ($table) {
                $t->dropIndex("{$table}_deleted_at_index");
                $t->dropSoftDeletes();
            });
        }
    }
};
