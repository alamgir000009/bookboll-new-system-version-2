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
        Schema::create('promocodes', function (Blueprint $table) {
            $table->id();
            $table->string('code')->unique();
            $table->enum('status', ['active', 'inactive'])->default('active');
            $table->text('description');
            $table->enum('discount_type', ['fixed', 'percentage'])->default('fixed');
            $table->decimal('discount_value', 5, 2);
            $table->unsignedInteger('usage_limit')->nullable();
            $table->date('valid_from')->nullable();
            $table->date('expires_at')->nullable();
            $table->decimal('min_order_amount', 8, 2)->nullable();
            $table->decimal('max_discount_amount', 8, 2)->nullable();
            $table->unsignedInteger('per_user_limit')->nullable();
            $table->string('payment_methods')->default('all');
            $table->json('used_by_users')->default('{}');
            $table->unsignedInteger('used_count')->default(0);
            $table->timestamps();
            $table->index(['status', 'valid_from', 'expires_at']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('promocodes');
    }
};
