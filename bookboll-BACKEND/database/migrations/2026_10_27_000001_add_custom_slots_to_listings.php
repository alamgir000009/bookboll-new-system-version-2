<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Provider-defined time slots.
 *
 * `start_time_mode` gains a third value, `custom`: instead of cutting the open
 * hours into equal `slot_duration` pieces, the listing sells exactly the slots
 * listed here — each with its own start, end and weekdays, so 7:30–8:30 and
 * 9:15–10:45 can both exist on the same court.
 *
 *   [{ "start_time": "07:30", "end_time": "08:30", "days": [1, 2, 3] }, …]
 *
 * Sunday is 0. Nullable so every existing listing keeps generating slots from
 * its schedule exactly as before.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('listings', function (Blueprint $table) {
            if (! Schema::hasColumn('listings', 'custom_slots')) {
                $table->json('custom_slots')->nullable()->after('fixed_start_times');
            }
        });
    }

    public function down(): void
    {
        Schema::table('listings', function (Blueprint $table) {
            if (Schema::hasColumn('listings', 'custom_slots')) {
                $table->dropColumn('custom_slots');
            }
        });
    }
};
