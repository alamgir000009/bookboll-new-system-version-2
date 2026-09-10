<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Per-country loyalty tiers.
 *
 * The Supabase design hangs tiers off a country's loyalty PROGRAM
 * (loyalty_tiers.program_id), so each country runs its own ladder. Laravel's
 * table was a single global list, which meant the Countries page could not
 * author tiers at all and every country shared one set.
 *
 * Rather than restructure a table other services already read, this adds the
 * missing columns:
 *   program_id  — null keeps a row as a GLOBAL default (existing rows, unchanged)
 *   bg_color    — badge background, as the dashboard renders it
 *   multiplier  — carried for parity with the Supabase schema
 *
 * `threshold` and `sort_order` map onto the existing `min_points` and `order`.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('loyalty_tiers', function (Blueprint $table) {
            if (! Schema::hasColumn('loyalty_tiers', 'program_id')) {
                $table->unsignedBigInteger('program_id')->nullable()->after('id')->index();
            }
            if (! Schema::hasColumn('loyalty_tiers', 'bg_color')) {
                $table->string('bg_color', 32)->nullable()->after('color');
            }
            if (! Schema::hasColumn('loyalty_tiers', 'multiplier')) {
                $table->decimal('multiplier', 8, 2)->default(1)->after('bg_color');
            }
        });
    }

    public function down(): void
    {
        Schema::table('loyalty_tiers', function (Blueprint $table) {
            foreach (['program_id', 'bg_color', 'multiplier'] as $col) {
                if (Schema::hasColumn('loyalty_tiers', $col)) {
                    $table->dropColumn($col);
                }
            }
        });
    }
};
