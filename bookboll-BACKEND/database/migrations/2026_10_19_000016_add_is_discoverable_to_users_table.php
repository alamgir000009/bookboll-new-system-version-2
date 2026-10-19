<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Whether a person may be FOUND by name in people search.
 *
 * `is_private` already governs who may see someone's posts, photos and stats,
 * and a private profile is still reachable at /u/:id — so it cannot double as
 * "do not list me". People search is a new way to be found, and the document
 * requires it to honour discoverability, which needs its own flag.
 *
 * Defaults to true so existing behaviour is unchanged: everyone who is already
 * reachable by link stays reachable by name until they say otherwise.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->boolean('is_discoverable')->default(true)->after('is_private');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn('is_discoverable');
        });
    }
};
