<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * One row per game in a tournament bracket. The tournament itself is a
 * hosted_games row (game_type = 'tournament'); each match points back at it and
 * holds its own court reservation, so a bracket is a parent game plus N matches.
 *
 * Mirrors Supabase public.tournament_matches (bigint PKs + unique uuid, no FKs).
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('tournament_matches')) {
            return;
        }

        Schema::create('tournament_matches', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->unsignedBigInteger('tournament_id')->index();

            // Position in the bracket. Round 0 is the first round.
            $table->unsignedInteger('round')->default(0);
            $table->unsignedInteger('match_index')->default(0);

            // The court this match reserved.
            $table->unsignedBigInteger('listing_id')->nullable()->index();
            $table->unsignedBigInteger('location_id')->nullable()->index();
            $table->unsignedBigInteger('booking_id')->nullable()->index();

            $table->date('match_date')->nullable();
            $table->string('start_time', 32)->nullable();
            $table->string('end_time', 32)->nullable();

            // Teams are filled in when the host seeds the bracket from accepted
            // join requests, then carried forward as winners advance.
            $table->string('home_team')->nullable();
            $table->unsignedBigInteger('home_request_id')->nullable();
            $table->string('away_team')->nullable();
            $table->unsignedBigInteger('away_request_id')->nullable();

            $table->string('winner', 16)->nullable();          // 'home' | 'away' | null
            $table->string('status', 32)->default('scheduled'); // 'scheduled' | 'done'

            $table->timestamps();
            $table->index(['tournament_id', 'round', 'match_index']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('tournament_matches');
    }
};
