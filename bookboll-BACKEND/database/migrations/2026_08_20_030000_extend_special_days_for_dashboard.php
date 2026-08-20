<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('special_days', function (Blueprint $table) {
            if (! Schema::hasColumn('special_days', 'price')) {
                $table->decimal('price', 15, 2)->nullable()->after('description');
            }
            if (! Schema::hasColumn('special_days', 'price_multiplier')) {
                $table->decimal('price_multiplier', 10, 4)->nullable()->default(1)->after('price');
            }
        });
    }

    public function down(): void
    {
        Schema::table('special_days', function (Blueprint $table) {
            if (Schema::hasColumn('special_days', 'price_multiplier')) {
                $table->dropColumn('price_multiplier');
            }
            if (Schema::hasColumn('special_days', 'price')) {
                $table->dropColumn('price');
            }
        });
    }
};
