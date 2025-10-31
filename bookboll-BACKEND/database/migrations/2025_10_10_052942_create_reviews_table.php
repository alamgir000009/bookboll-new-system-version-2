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
        Schema::create('reviews', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->onDelete('cascade');
            $table->foreignId('listing_id')->constrained()->onDelete('cascade');
            $table->foreignId('booking_id')->constrained()->onDelete('cascade');
            $table->integer('overall_rating')->between(1, 5);
            $table->integer('cleanliness_rating')->between(1, 5)->nullable();
            $table->integer('facilities_rating')->between(1, 5)->nullable();
            $table->integer('staff_rating')->between(1, 5)->nullable();
            $table->integer('value_rating')->between(1, 5)->nullable();
            $table->longText('review_text')->nullable();
            $table->longText('comment')->nullable();
            $table->boolean('recommended')->default(true);
            $table->integer('helpful_count')->default(0);
            $table->timestamps();

            $table->index(['user_id', 'listing_id', 'booking_id']);
            $table->index(['overall_rating', 'recommended']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('reviews');
    }
};
