<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Dashboard listing pricing: cells grid + group color/sort_order (Supabase parity).
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('pricing_groups')) {
            Schema::table('pricing_groups', function (Blueprint $table) {
                if (! Schema::hasColumn('pricing_groups', 'color')) {
                    $table->string('color', 32)->nullable()->after('name');
                }
                if (! Schema::hasColumn('pricing_groups', 'sort_order')) {
                    $table->integer('sort_order')->default(0)->after('currency');
                }
            });
        }

        if (! Schema::hasTable('pricing_cells')) {
            Schema::create('pricing_cells', function (Blueprint $table) {
                $table->id();
                $table->foreignId('listing_id')->constrained('listings')->cascadeOnDelete();
                $table->unsignedTinyInteger('day_of_week');
                $table->string('slot_time', 8);
                $table->unsignedBigInteger('pricing_group_id')->nullable()->index();
                $table->decimal('direct_price', 15, 2)->nullable();
                $table->timestamps();

                $table->index(['listing_id', 'day_of_week', 'slot_time']);
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('pricing_cells');

        if (Schema::hasTable('pricing_groups')) {
            Schema::table('pricing_groups', function (Blueprint $table) {
                if (Schema::hasColumn('pricing_groups', 'sort_order')) {
                    $table->dropColumn('sort_order');
                }
                if (Schema::hasColumn('pricing_groups', 'color')) {
                    $table->dropColumn('color');
                }
            });
        }
    }
};
