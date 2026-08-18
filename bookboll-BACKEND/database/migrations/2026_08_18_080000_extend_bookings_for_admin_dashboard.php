<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Align bookings with the enhanced apps/admin Bookings page
 * (origin/channel, player count, optional program sessions).
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('bookings')) {
            return;
        }

        Schema::table('bookings', function (Blueprint $table) {
            if (! Schema::hasColumn('bookings', 'origin')) {
                $table->string('origin', 40)->default('bookboll_web')->index();
            }
            if (! Schema::hasColumn('bookings', 'players')) {
                $table->unsignedInteger('players')->default(1);
            }
            if (! Schema::hasColumn('bookings', 'program_id')) {
                $table->unsignedBigInteger('program_id')->nullable()->index();
            }
        });
    }

    public function down(): void
    {
        if (! Schema::hasTable('bookings')) {
            return;
        }

        Schema::table('bookings', function (Blueprint $table) {
            $cols = array_values(array_filter(
                ['origin', 'players', 'program_id'],
                fn ($c) => Schema::hasColumn('bookings', $c)
            ));
            if ($cols) {
                $table->dropColumn($cols);
            }
        });
    }
};
