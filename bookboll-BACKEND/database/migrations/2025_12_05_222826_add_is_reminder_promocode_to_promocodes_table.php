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
            $table->boolean('is_reminder_promocode')->default(false)->after('used_count');
            $table->index('is_reminder_promocode');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('promocodes', function (Blueprint $table) {
            $table->dropIndex(['is_reminder_promocode']);
            $table->dropColumn('is_reminder_promocode');
        });
    }
};
