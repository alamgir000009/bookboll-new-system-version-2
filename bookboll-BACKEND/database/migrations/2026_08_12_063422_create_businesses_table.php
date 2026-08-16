<?php

use App\Models\Business;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('businesses', function (Blueprint $table) {
            $table->id();
            $table->foreignId('owner_id')->unique()->constrained('users')->cascadeOnDelete();
            $table->string('name');
            $table->string('username', 30)->unique();
            $table->json('business_types')->nullable();
            $table->unsignedBigInteger('country_id')->nullable()->index();
            $table->enum('status', Business::STATUSES)->default(Business::STATUS_PENDING)->index();
            $table->boolean('brand_enabled')->default(false);
            $table->string('brand_subdomain', 30)->nullable()->unique();
            $table->string('brand_name')->nullable();
            $table->string('brand_tagline')->nullable();
            $table->string('brand_logo_url')->nullable();
            $table->string('brand_primary', 20)->nullable();
            $table->string('brand_accent', 20)->nullable();
            $table->string('brand_meta_title')->nullable();
            $table->text('brand_meta_description')->nullable();
            $table->json('notification_templates')->nullable();

            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('businesses');
    }
};
