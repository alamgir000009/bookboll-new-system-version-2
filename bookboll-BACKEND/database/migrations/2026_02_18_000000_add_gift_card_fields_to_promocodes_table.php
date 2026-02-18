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
        Schema::table('promocodes', function (Blueprint $table) {
            $table->boolean('is_gift_card_promocode')->default(false)->after('is_reminder_promocode');
            $table->foreignId('gift_card_id')->nullable()->after('is_gift_card_promocode')->constrained()->nullOnDelete();
            $table->index('is_gift_card_promocode');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('promocodes', function (Blueprint $table) {
            $table->dropForeign(['gift_card_id']);
            $table->dropIndex(['is_gift_card_promocode']);
            $table->dropColumn(['is_gift_card_promocode', 'gift_card_id']);
        });
    }
};
