<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A server-computed offer of how many points this exact checkout may spend.
 *
 * Rewards are funded ENTIRELY out of Bookboll's own share — its commission and
 * its own fees. The provider's payout and the customer's arrival balance are
 * untouched, so the discount can never be larger than what Bookboll was going
 * to keep. Working that out needs the real prices, so the browser cannot be
 * trusted to compute it and is not asked to: it receives an opaque quote id.
 *
 * Stored rather than signed because the quote must be single-use and bound to
 * the account, country, currency and the normalised inputs it was priced from.
 * A quote that is expired, already spent, for another account, another country
 * or a changed order is refused at payment rather than charged.
 *
 * Issuing one spends nothing: points are reserved at payment, never here.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('loyalty_checkout_quotes', function (Blueprint $table) {
            $table->id();
            $table->string('quote_id', 80)->unique();

            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->unsignedBigInteger('country_id')->index();
            $table->string('currency', 10);
            // listing | membership | host_game | host_tournament
            $table->string('kind', 32);

            // What was offered, in minor units so a fractional commission rate
            // lands on real money rather than being re-derived through floats.
            $table->unsignedInteger('points');
            $table->unsignedBigInteger('discount_minor');
            $table->unsignedBigInteger('order_total_minor');
            $table->unsignedBigInteger('due_now_minor');
            // The parts of Bookboll's own share that funded it, kept apart so a
            // dispute can be answered without recomputing six months later.
            $table->unsignedBigInteger('commission_minor')->default(0);
            $table->unsignedBigInteger('event_fee_minor')->default(0);
            $table->unsignedBigInteger('booking_fee_minor')->default(0);

            // Fingerprint of the normalised pricing inputs. Recomputed at
            // payment: if the order changed, the quote no longer describes it.
            $table->string('inputs_hash', 64);

            $table->timestamp('expires_at')->index();
            // Reserved at payment, consumed on confirmation, released on
            // cancellation, failure or expiry — so a redirect to Stripe cannot
            // leave the same points spendable in another tab.
            $table->timestamp('reserved_at')->nullable();
            $table->timestamp('consumed_at')->nullable();
            $table->timestamp('released_at')->nullable();
            $table->string('idempotency_key', 120)->nullable()->unique();

            $table->timestamps();
            $table->index(['user_id', 'country_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('loyalty_checkout_quotes');
    }
};
