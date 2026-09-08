<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A provider's Bookboll for Business PREMIUM subscription.
 *
 * Its own table rather than a dozen more columns on `businesses`: a subscription is
 * an entity with a lifecycle, not an attribute of a business. A provider may start
 * one, cancel it, and come back a year later on a different billing cycle — that is
 * three rows of history here, and would be one row of overwritten columns there.
 *
 * `businesses.plan` stays as the cached entitlement flag: it is read on the
 * storefront's hot path (BrandResolutionService, on every white-label page load) and
 * on every gated API call, so it must not cost a join. It is derived from this table
 * and written in exactly one place — ProviderPlanService::applyEntitlement().
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('business_subscriptions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('business_id')->constrained('businesses')->cascadeOnDelete();

            $table->string('plan', 32)->default('premium');       // room for further tiers
            $table->string('billing', 16);                        // monthly | yearly
            // incomplete → the checkout was opened but Stripe has not confirmed it.
            $table->string('status', 32)->default('incomplete');  // incomplete|trialing|active|past_due|canceled
            $table->decimal('amount', 10, 2)->default(0);         // billed per period, before any promo
            $table->string('currency', 3)->default('USD');

            $table->timestamp('trial_ends_at')->nullable();
            $table->timestamp('current_period_end')->nullable();
            $table->boolean('cancel_at_period_end')->default(false);
            $table->timestamp('started_at')->nullable();
            $table->timestamp('ended_at')->nullable();

            $table->string('stripe_subscription_id', 191)->nullable()->unique();
            $table->string('stripe_customer_id', 191)->nullable();
            $table->string('stripe_checkout_session_id', 191)->nullable();
            $table->unsignedBigInteger('promocode_id')->nullable();
            $table->decimal('discount_amount', 10, 2)->default(0);

            $table->timestamps();

            // "the current subscription for this business" is the query that runs on
            // every plan read, and the checkout id is how a browser return finds its row.
            $table->index(['business_id', 'status']);
            $table->index('stripe_checkout_session_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('business_subscriptions');
    }
};
