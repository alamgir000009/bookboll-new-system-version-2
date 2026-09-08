<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The one-time fee a provider pays to connect their own web address.
 *
 * Kept apart from `businesses` for the same reason as the subscription: this is a
 * purchase, with its own outcome and its own history. A provider may pay for
 * book.myclub.com this year and clubshop.com the next, and support needs to see both
 * — which domain, how much, which Stripe session, whether it actually completed.
 *
 * The LIVE domain configuration (brand_custom_domain / brand_domain_status /
 * brand_domain_paid_at) stays on `businesses`: that is what the storefront reads to
 * serve the site, and it must not cost a join.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('business_domain_purchases', function (Blueprint $table) {
            $table->id();
            $table->foreignId('business_id')->constrained('businesses')->cascadeOnDelete();

            $table->string('domain', 191);
            $table->decimal('amount', 10, 2);
            $table->string('currency', 3)->default('USD');
            $table->string('status', 16)->default('pending');   // pending | paid | expired

            $table->string('stripe_checkout_session_id', 191)->nullable()->unique();
            $table->string('stripe_payment_intent_id', 191)->nullable();
            $table->timestamp('paid_at')->nullable();

            $table->timestamps();

            $table->index(['business_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('business_domain_purchases');
    }
};
