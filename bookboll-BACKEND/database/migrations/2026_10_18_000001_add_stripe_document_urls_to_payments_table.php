<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Stripe's own hosted invoice / receipt page for a payment, so Payments →
 * Transactions can hand staff a printable document instead of building one.
 *
 * These are stored rather than fetched per row: a dashboard page lists up to a
 * hundred payments, and resolving each one against the Stripe API on every read
 * would turn one request into a hundred. They are written where the Stripe
 * object is already in hand (invoice webhook, charge confirmation) and can be
 * refilled for historical rows with `payments:backfill-stripe-urls`.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('payments', function (Blueprint $table) {
            if (! Schema::hasColumn('payments', 'stripe_invoice_url')) {
                $table->string('stripe_invoice_url', 2048)->nullable()->after('charge_id');
            }
            if (! Schema::hasColumn('payments', 'stripe_receipt_url')) {
                $table->string('stripe_receipt_url', 2048)->nullable()->after('stripe_invoice_url');
            }
        });
    }

    public function down(): void
    {
        Schema::table('payments', function (Blueprint $table) {
            foreach (['stripe_invoice_url', 'stripe_receipt_url'] as $col) {
                if (Schema::hasColumn('payments', $col)) {
                    $table->dropColumn($col);
                }
            }
        });
    }
};
