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
        Schema::create('listings', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->foreignId('venue_id')->constrained()->cascadeOnDelete();
            $table->string('sports_type')->nullable();
            $table->enum('status', ['active', 'inactive'])->default('active');
            $table->text('description')->nullable();
            $table->decimal('base_price', 15, 2);
            $table->integer('max_bookings_per_slot')->default(1);
            $table->integer('capacity');
            $table->integer('slot_duration')->comment('In hours');
            $table->integer('loyalty_points')->nullable();
            $table->string('calendar_color')->default('#0000FF');
            $table->json('payment_options');
            $table->json('image_urls')->nullable();
            $table->timestamps();

            $table->index(['venue_id', 'status']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('listings');
    }
};
