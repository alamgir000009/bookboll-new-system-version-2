<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A campaign is no longer just "recorded" — it is now actually delivered, which takes
 * time and can partly fail. These columns are what the dashboard reports back.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('marketing_campaigns', function (Blueprint $table) {
            // queued → sending → sent (or failed when nothing at all got out)
            $table->string('delivery_status', 16)->default('queued')->after('recipient_count');
            $table->unsignedInteger('sent_count')->default(0)->after('delivery_status');
            $table->unsignedInteger('failed_count')->default(0)->after('sent_count');
            $table->timestamp('completed_at')->nullable()->after('failed_count');
        });
    }

    public function down(): void
    {
        Schema::table('marketing_campaigns', function (Blueprint $table) {
            $table->dropColumn(['delivery_status', 'sent_count', 'failed_count', 'completed_at']);
        });
    }
};
