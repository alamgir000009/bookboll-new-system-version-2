<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Which rewards quote paid for part of this booking.
 *
 * Payment can redirect: the points are reserved when checkout starts and only
 * consumed once Stripe confirms. Without a link from the booking back to its
 * quote, the confirmation has no way to find the reservation to spend — and a
 * later refund has no way to find what to give back.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('bookings', function (Blueprint $table) {
            if (! Schema::hasColumn('bookings', 'loyalty_quote_id')) {
                $table->string('loyalty_quote_id', 80)->nullable()->index();
            }
            if (! Schema::hasColumn('bookings', 'loyalty_discount')) {
                // What the points actually took off, kept with the booking so a
                // confirmed order still reports it after the quote expires.
                $table->decimal('loyalty_discount', 15, 2)->nullable();
            }
        });
    }

    public function down(): void
    {
        Schema::table('bookings', function (Blueprint $table) {
            foreach (['loyalty_quote_id', 'loyalty_discount'] as $c) {
                if (Schema::hasColumn('bookings', $c)) {
                    $table->dropColumn($c);
                }
            }
        });
    }
};
