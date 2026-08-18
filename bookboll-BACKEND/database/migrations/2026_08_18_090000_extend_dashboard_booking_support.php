<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Extra columns the apps/admin Bookings page needs that existed on Supabase:
 * add-on quantities, venue geo/currency for filters.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('add_on_booking')) {
            Schema::table('add_on_booking', function (Blueprint $table) {
                if (! Schema::hasColumn('add_on_booking', 'quantity')) {
                    $table->unsignedInteger('quantity')->default(1);
                }
                if (! Schema::hasColumn('add_on_booking', 'unit_price')) {
                    $table->decimal('unit_price', 15, 2)->nullable();
                }
                if (! Schema::hasColumn('add_on_booking', 'subtotal')) {
                    $table->decimal('subtotal', 15, 2)->nullable();
                }
            });
        }

        if (Schema::hasTable('venues')) {
            Schema::table('venues', function (Blueprint $table) {
                if (! Schema::hasColumn('venues', 'city_id')) {
                    $table->unsignedBigInteger('city_id')->nullable()->index();
                }
                if (! Schema::hasColumn('venues', 'country_id')) {
                    $table->unsignedBigInteger('country_id')->nullable()->index();
                }
                if (! Schema::hasColumn('venues', 'currency_override')) {
                    $table->string('currency_override', 8)->nullable();
                }
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasTable('add_on_booking')) {
            Schema::table('add_on_booking', function (Blueprint $table) {
                $cols = array_values(array_filter(
                    ['quantity', 'unit_price', 'subtotal'],
                    fn ($c) => Schema::hasColumn('add_on_booking', $c)
                ));
                if ($cols) {
                    $table->dropColumn($cols);
                }
            });
        }

        if (Schema::hasTable('venues')) {
            Schema::table('venues', function (Blueprint $table) {
                $cols = array_values(array_filter(
                    ['city_id', 'country_id', 'currency_override'],
                    fn ($c) => Schema::hasColumn('venues', $c)
                ));
                if ($cols) {
                    $table->dropColumn($cols);
                }
            });
        }
    }
};
