<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * A hosted game only goes live once the host has actually paid for the court.
 *
 * Until now the host was never charged: the booking and the game were written
 * straight away with payment rows already marked 'paid'. Hosting now goes
 * through the same Stripe checkout as any other booking, so a game needs a
 * state for "created, awaiting payment" that keeps it out of every feed.
 *
 * published_at IS NULL  = awaiting the host's payment (hidden by a global scope)
 * published_at NOT NULL = live
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('hosted_games', function (Blueprint $table) {
            $table->timestamp('published_at')->nullable()->after('status')->index();
        });

        // Every game that already exists was published under the old rules —
        // without this backfill the new global scope would hide all of them.
        DB::table('hosted_games')->whereNull('published_at')->update([
            'published_at' => DB::raw('COALESCE(created_at, NOW())'),
        ]);
    }

    public function down(): void
    {
        Schema::table('hosted_games', function (Blueprint $table) {
            $table->dropIndex(['published_at']);
            $table->dropColumn('published_at');
        });
    }
};
