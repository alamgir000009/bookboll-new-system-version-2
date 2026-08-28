<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Mirrors Supabase public.customer_messages — DM + community group chat
 * (conversation_key = community__{uuid}, recipient_id nullable for groups).
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('customer_messages')) {
            Schema::create('customer_messages', function (Blueprint $table) {
                $table->id();
                $table->uuid('uuid')->unique();
                $table->string('conversation_key')->index();
                $table->uuid('sender_id')->index();
                $table->uuid('recipient_id')->nullable()->index();
                $table->text('body');
                $table->timestamp('read_at')->nullable();
                $table->timestamps();
                $table->index(['conversation_key', 'created_at'], 'cm_convo_created');
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('customer_messages');
    }
};
