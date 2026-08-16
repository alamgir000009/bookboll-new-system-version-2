<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('cities') && ! Schema::hasColumn('cities', 'status')) {
            Schema::table('cities', function (Blueprint $table) {
                $table->unsignedTinyInteger('status')->default(1)->index()->after('name');
            });
        }

        if (! Schema::hasTable('commission_rates')) {
            Schema::create('commission_rates', function (Blueprint $table) {
                $table->id();
                $table->unsignedBigInteger('country_id')->index();
                $table->string('listing_type', 30); // facility | program | event
                $table->decimal('rate_percent', 5, 2)->default(15);
                $table->timestamps();

                $table->unique(['country_id', 'listing_type']);
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('commission_rates');

        if (Schema::hasTable('cities') && Schema::hasColumn('cities', 'status')) {
            Schema::table('cities', function (Blueprint $table) {
                $table->dropColumn('status');
            });
        }
    }
};
