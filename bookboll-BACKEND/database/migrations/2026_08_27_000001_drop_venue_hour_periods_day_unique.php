<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * WeeklyHours sends multiple windows per weekday (incl. overnight split).
 * Unique (venue_id, day) must not exist — 000004 may have been recorded
 * without actually dropping the index (dump re-import / swallowed exception).
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
            // Ignore if the foreign key is not present.
        }

        if (Schema::hasIndex('venue_hour_periods', 'venue_hour_periods_venue_id_day_index')) {
            Schema::table('venue_hour_periods', function (Blueprint $table) {
                $table->dropIndex('venue_hour_periods_venue_id_day_index');
            });
        }

        if (! Schema::hasIndex('venue_hour_periods', 'venue_hour_periods_venue_id_day_unique')) {
            Schema::table('venue_hour_periods', function (Blueprint $table) {
                $table->unique(['venue_id', 'day'], 'venue_hour_periods_venue_id_day_unique');
            });
        }
    }
};
