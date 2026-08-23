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
                $table->dropUnique('venue_hour_periods_venue_id_day_unique');
            });
        } catch (\Throwable $e) {
            // Unique may never have been created (duplicates blocked it earlier).
        }

        try {
            Schema::table('venue_hour_periods', function (Blueprint $table) {
                $table->index(['venue_id', 'day'], 'venue_hour_periods_venue_id_day_index');
            });
        } catch (\Throwable $e) {
            // Index already exists.
        }
    }

    public function down(): void
    {
        if (! Schema::hasTable('venue_hour_periods')) {
            return;
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
