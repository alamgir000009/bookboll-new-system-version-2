<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Communities module — mirrors Supabase public.communities,
 * community_members, community_posts (bigint PKs + unique uuid, no FKs).
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('communities')) {
            Schema::create('communities', function (Blueprint $table) {
                $table->id();
                $table->uuid('uuid')->unique();
                $table->uuid('owner_customer_id')->index();
                $table->string('name');
                $table->text('description')->nullable();
                $table->string('sport')->nullable()->default('');
                $table->json('sports')->nullable();
                $table->uuid('city_id')->nullable()->index();
                $table->uuid('country_id')->nullable()->index();
                $table->text('cover_image')->nullable();
                $table->json('images')->nullable();
                $table->boolean('is_private')->default(false);
                $table->json('settings')->nullable();
                $table->string('moderation_status', 32)->default('active')->index();
                $table->text('moderation_reason')->nullable();
                $table->timestamp('moderated_at')->nullable();
                $table->string('moderated_by')->nullable();
                $table->text('avatar_image')->nullable();
                $table->timestamps();
                $table->index('created_at');
            });
        }

        if (! Schema::hasTable('community_members')) {
            Schema::create('community_members', function (Blueprint $table) {
                $table->id();
                $table->uuid('uuid')->unique();
                $table->unsignedBigInteger('community_id');
                $table->uuid('customer_id');
                $table->string('role', 32)->default('member');
                $table->string('status', 32)->default('accepted');
                $table->timestamp('last_read_at')->nullable();
                $table->timestamp('joined_at')->nullable();
                $table->timestamps();
                $table->unique(['community_id', 'customer_id'], 'cm_community_customer_unique');
                $table->index(['community_id', 'status'], 'cm_community_status');
                $table->index(['customer_id', 'status'], 'cm_customer_status');
            });
        }

        if (! Schema::hasTable('community_posts')) {
            Schema::create('community_posts', function (Blueprint $table) {
                $table->id();
                $table->uuid('uuid')->unique();
                $table->unsignedBigInteger('community_id')->index();
                $table->uuid('author_id')->index();
                $table->text('body')->nullable();
                $table->json('photos')->nullable();
                $table->string('moderation_status', 32)->default('active')->index();
                $table->text('moderation_reason')->nullable();
                $table->timestamp('moderated_at')->nullable();
                $table->string('moderated_by')->nullable();
                $table->timestamps();
                $table->index('created_at');
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('community_posts');
        Schema::dropIfExists('community_members');
        Schema::dropIfExists('communities');
    }
};
