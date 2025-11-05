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
        Schema::create('payments', function (Blueprint $table) {
            $table->id();
            $table->string('payment_id')->unique();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('booking_id')->constrained()->cascadeOnDelete();
            $table->enum('type', PaymentType::list())->default(PaymentType::FULL);
            $table->decimal('amount', 15, 2);
            $table->enum('method', PaymentMethod::list())->default(PaymentMethod::CASH);
            $table->enum('status', PaymentStatus::list())->default(PaymentStatus::PENDING);
            $table->date('date')->nullable();
            $table->text('notes')->nullable();
            $table->string('payment_intent_id')->nullable();
            $table->string('charge_id')->nullable();
            $table->timestamps();

            $table->index(['payment_id', 'user_id', 'booking_id']);
            $table->index(['type', 'method', 'date', 'status']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('payments');
    }
};
