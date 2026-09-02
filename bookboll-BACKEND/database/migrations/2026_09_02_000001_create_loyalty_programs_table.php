<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Per-country loyalty program config (earn/redeem rates) for the storefront
 * checkout and the apps/admin Countries page. A missing row means defaults.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('loyalty_programs')) {
            Schema::create('loyalty_programs', function (Blueprint $table) {
                $table->id();
                $table->unsignedBigInteger('country_id')->unique();
                $table->decimal('earn_rate', 8, 2)->default(1);
                $table->unsignedInteger('redeem_rate')->default(100);
                $table->decimal('redeem_value', 8, 2)->default(1);
                $table->unsignedInteger('min_redeem')->default(100);
                $table->unsignedTinyInteger('max_redeem_pct')->default(100);
                $table->boolean('is_active')->default(true);
                $table->timestamps();
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('loyalty_programs');
    }
};
