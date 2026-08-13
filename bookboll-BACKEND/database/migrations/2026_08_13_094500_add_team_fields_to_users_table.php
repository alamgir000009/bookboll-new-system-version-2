<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            if (! Schema::hasColumn('users', 'country_id')) {
                $table->unsignedBigInteger('country_id')->nullable()->index()->after('status');
            }
            if (! Schema::hasColumn('users', 'assigned_country_ids')) {
                $table->json('assigned_country_ids')->nullable()->after('country_id');
            }
            if (! Schema::hasColumn('users', 'owner_user_id')) {
                $table->foreignId('owner_user_id')->nullable()->after('assigned_country_ids')
                    ->constrained('users')->nullOnDelete();
            }
            if (! Schema::hasColumn('users', 'last_login_at')) {
                $table->timestamp('last_login_at')->nullable()->after('remember_token');
            }
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            if (Schema::hasColumn('users', 'owner_user_id')) {
                $table->dropConstrainedForeignId('owner_user_id');
            }
            foreach (['country_id', 'assigned_country_ids', 'last_login_at'] as $col) {
                if (Schema::hasColumn('users', $col)) {
                    $table->dropColumn($col);
                }
            }
        });
    }
};
