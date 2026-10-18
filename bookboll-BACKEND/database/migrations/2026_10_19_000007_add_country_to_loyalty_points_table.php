<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Which country's programme each loyalty movement belongs to.
 *
 * Loyalty rules are set per country — earn rate, redeem rate, minimum, cap,
 * expiry — and `loyalty_programs` is keyed by country_id. The ledger was not:
 * every credit and debit went into one global pile, so a customer earning in
 * Pakistan and one earning in Qatar shared a single balance that no country's
 * rules actually described. `/customer/loyalty` then returned that pile with no
 * country attached at all, which is why the storefront refuses to show it as a
 * country balance.
 *
 * Nullable on purpose. Rows written before this have no country and no way to
 * recover one — a booking's venue may since have moved, and guessing would
 * invent a balance in a country the customer never earned in. They are reported
 * as unscoped and are never folded into a country's total: readmeAlamgir is
 * explicit that "Missing or unscoped data is displayed as unavailable, not
 * zero."
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('loyalty_points', function (Blueprint $table) {
            if (! Schema::hasColumn('loyalty_points', 'country_id')) {
                $table->unsignedBigInteger('country_id')->nullable()->after('user_id');
                // The balance query is always "this customer, this country".
                $table->index(['user_id', 'country_id'], 'loyalty_points_user_country');
            }
        });
    }

    public function down(): void
    {
        Schema::table('loyalty_points', function (Blueprint $table) {
            if (Schema::hasColumn('loyalty_points', 'country_id')) {
                $table->dropIndex('loyalty_points_user_country');
                $table->dropColumn('country_id');
            }
        });
    }
};
