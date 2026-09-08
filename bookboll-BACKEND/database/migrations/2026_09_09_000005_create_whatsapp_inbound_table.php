<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Everything Meta sends US (Postgres: public.whatsapp_inbound).
 *
 * Two kinds share the table because they arrive in the same webhook payload:
 *   message — a customer wrote to one of our numbers
 *   status  — a delivery receipt for something we sent (sent → delivered → read)
 *
 * `raw` keeps the full "changes" object so anything we do not parse today is still
 * recoverable tomorrow.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('whatsapp_inbound')) {
            return;
        }

        Schema::create('whatsapp_inbound', function (Blueprint $table) {
            $table->id();
            $table->string('kind', 16);                                  // message | status
            $table->string('wa_message_id')->nullable();                 // wamid…
            $table->string('from_number', 32)->nullable();               // sender (message) / recipient (status)
            $table->string('from_name')->nullable();                     // profile name, when Meta sends one
            $table->string('phone_number_id', 64)->nullable();           // which of our numbers received it
            $table->string('message_type', 32)->nullable();              // text | image | interactive | …
            $table->text('body')->nullable();                            // best-effort plain text
            $table->string('status', 16)->nullable();                    // status rows only
            $table->json('raw');
            $table->timestamp('received_at')->useCurrent();
            $table->timestamps();

            $table->index('received_at');
            $table->index('from_number');
            $table->index('wa_message_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('whatsapp_inbound');
    }
};
