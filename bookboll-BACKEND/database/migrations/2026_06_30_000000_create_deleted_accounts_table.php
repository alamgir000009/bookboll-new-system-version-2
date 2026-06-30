<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('deleted_accounts', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('user_id')->index();
            $table->string('name')->nullable();
            $table->string('email')->index();
            $table->string('phone')->nullable();
            $table->enum('deletion_type', ['account_deleted', 'access_removed'])->index();
            $table->string('source')->default('admin_mobile_app')->index();
            $table->json('roles')->nullable();
            $table->json('assigned_venues')->nullable();
            $table->json('user_snapshot')->nullable();
            $table->string('ip_address', 45)->nullable();
            $table->text('user_agent')->nullable();
            $table->timestamp('deleted_at')->useCurrent();
            $table->timestamps();

            $table->index(['email', 'deleted_at']);
            $table->index(['user_id', 'deletion_type']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('deleted_accounts');
    }
};
