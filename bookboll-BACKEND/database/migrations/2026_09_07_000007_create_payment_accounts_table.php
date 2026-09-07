<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A provider's bank account for receiving online payments (Postgres:
 * public.payment_accounts).
 *
 * This predates the wallet/payout system and is still shown on the Listings page
 * as the "connected payment account" for a venue. Wallets are the modern rail;
 * this stays because the dashboard reads and edits it.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('payment_accounts')) {
            return;
        }

        Schema::create('payment_accounts', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('owner_user_id')->constrained('users')->cascadeOnDelete();
            $table->string('account_holder')->nullable();
            $table->string('bank_name')->nullable();
            $table->string('iban')->nullable();
            $table->string('swift')->nullable();
            $table->string('currency', 10)->default('AED');
            $table->boolean('is_primary')->default(true);
            $table->boolean('verified')->default(false);
            $table->timestamps();

            $table->index('owner_user_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('payment_accounts');
    }
};
