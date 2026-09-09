<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Every email we actually dispatch — the sibling of whatsapp_outbound.
 *
 * One row per RECIPIENT, so "did Jane get the campaign?" has an answer, and so a
 * failed address is visible instead of silently swallowed. The message body is not
 * duplicated here: a campaign's HTML lives once on marketing_campaigns.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('email_outbound', function (Blueprint $table) {
            $table->id();
            // Whose address it was sent FROM: null = Bookboll's, set = that provider's.
            $table->unsignedBigInteger('owner_user_id')->nullable();
            $table->string('to_email', 191);
            $table->unsignedBigInteger('customer_id')->nullable();
            $table->unsignedBigInteger('campaign_id')->nullable();
            $table->string('kind', 16)->default('campaign');   // campaign | notification | test
            $table->string('subject')->nullable();
            $table->string('status', 16)->default('sent');     // sent | failed
            $table->text('error')->nullable();
            $table->timestamps();

            $table->index(['owner_user_id', 'created_at']);
            $table->index('campaign_id');
            $table->index('to_email');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('email_outbound');
    }
};
