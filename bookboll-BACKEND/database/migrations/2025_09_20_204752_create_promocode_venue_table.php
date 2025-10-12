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
        Schema::create('promocode_venue', function (Blueprint $table) {
            $table->id();
            $table->foreignId('promocode_id')->constrained('promocodes')->cascadeOnDelete();
            $table->foreignId('venue_id')->constrained('venues')->cascadeOnDelete();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('promocode_venue');
    }
};
