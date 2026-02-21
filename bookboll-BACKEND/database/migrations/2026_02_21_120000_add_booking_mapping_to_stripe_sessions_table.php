<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('stripe_sessions', function (Blueprint $table) {
            $table->unsignedBigInteger('booking_id')->nullable()->after('session_id');
            $table->json('booking_ids')->nullable()->after('booking_id');
            $table->string('source')->nullable()->after('booking_ids');

            $table->index('booking_id');
            $table->index('source');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('stripe_sessions', function (Blueprint $table) {
            $table->dropIndex(['booking_id']);
            $table->dropIndex(['source']);
            $table->dropColumn(['booking_id', 'booking_ids', 'source']);
        });
    }
};
