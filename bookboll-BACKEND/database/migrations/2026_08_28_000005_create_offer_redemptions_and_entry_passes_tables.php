<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Customer membership support tables (Supabase offer_redemptions + entry_passes parity).
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('offer_redemptions')) {
            Schema::create('offer_redemptions', function (Blueprint $table) {
                $table->id();
                $table->uuid('uuid')->unique();
                $table->string('customer_email')->nullable()->index();
                $table->string('card_fingerprint', 128)->nullable()->index();
                $table->uuid('owner_user_id')->index();
                $table->uuid('program_id')->nullable()->index();
                $table->uuid('subscription_id')->nullable()->index();
                $table->string('offer_type', 32)->default('trial');
                $table->string('source', 64)->nullable();
                $table->timestamps();
                $table->index(['owner_user_id', 'customer_email'], 'or_owner_email');
                $table->index(['owner_user_id', 'card_fingerprint'], 'or_owner_card');
            });
        }

        if (! Schema::hasTable('entry_passes')) {
            Schema::create('entry_passes', function (Blueprint $table) {
                $table->id();
                $table->uuid('uuid')->unique();
                $table->string('kind', 32); // member | membership
                $table->string('ref_id'); // customer uuid or subscription uuid
                $table->uuid('owner_user_id')->nullable()->index();
                $table->uuid('customer_id')->nullable()->index();
                $table->string('customer_email')->nullable();
                $table->string('code', 16)->unique();
                $table->timestamps();
                $table->unique(['kind', 'ref_id'], 'entry_passes_kind_ref_unique');
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('entry_passes');
        Schema::dropIfExists('offer_redemptions');
    }
};
