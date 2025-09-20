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
        Schema::create('audit_logs', function (Blueprint $table) {
            $table->id();
            $table->timestamp('timestamp');
            $table->unsignedBigInteger('actor_id')->nullable();
            $table->string('actor_email')->nullable();
            $table->string('action');
            $table->string('resource');
            $table->string('resource_id');
            $table->text('details');
            $table->string('ip_address')->nullable();
            $table->string('device')->nullable();
            $table->timestamps();

            $table->foreign('actor_id')->references('id')->on('users')->onDelete('set null');
            $table->index(['timestamp', 'action', 'resource']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('audit_logs');
    }
};
