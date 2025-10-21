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
        Schema::create('schedules', function (Blueprint $table) {
            $table->id();
            $table->foreignId('venue_id')->nullable()->constrained()->cascadeOnDelete();
            $table->foreignId('listing_id')->nullable()->constrained()->cascadeOnDelete();
            $table->enum('day', ['monday', 'tuesday', 'wednesday', 'thursday', 'friday', 'saturday', 'sunday'])->nullable();
            $table->boolean('is_open')->default(false);
            $table->string('start_time')->nullable();
            $table->string('end_time')->nullable();
            $table->timestamps();
            $table->index(['venue_id', 'listing_id']);
            $table->index('day');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('venu_schedules');
    }
};
