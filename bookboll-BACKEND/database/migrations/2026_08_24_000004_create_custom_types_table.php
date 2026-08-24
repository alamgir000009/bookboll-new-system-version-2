<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * custom_types — provider-suggested listing/program types promoted on approve
 * (mirrors Supabase public.custom_types used by Approvals.vue).
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('custom_types')) {
            return;
        }

        Schema::create('custom_types', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('domain', 32)->index(); // venue | program
            $table->string('name');
            $table->string('category', 64)->nullable();
            $table->string('status', 32)->default('pending')->index(); // pending | approved
            $table->string('created_by')->nullable();
            $table->timestamp('approved_at')->nullable();
            $table->timestamps();

            $table->index(['domain', 'category', 'status', 'name']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('custom_types');
    }
};
