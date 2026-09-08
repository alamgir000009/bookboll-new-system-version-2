<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Campaigns composed against a template Meta has NOT approved yet.
 *
 * WhatsApp forbids free-form bulk messages, so a marketing send needs an approved
 * template. Rather than make the provider come back later, the whole campaign is
 * snapshotted here and fires itself the moment Meta approves — or records the
 * rejection reason if Meta declines.
 *
 * `payload` is everything send_campaign needs later:
 *   { bodyText, recipients:[{to, customerId, variables[]}], campaignMeta:{…} }
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('whatsapp_queued_campaigns')) {
            return;
        }

        Schema::create('whatsapp_queued_campaigns', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('owner_user_id')->nullable();   // null = Bookboll platform campaign
            $table->string('template_name');
            $table->string('template_language', 16)->default('en_US');
            $table->json('payload');
            $table->string('status', 24)->default('awaiting_approval'); // awaiting_approval|sent|rejected|cancelled
            $table->text('reject_reason')->nullable();
            $table->json('result')->nullable();                        // { sent, failed, dropped } after auto-send
            $table->timestamp('sent_at')->nullable();
            $table->timestamp('dismissed_at')->nullable();             // outcome banner acknowledged
            $table->timestamps();

            $table->index(['owner_user_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('whatsapp_queued_campaigns');
    }
};
