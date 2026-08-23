<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Ensure venue_hour_periods matches Postgres location_hours intent
 * (one open/close window per venue per weekday). Idempotent.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('venue_hour_periods')) {
            Schema::create('venue_hour_periods', function (Blueprint $table) {
                $table->id();
                $table->foreignId('venue_id')->constrained('venues')->cascadeOnDelete();
                $table->string('day', 12);
                $table->boolean('is_open')->default(true);
                $table->time('open_time')->nullable();
                $table->time('close_time')->nullable();
                $table->timestamps();

                $table->unique(['venue_id', 'day'], 'venue_hour_periods_venue_id_day_unique');
            });

            return;
        }

        Schema::table('venue_hour_periods', function (Blueprint $table) {
            if (! Schema::hasColumn('venue_hour_periods', 'venue_id')) {
                $table->unsignedBigInteger('venue_id')->nullable()->index();
            }
            if (! Schema::hasColumn('venue_hour_periods', 'day')) {
                $table->string('day', 12)->nullable();
            }
            if (! Schema::hasColumn('venue_hour_periods', 'is_open')) {
                $table->boolean('is_open')->default(true);
            }
            if (! Schema::hasColumn('venue_hour_periods', 'open_time')) {
                $table->time('open_time')->nullable();
            }
            if (! Schema::hasColumn('venue_hour_periods', 'close_time')) {
                $table->time('close_time')->nullable();
            }
        });

        try {
            Schema::table('venue_hour_periods', function (Blueprint $table) {
                $table->unique(['venue_id', 'day'], 'venue_hour_periods_venue_id_day_unique');
            });
        } catch (\Throwable $e) {
            // Index already exists or duplicate (venue_id, day) rows prevent creation.
        }
    }

    public function down(): void
    {
        // Do not drop the table — it may predate this migration and hold data.
        if (! Schema::hasTable('venue_hour_periods')) {
            return;
        }

        try {
            Schema::table('venue_hour_periods', function (Blueprint $table) {
                $table->dropUnique('venue_hour_periods_venue_id_day_unique');
            });
        } catch (\Throwable $e) {
            // Ignore if index was never added by this migration.
        }
    }
};
