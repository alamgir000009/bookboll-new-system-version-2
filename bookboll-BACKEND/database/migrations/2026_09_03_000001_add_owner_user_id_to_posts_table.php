<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Which blog a post belongs to.
 *
 *   NULL  → a Bookboll platform blog (the `type` column then picks marketplace
 *           vs for-business). Every existing post is one of these.
 *   set   → that provider's own white-label blog; `type` is ignored.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasColumn('posts', 'owner_user_id')) {
            return;
        }

        Schema::table('posts', function (Blueprint $table) {
            $table->foreignId('owner_user_id')->nullable()->after('user_id')
                ->constrained('users')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('posts', function (Blueprint $table) {
            $table->dropConstrainedForeignId('owner_user_id');
        });
    }
};
