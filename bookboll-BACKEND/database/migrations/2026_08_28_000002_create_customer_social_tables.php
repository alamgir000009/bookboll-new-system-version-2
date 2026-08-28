<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('customer_follows')) {
            Schema::create('customer_follows', function (Blueprint $table) {
                $table->id();
                $table->uuid('follower_uuid')->index();
                $table->uuid('following_uuid')->index();
                $table->string('status', 32)->default('pending')->index();
                $table->timestamps();
                $table->unique(['follower_uuid', 'following_uuid'], 'cf_follower_following_unique');
            });
        }

        if (! Schema::hasTable('customer_posts')) {
            Schema::create('customer_posts', function (Blueprint $table) {
                $table->id();
                $table->uuid('uuid')->unique();
                $table->uuid('author_uuid')->index();
                $table->text('body')->nullable();
                $table->json('photos')->nullable();
                $table->timestamps();
                $table->index('created_at');
            });
        }

        if (! Schema::hasTable('street_cred')) {
            Schema::create('street_cred', function (Blueprint $table) {
                $table->id();
                $table->uuid('game_uuid')->index();
                $table->uuid('giver_uuid')->index();
                $table->uuid('receiver_uuid')->index();
                $table->string('badge', 64);
                $table->timestamps();
                $table->unique(
                    ['game_uuid', 'giver_uuid', 'receiver_uuid', 'badge'],
                    'street_cred_unique'
                );
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('street_cred');
        Schema::dropIfExists('customer_posts');
        Schema::dropIfExists('customer_follows');
    }
};
