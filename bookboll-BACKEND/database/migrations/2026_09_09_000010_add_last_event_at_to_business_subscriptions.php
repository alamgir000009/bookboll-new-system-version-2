<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * When the newest Stripe event we have applied to this subscription was GENERATED.
 *
 * Stripe does not guarantee webhook delivery order, and retries make reordering more
 * likely over time. Without this, a `customer.subscription.updated` generated before a
 * cancellation but delivered after it would flip a cancelled business back to Premium
 * — free, and indefinitely. Comparing generation time makes a stale event a no-op.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('business_subscriptions', function (Blueprint $table) {
            $table->timestamp('last_event_at')->nullable()->after('stripe_checkout_session_id');
        });
    }

    public function down(): void
    {
        Schema::table('business_subscriptions', function (Blueprint $table) {
            $table->dropColumn('last_event_at');
        });
    }
};
