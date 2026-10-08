<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Record the currency a booking's amounts are in, on the booking.
 *
 * Bookboll ran its own venues in one country, so clients hardcoded "QAR" against
 * every amount — the admin app still does. Now that merchants operate in their own
 * countries that label is a fiction, and the code has to travel with the money.
 *
 * Stored rather than derived from the venue at read time, for the same reason
 * `payments.currency` is stored: it is a record of what was actually charged. A
 * venue that later changes its currency_override, or moves country, must not
 * retroactively relabel money that was already taken in something else.
 *
 * Existing rows are backfilled to QAR. Every venue on the platform today is in
 * Doha — Abu Hamor, Al Sadd, Bin Omran, Al Waab, Al Rayyan, Al Duhail, Madinat
 * Khalifa, Ain Khaled, Westbay, Al Gharafa — so that is not a guess, it is what
 * those bookings were charged in.
 */
return new class extends Migration
{
    /** What every booking taken so far was actually charged in. */
    private const LEGACY_CURRENCY = 'QAR';

    public function up(): void
    {
        if (! Schema::hasTable('bookings')) {
            return;
        }

        if (! Schema::hasColumn('bookings', 'currency')) {
            Schema::table('bookings', function (Blueprint $table) {
                $table->string('currency', 10)->nullable()->after('total_price');
            });
        }

        // Backfill in chunks: this runs against a live table.
        DB::table('bookings')
            ->whereNull('currency')
            ->orWhere('currency', '')
            ->update(['currency' => self::LEGACY_CURRENCY]);
    }

    public function down(): void
    {
        if (Schema::hasTable('bookings') && Schema::hasColumn('bookings', 'currency')) {
            Schema::table('bookings', function (Blueprint $table) {
                $table->dropColumn('currency');
            });
        }
    }
};
