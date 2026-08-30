<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Allow multiple open windows per weekday (Postgres location_hours / LocationModal).
 * Drops unique (venue_id, day) if present; keeps a non-unique composite index.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('venue_hour_periods')) {
            return;
        }

        try {
            Schema::table('venue_hour_periods', function (Blueprint $table) {
                $table->dropForeign(['venue_id']);
            });
        } catch (\Throwable $e) {
            // Foreign key may already be absent.
        }

        if (Schema::hasIndex('venue_hour_periods', 'venue_hour_periods_venue_id_day_unique')) {
            Schema::table('venue_hour_periods', function (Blueprint $table) {
                $table->dropUnique('venue_hour_periods_venue_id_day_unique');
            });
        }

        if (! Schema::hasIndex('venue_hour_periods', 'venue_hour_periods_venue_id_day_index')) {
            Schema::table('venue_hour_periods', function (Blueprint $table) {
                $table->index(['venue_id', 'day'], 'venue_hour_periods_venue_id_day_index');
            });
        }
    }

    public function down(): void
    {
        if (! Schema::hasTable('venue_hour_periods')) {
            return;
        }

        try {
            Schema::table('venue_hour_periods', function (Blueprint $table) {
                $table->dropForeign(['venue_id']);
            });
        } catch (\Throwable $e) {
            // ignore if the foreign key is not present
        }

        try {
            Schema::table('venue_hour_periods', function (Blueprint $table) {
                $table->dropIndex('venue_hour_periods_venue_id_day_index');
            });
        } catch (\Throwable $e) {
            // ignore
        }

        try {
            Schema::table('venue_hour_periods', function (Blueprint $table) {
                $table->unique(['venue_id', 'day'], 'venue_hour_periods_venue_id_day_unique');
            });
        } catch (\Throwable $e) {
            // ignore if duplicates prevent re-adding unique
        }
    }
};
