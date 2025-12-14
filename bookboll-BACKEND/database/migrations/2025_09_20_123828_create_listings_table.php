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
            $table->foreignId('sports_category_id')->nullable()->constrained('sports_categories')->nullOnDelete();
            $table->enum('status', ['active', 'inactive'])->default('active');
            $table->text('description')->nullable();
            $table->decimal('base_price', 15, 2);
            $table->integer('max_bookings_per_slot')->default(1);
            $table->boolean('allow_multiple_bookings')->default(false);
            $table->integer('max_multiple_bookings')->nullable();
            $table->integer('capacity');
            $table->float('slot_duration')->comment('In hours');
            $table->integer('loyalty_points')->nullable();
            $table->string('calendar_color')->default('#0000FF');
            $table->json('payment_options');
            $table->json('images_urls')->nullable();
            $table->json('amenities')->nullable();
            $table->json('tags')->nullable();
            $table->boolean('online_payment')->default(false);
            $table->integer('sort_order')->default(0);
            $table->timestamps();

            $table->index(['name', 'base_price', 'status']);
            $table->index(['venue_id', 'sports_category_id']);
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
