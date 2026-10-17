<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;

/**
 * One store for dynamic pricing, not two.
 *
 * `pricing_cells` held one row per painted square, naming a pricing group. When a
 * slot was priced it was read FIRST and won outright — and it never re-checked
 * that the group it named still covered that day or hour. So a square painted
 * before a group's Days were edited went on charging the old group's price for
 * ever, while the admin's group card said something completely different. The
 * group editor and the customer's price could disagree indefinitely, and did.
 *
 * Groups can express everything these rows held, so the table goes and the grid
 * becomes a view of the groups. Checked against the live data first:
 *
 *   - 39 rows in the whole database, every one of them on a single listing
 *   - NONE had a `direct_price`; every row merely pointed at a group, so there
 *     is nothing here that a group cannot say
 *   - all 39 written in one save, by the tab-switch converter that collapsed
 *     groups by price (since deleted)
 *
 * A row carrying a `direct_price` WOULD be real pricing this cannot represent,
 * so if any exist the drop is refused rather than silently throwing money data
 * away — that means a release went out between this being written and run, and
 * it needs looking at by hand.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('pricing_cells')) {
            return;
        }

        $priced = DB::table('pricing_cells')->whereNotNull('direct_price')->count();
        if ($priced > 0) {
            throw new RuntimeException(
                "Refusing to drop pricing_cells: {$priced} row(s) carry a direct_price that no "
                .'pricing group represents. Convert them to groups first, then re-run.'
            );
        }

        Log::info('Dropping pricing_cells', ['rows' => DB::table('pricing_cells')->count()]);

        Schema::drop('pricing_cells');
    }

    /**
     * Recreated empty. The rows are not restored and must not be: every one of
     * them was a duplicate of coverage a group already states, and bringing them
     * back would re-open the same disagreement.
     */
    public function down(): void
    {
        if (Schema::hasTable('pricing_cells')) {
            return;
        }

        Schema::create('pricing_cells', function (Blueprint $table) {
            $table->id();
            $table->foreignId('listing_id')->constrained('listings')->cascadeOnDelete();
            $table->unsignedTinyInteger('day_of_week');
            $table->string('slot_time', 8);
            $table->unsignedBigInteger('pricing_group_id')->nullable()->index();
            $table->decimal('direct_price', 15, 2)->nullable();
            $table->timestamps();
            $table->unique(['listing_id', 'day_of_week', 'slot_time'], 'pricing_cells_square_unique');
        });
    }
};
