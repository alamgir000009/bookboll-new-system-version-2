<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Who actually runs a venue, said explicitly.
 *
 * It was inferred from a null owner_user_id: no owner meant Bookboll ran it.
 * That is a guess, not a fact — a venue can lose its owner for several
 * reasons — and readmeAlamgir is blunt that "nullable owner IDs must never
 * grant Bookboll payout/payment exemptions". Operating agreements only exist
 * for venues Bookboll operates, so the question has to have a real answer
 * before they can be attached to one.
 *
 * Backfilled from the existing inference so nothing changes today: venues with
 * no owner become 'bookboll', the rest 'provider'. From here it is recorded
 * rather than deduced.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('venues', function (Blueprint $table) {
            if (! Schema::hasColumn('venues', 'operator_type')) {
                $table->enum('operator_type', ['bookboll', 'provider'])->nullable()->index();
            }
            if (! Schema::hasColumn('venues', 'business_id')) {
                // The operating business, independent of any owner account.
                $table->unsignedBigInteger('business_id')->nullable()->index();
            }
        });

        // Only where it is still unknown, so a re-run cannot relabel a venue
        // somebody has since corrected by hand.
        DB::table('venues')->whereNull('operator_type')->update([
            'operator_type' => DB::raw("CASE WHEN owner_user_id IS NULL THEN 'bookboll' ELSE 'provider' END"),
        ]);
    }

    public function down(): void
    {
        Schema::table('venues', function (Blueprint $table) {
            foreach (['operator_type', 'business_id'] as $c) {
                if (Schema::hasColumn('venues', $c)) {
                    $table->dropColumn($c);
                }
            }
        });
    }
};
