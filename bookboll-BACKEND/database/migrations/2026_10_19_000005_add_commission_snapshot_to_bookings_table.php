<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * What Bookboll charged for this booking, recorded at the moment it was made.
 *
 * Two things were impossible without it.
 *
 * The channel never survived the request: `surface` was validated and then
 * dropped, so by the time the wallet was credited nothing knew whether the
 * booking came from the marketplace or the merchant's own site. The ledger fell
 * back to the marketplace rate, which overcharges a paid Premium merchant whose
 * own-site rate is halved.
 *
 * And a rate resolved live is the right answer for a NEW booking and the wrong
 * one for an old receipt. A merchant upgrading, cancelling or a country's rate
 * changing would silently rewrite what a confirmed booking had charged.
 * readmeAlamgir: "Preserve historical amounts when plans or commission settings
 * change" and "do not recalculate historical fees using a merchant's later plan."
 *
 * Minor units, so a fractional rate like 7.5% is stored as the money it was,
 * not re-derived through floating point later.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('bookings', function (Blueprint $table) {
            if (! Schema::hasColumn('bookings', 'commission_channel')) {
                $table->enum('commission_channel', ['marketplace', 'site'])->nullable()->index();
            }
            if (! Schema::hasColumn('bookings', 'commission_standard_pct')) {
                // 6,3 so 7.500 and 33.333 both survive intact.
                $table->decimal('commission_standard_pct', 6, 3)->nullable();
            }
            if (! Schema::hasColumn('bookings', 'commission_effective_pct')) {
                $table->decimal('commission_effective_pct', 6, 3)->nullable();
            }
            if (! Schema::hasColumn('bookings', 'commission_plan_eligible')) {
                // Null = not recorded (a booking predating this), which is not
                // the same as false.
                $table->boolean('commission_plan_eligible')->nullable();
            }
            if (! Schema::hasColumn('bookings', 'commission_fee_minor')) {
                $table->unsignedBigInteger('commission_fee_minor')->nullable();
            }
            if (! Schema::hasColumn('bookings', 'merchant_share_minor')) {
                $table->unsignedBigInteger('merchant_share_minor')->nullable();
            }
            if (! Schema::hasColumn('bookings', 'commission_currency')) {
                $table->string('commission_currency', 10)->nullable();
            }
        });
    }

    public function down(): void
    {
        Schema::table('bookings', function (Blueprint $table) {
            foreach ([
                'commission_channel', 'commission_standard_pct', 'commission_effective_pct',
                'commission_plan_eligible', 'commission_fee_minor', 'merchant_share_minor',
                'commission_currency',
            ] as $col) {
                if (Schema::hasColumn('bookings', $col)) {
                    $table->dropColumn($col);
                }
            }
        });
    }
};
