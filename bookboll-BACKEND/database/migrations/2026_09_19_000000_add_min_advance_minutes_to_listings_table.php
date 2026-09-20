<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Minimum lead time before a slot may be booked, in minutes. Zero, the
     * default, means a slot can be booked right up to its start.
     */
    public function up(): void
    {
        Schema::table('listings', function (Blueprint $table) {
            $table->unsignedInteger('min_advance_minutes')->default(0)->after('slot_duration');
        });
    }

    public function down(): void
    {
        Schema::table('listings', function (Blueprint $table) {
            $table->dropColumn('min_advance_minutes');
        });
    }
};
