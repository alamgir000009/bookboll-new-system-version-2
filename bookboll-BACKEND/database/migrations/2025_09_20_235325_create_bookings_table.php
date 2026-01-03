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
        Schema::create('bookings', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->comment('Used for customers')->constrained('users')->cascadeOnDelete();
            $table->foreignId('venue_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('listing_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('promocode_id')->nullable()->constrained('promocodes')->nullOnDelete();
            $table->string('booking_id')->unique()->nullable();
            $table->date('date')->nullable();
            $table->time('start_time')->nullable();
            $table->time('end_time')->nullable();
            $table->integer('duration')->nullable();
            $table->decimal('subtotal', 15, 2);
            $table->decimal('discount_amount')->default(0);
            $table->decimal('gift_card_amount')->default(0);
            $table->decimal('total_price', 15, 2);
            $table->boolean('full_payment_received')->default(false);
            $table->longText('notes')->nullable();
            $table->text('cancel_reason')->nullable();
            $table->foreignId('cancelled_by')->nullable()->constrained('users')->references('id')->nullOnDelete();
            $table->timestamp('cancelled_at')->nullable();
            $table->decimal('refunded_amount', 15, 2)->default(0);
            $table->timestamp('refunded_at')->nullable();
            $table->enum('status', BookingStatus::list())->default(BookingStatus::PENDING);
            $table->enum('payment_status', BookingPaymentStatus::list())->nullable();
            $table->enum('payment_method', BookingPaymentMethod::list())->nullable();
            $table->json('payment_breakdown')->nullable();
            $table->integer('loyalty_points_earned')->nullable();
            $table->boolean('is_free')->default(false);
            $table->timestamps();

            $table->index(['user_id', 'venue_id', 'listing_id']);
            $table->index(['date', 'start_time', 'end_time']);
            $table->index(['booking_id', 'total_price', 'status']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('bookings');
    }
};
