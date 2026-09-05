<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * How an event host gets paid when their tickets are sold online.
 *
 * Two ways, one row per host:
 *   • connect — Bookboll creates a Stripe Express account for them and Stripe hosts
 *     the onboarding. Joiners are charged on Bookboll's account with the money
 *     transferred to theirs, so Bookboll's commission is taken automatically.
 *   • keys — the host supplies their own Stripe keys and joiners are charged
 *     directly on their account. The secret key is encrypted at rest.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('host_payout_accounts')) {
            return;
        }

        Schema::create('host_payout_accounts', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('user_id')->unique()->constrained()->cascadeOnDelete();

            $table->string('mode', 16);                  // 'connect' | 'keys'
            $table->string('status', 16)->default('pending'); // 'pending' | 'active' | 'disabled'

            // Stripe's id for the account money lands in, whichever mode is used.
            $table->string('stripe_account_id')->nullable()->index();
            $table->string('account_name')->nullable();
            $table->string('country', 8)->nullable();
            $table->boolean('livemode')->default(false);

            // Reported by Stripe — a connected account can exist long before it is
            // allowed to take money, so these decide whether tickets may be sold.
            $table->boolean('charges_enabled')->default(false);
            $table->boolean('payouts_enabled')->default(false);
            $table->boolean('details_submitted')->default(false);

            // 'keys' mode only. The secret is encrypted by the model's cast.
            $table->text('secret_key')->nullable();
            $table->string('publishable_key')->nullable();

            $table->timestamp('connected_at')->nullable();
            $table->timestamp('synced_at')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('host_payout_accounts');
    }
};
