<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Every marketing send, recorded (Postgres: public.marketing_campaigns).
 *
 * This is the compliance ledger behind the Customers page composer: who sent what, to
 * how many people, on which channel, with the exact HTML that went out. It exists so
 * "did we email these customers, and what did we say?" has an answer.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('marketing_campaigns')) {
            return;
        }

        Schema::create('marketing_campaigns', function (Blueprint $table) {
            $table->id();
            // null = a Bookboll/platform campaign; set = that provider's own campaign.
            $table->unsignedBigInteger('owner_user_id')->nullable();
            $table->unsignedBigInteger('created_by')->nullable();     // the admin who pressed send
            $table->string('sender_label')->default('Bookboll');      // "Bookboll" or the business name
            $table->string('channel', 16)->default('email');          // email | sms | whatsapp
            $table->string('audience')->nullable();                   // human note, e.g. "opted-in email · 42 selected"
            $table->string('subject')->nullable();
            $table->text('body')->nullable();
            $table->longText('html')->nullable();                     // rendered, with disclaimer + unsubscribe
            $table->unsignedInteger('recipient_count')->default(0);
            $table->timestamps();

            $table->index(['owner_user_id', 'created_at']);
            $table->index('channel');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('marketing_campaigns');
    }
};
