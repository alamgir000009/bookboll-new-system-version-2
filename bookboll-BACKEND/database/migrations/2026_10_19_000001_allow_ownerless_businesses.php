<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * A business exists in its own right, with or without an owner account.
 *
 * `businesses.owner_id` was NOT NULL with a cascading foreign key, which said
 * two things we no longer want: that a business cannot be created before an
 * owner account exists, and that deleting the owner's user row should delete
 * the business with it. Platform staff need to register a venue operator before
 * anyone from that operator has a login, and such a business is unclaimed — not
 * Bookboll's own, and certainly not something to destroy along with a user.
 *
 * The UNIQUE index stays: MySQL and MariaDB allow repeated NULLs in a unique
 * index, so "at most one business per owner account" still holds while any
 * number of businesses can sit ownerless.
 *
 * The cascade becomes SET NULL, so removing an owner's account unclaims the
 * business instead of deleting it along with its venues and bookings.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('businesses')) {
            return;
        }

        // The column cannot be altered while a foreign key sits on it.
        $this->dropOwnerForeignKey();

        DB::statement('ALTER TABLE `businesses` MODIFY `owner_id` BIGINT UNSIGNED NULL');

        Schema::table('businesses', function (Blueprint $table) {
            $table->foreign('owner_id', 'businesses_owner_id_foreign')
                ->references('id')->on('users')->nullOnDelete();
        });
    }

    public function down(): void
    {
        if (! Schema::hasTable('businesses')) {
            return;
        }

        // Going back means every business must have an owner again. Refuse
        // rather than invent one or delete real businesses.
        $ownerless = DB::table('businesses')->whereNull('owner_id')->count();
        if ($ownerless > 0) {
            throw new RuntimeException(
                "Refusing to revert: {$ownerless} business(es) have no owner. Assign owners first."
            );
        }

        $this->dropOwnerForeignKey();

        DB::statement('ALTER TABLE `businesses` MODIFY `owner_id` BIGINT UNSIGNED NOT NULL');

        Schema::table('businesses', function (Blueprint $table) {
            $table->foreign('owner_id', 'businesses_owner_id_foreign')
                ->references('id')->on('users')->cascadeOnDelete();
        });
    }

    /** Drop it by its real name, tolerating an install that never had it. */
    protected function dropOwnerForeignKey(): void
    {
        try {
            Schema::table('businesses', function (Blueprint $table) {
                $table->dropForeign('businesses_owner_id_foreign');
            });
        } catch (\Throwable $e) {
            // Already absent — nothing to drop.
        }
    }
};
