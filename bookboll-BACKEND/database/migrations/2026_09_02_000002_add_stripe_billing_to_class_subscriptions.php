<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Stripe-backed membership billing. Subscriptions created before this migration
 * have no Stripe subscription behind them and are left alone — every billing
 * action keys off stripe_subscription_id.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('class_subscriptions', function (Blueprint $table) {
            $table->string('stripe_subscription_id')->nullable()->unique()->after('card_on_file_id');
            $table->string('stripe_customer_id')->nullable()->after('stripe_subscription_id');
            $table->string('stripe_checkout_session_id')->nullable()->index()->after('stripe_customer_id');
            $table->string('stripe_status', 32)->nullable()->after('stripe_checkout_session_id');
            $table->timestamp('current_period_end')->nullable()->after('stripe_status');
            $table->decimal('loyalty_redeem', 14, 2)->default(0)->after('current_period_end');
            $table->decimal('discount_amount', 14, 2)->default(0)->after('loyalty_redeem');
        });
    }

    public function down(): void
    {
        Schema::table('class_subscriptions', function (Blueprint $table) {
            $table->dropColumn([
                'stripe_subscription_id',
                'stripe_customer_id',
                'stripe_checkout_session_id',
                'stripe_status',
                'current_period_end',
                'loyalty_redeem',
                'discount_amount',
            ]);
        });
    }
};
