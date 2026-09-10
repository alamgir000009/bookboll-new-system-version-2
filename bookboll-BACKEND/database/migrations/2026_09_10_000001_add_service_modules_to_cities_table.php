<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Per-city service modules — the Laravel twin of the Supabase design's
 * 20260530100000_city_service_modules.sql.
 *
 *   module_listings    → court / space booking listings
 *   module_classes     → academy classes & subscriptions
 *   module_matchmaking → player matchmaking (host/join events)
 *
 * A super admin disables a service for a city and the matching creation flows
 * are refused there. All modules are ON by default, and NULL reads as ON, so
 * existing rows and any city seeded later behave exactly as before.
 *
 * The dashboard already carried the guards for these (Listings.vue and
 * ClassesSubscriptions.vue both test `city.module_* === false`); without the
 * columns those tests read `undefined` and could never fire.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('cities', function (Blueprint $table) {
            foreach (['module_listings', 'module_classes', 'module_matchmaking'] as $col) {
                if (! Schema::hasColumn('cities', $col)) {
                    $table->boolean($col)->default(true);
                }
            }
        });
    }

    public function down(): void
    {
        Schema::table('cities', function (Blueprint $table) {
            foreach (['module_listings', 'module_classes', 'module_matchmaking'] as $col) {
                if (Schema::hasColumn('cities', $col)) {
                    $table->dropColumn($col);
                }
            }
        });
    }
};
