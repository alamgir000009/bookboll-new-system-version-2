<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Which countries a host may open a Stripe payout account in.
 *
 * This is NOT the same as "countries Stripe operates in". It depends on Bookboll's
 * own Stripe account: a US platform is refused outright in Pakistan, Qatar and
 * South Africa, even though Stripe lists all three. Offering them anyway means the
 * host fills in the form and only then hits "You cannot request the `card_payments`
 * capability for accounts in ZA".
 *
 * Keeping the answer on the country row means every environment agrees without
 * anyone remembering to run a command, and it stays visible and editable.
 *
 * The seed below is what Stripe itself accepted when asked — each country tried
 * with a throwaway Express account, in September 2026, against a US platform.
 * `php artisan stripe:sync-connect-countries --apply` re-verifies it, which is
 * what to run if Stripe approves the platform for more countries (or after a
 * `world:install` re-seed, which would clear these flags).
 */
return new class extends Migration
{
    /** Verified against Stripe, 2026-09-16, US platform account. */
    private const SUPPORTED = [
        'AE', 'AT', 'AU', 'BE', 'BG', 'CA', 'CH', 'CY', 'CZ', 'DE', 'DK', 'EE', 'ES', 'FI',
        'FR', 'GB', 'GI', 'GR', 'HK', 'HR', 'HU', 'IE', 'IT', 'JP', 'LI', 'LT', 'LU', 'LV',
        'MT', 'MX', 'MY', 'NL', 'NO', 'NZ', 'PL', 'PT', 'RO', 'SE', 'SG', 'SI', 'SK', 'TH',
        'US', 'UY',
    ];

    public function up(): void
    {
        Schema::table('countries', function (Blueprint $table) {
            $table->boolean('stripe_connect_supported')->default(false)->after('status')->index();
            $table->timestamp('stripe_connect_checked_at')->nullable()->after('stripe_connect_supported');
        });

        DB::table('countries')
            ->whereIn('iso2', self::SUPPORTED)
            ->update([
                'stripe_connect_supported'  => true,
                'stripe_connect_checked_at' => now(),
            ]);
    }

    public function down(): void
    {
        Schema::table('countries', function (Blueprint $table) {
            $table->dropIndex(['stripe_connect_supported']);
            $table->dropColumn(['stripe_connect_supported', 'stripe_connect_checked_at']);
        });
    }
};
