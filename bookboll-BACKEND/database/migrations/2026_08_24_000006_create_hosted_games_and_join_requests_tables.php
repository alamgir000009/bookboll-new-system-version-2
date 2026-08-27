<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Hosted events / join requests — mirrors Supabase public.hosted_games
 * and public.game_join_requests (bigint PKs + unique uuid, no FKs).
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('hosted_games')) {
            Schema::create('hosted_games', function (Blueprint $table) {
                $table->id();
                $table->uuid('uuid')->unique();
                $table->uuid('host_customer_id')->nullable()->index();
                $table->string('host_name')->nullable()->default('');
                $table->string('host_phone')->nullable()->default('');
                $table->string('host_whatsapp')->nullable()->default('');
                $table->uuid('location_id')->nullable()->index();
                $table->uuid('listing_id')->nullable();
                $table->uuid('booking_id')->nullable();
                $table->uuid('city_id')->nullable()->index();
                $table->uuid('country_id')->nullable()->index();
                $table->string('title')->nullable()->default('');
                $table->string('sport')->nullable()->default('');
                $table->string('game_type', 32)->default('game');
                $table->date('game_date')->nullable();
                $table->string('start_time', 32)->nullable()->default('');
                $table->string('end_time', 32)->nullable()->default('');
                $table->decimal('price_per_person', 14, 2)->default(0);
                $table->string('currency', 16)->default('AED');
                $table->integer('max_players')->default(10);
                $table->string('skill_level', 32)->default('all');
                $table->text('description')->nullable();
                $table->string('status', 32)->default('open')->index();
                $table->integer('num_teams')->default(0);
                $table->string('format', 64)->nullable()->default('single_elim');
                $table->decimal('prize_pot', 14, 2)->default(0);
                $table->text('prize_note')->nullable();
                $table->string('custom_location_name')->nullable()->default('');
                $table->text('custom_location_address')->nullable();
                $table->boolean('hide_location')->default(false);
                $table->json('images')->nullable();
                $table->string('moderation_status', 32)->default('active')->index();
                $table->text('moderation_reason')->nullable();
                $table->timestamp('moderated_at')->nullable();
                $table->string('moderated_by')->nullable();
                $table->json('signup_form')->nullable();
                $table->timestamp('request_deadline')->nullable();
                $table->string('approval_mode', 32)->default('review');
                $table->json('charge')->nullable();
                $table->unsignedBigInteger('community_id')->nullable()->index();
                $table->integer('lock_minutes_before')->nullable();
                $table->string('play_format', 64)->nullable()->default('');
                $table->integer('courts_count')->nullable();
                $table->json('rounds')->nullable();
                $table->boolean('community_public')->default(false);
                $table->boolean('allow_waitlist')->default(true);
                $table->timestamps();
                $table->index('created_at');
            });
        }

        if (! Schema::hasTable('game_join_requests')) {
            Schema::create('game_join_requests', function (Blueprint $table) {
                $table->id();
                $table->uuid('uuid')->unique();
                $table->unsignedBigInteger('game_id')->index();
                $table->uuid('joiner_customer_id')->nullable()->index();
                $table->string('joiner_name')->nullable()->default('');
                $table->string('joiner_phone')->nullable()->default('');
                $table->string('joiner_whatsapp')->nullable()->default('');
                $table->text('joiner_message')->nullable();
                $table->string('status', 32)->default('pending')->index();
                $table->json('form_answers')->nullable();
                $table->boolean('attended')->nullable();
                $table->string('refund_state', 32)->nullable()->default('');
                $table->timestamps();
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('game_join_requests');
        Schema::dropIfExists('hosted_games');
    }
};
