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
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete(); // Customer
            $table->foreignId('venue_id')->constrained()->cascadeOnDelete();
            $table->foreignId('listing_id')->constrained()->cascadeOnDelete();
            $table->foreignId('promocode_id')->nullable()->constrained('promocodes')->nullOnDelete();
            $table->dateTime('start_time')->nullable();
            $table->dateTime('end_time')->nullable();
            $table->integer('duration')->storedAs('TIMESTAMPDIFF(HOUR, start_time, end_time)');
            $table->decimal('subtotal', 15, 2);
            $table->decimal('discount_amount')->default(0);
            $table->decimal('total_price', 15, 2);
            $table->enum('payment_method', ['cash_on_arrival', 'online', 'deposit'])->default('cash_on_arrival');
            $table->text('notes')->nullable();
            $table->string('status')->default('pending');
            $table->timestamps();
            $table->index(['user_id', 'status']);
            $table->index(['listing_id', 'start_time', 'end_time']);
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
