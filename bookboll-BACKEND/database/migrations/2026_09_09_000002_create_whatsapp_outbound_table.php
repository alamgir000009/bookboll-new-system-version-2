<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Every WhatsApp message we dispatch (Postgres: public.whatsapp_outbound).
 *
 * Written only by the server, never by the browser — the WhatsApp page reads it back
 * as the "recent messages" log. `wa_message_id` is Meta's wamid, which a delivery
 * webhook can later use to move `status` from sent → delivered → read.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('whatsapp_outbound')) {
            return;
        }

        Schema::create('whatsapp_outbound', function (Blueprint $table) {
            $table->id();
            // Whose number sent it: null = Bookboll's, set = that provider's.
            $table->unsignedBigInteger('owner_user_id')->nullable();
            $table->string('to_number', 32);                          // digits only, country code, no +
            $table->unsignedBigInteger('customer_id')->nullable();    // matched customer, when known
            $table->unsignedBigInteger('campaign_id')->nullable();    // marketing_campaigns.id for bulk sends
            $table->string('kind', 16)->default('template');          // template | text | interactive
            $table->string('template_name')->nullable();
            $table->text('body')->nullable();                         // rendered text / template preview
            $table->string('wa_message_id')->nullable();              // Meta wamid, for status sync
            $table->string('status', 16)->default('sent');            // sent | failed | delivered | read
            $table->text('error')->nullable();                        // Graph API message when failed
            $table->timestamps();

            $table->index(['owner_user_id', 'created_at']);
            $table->index('to_number');
            $table->index('campaign_id');
            $table->index('wa_message_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('whatsapp_outbound');
    }
};
