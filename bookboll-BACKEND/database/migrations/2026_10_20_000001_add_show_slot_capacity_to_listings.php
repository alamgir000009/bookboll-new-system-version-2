<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Whether a slot shows how many spaces are left.
 *
 * Purely presentational: the capacity is still enforced and a full slot still
 * stops selling. Some venues would rather not advertise a quiet hour.
 *
 * Defaults to true so nothing changes for listings that already exist.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('listings', function (Blueprint $table) {
            $table->boolean('show_slot_capacity')->default(true)->after('max_bookings_per_slot');
        });
    }

    public function down(): void
    {
        Schema::table('listings', function (Blueprint $table) {
            $table->dropColumn('show_slot_capacity');
        });
    }
};
