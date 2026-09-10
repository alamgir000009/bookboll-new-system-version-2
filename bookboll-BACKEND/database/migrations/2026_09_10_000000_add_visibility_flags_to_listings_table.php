<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('listings', function (Blueprint $table) {
            $table->boolean('show_on_admin')->default(true)->after('status');
            $table->boolean('show_on_customer')->default(true)->after('show_on_admin');

            $table->index(['show_on_admin', 'show_on_customer'], 'listings_visibility_index');
        });

        // Existing active listings stay visible on both sides; inactive ones stay hidden.
        DB::table('listings')->update([
            'show_on_admin'    => DB::raw("CASE WHEN status = 'active' THEN 1 ELSE 0 END"),
            'show_on_customer' => DB::raw("CASE WHEN status = 'active' THEN 1 ELSE 0 END"),
        ]);
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('listings', function (Blueprint $table) {
            $table->dropIndex('listings_visibility_index');
            $table->dropColumn(['show_on_admin', 'show_on_customer']);
        });
    }
};
