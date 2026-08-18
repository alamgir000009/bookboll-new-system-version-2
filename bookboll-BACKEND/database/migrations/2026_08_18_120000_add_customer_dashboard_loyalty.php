<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Per-country loyalty balances and optional country on customers
 * for the apps/admin Customers CRM page.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('users') && ! Schema::hasColumn('users', 'country_id')) {
            Schema::table('users', function (Blueprint $table) {
                $table->unsignedBigInteger('country_id')->nullable()->index()->after('nationality');
            });
        }

        if (! Schema::hasTable('customer_loyalty')) {
            Schema::create('customer_loyalty', function (Blueprint $table) {
                $table->id();
                $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
                $table->unsignedBigInteger('country_id')->index();
                $table->unsignedInteger('points')->default(0);
                $table->unsignedInteger('lifetime_points')->default(0);
                $table->timestamps();

                $table->unique(['user_id', 'country_id']);
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('customer_loyalty');

        if (Schema::hasTable('users') && Schema::hasColumn('users', 'country_id')) {
            Schema::table('users', function (Blueprint $table) {
                $table->dropColumn('country_id');
            });
        }
    }
};
