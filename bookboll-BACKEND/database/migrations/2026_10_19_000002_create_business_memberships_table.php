<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Who belongs to which business, and in what capacity.
 *
 * Until now a business was identified by its OWNER's user id: a provider_admin
 * was the business, and staff pointed at them through `users.owner_user_id`.
 * That makes an ownerless business unrepresentable, gives one person two
 * different meanings, and spreads the business's identity across every table
 * that stored an owner id.
 *
 * This table makes membership its own fact, keyed by `businesses.id`, so a
 * business can exist with no owner, change owner without rewriting its records,
 * and carry staff whose access is scoped to particular venues.
 *
 * `owner_user_id` is deliberately NOT removed yet — 382 references across 59
 * files read it, including wallets, payouts and promocodes. This is the expand
 * step: both exist, this one becomes the source of truth for new work, and the
 * old column is retired per-area with its own tests.
 */
return new class extends Migration
{
    /** Roles a membership can hold. 'owner' is the business's principal. */
    public const ROLES = [
        'owner',
        'provider_admin',
        'facility_admin',
        'provider_supervisor',
        'staff',
        'provider_viewer',
    ];

    public function up(): void
    {
        Schema::create('business_memberships', function (Blueprint $table) {
            $table->id();
            $table->foreignId('business_id')->constrained('businesses')->cascadeOnDelete();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();

            $table->enum('role', self::ROLES)->index();
            // 'all' = the whole business; 'selected' = only location_ids.
            $table->enum('scope', ['all', 'selected'])->default('all');
            $table->json('location_ids')->nullable();

            $table->enum('status', ['active', 'revoked'])->default('active')->index();
            // Optimistic concurrency: the assignment endpoints send the version
            // they previewed against, and a stale one is rejected rather than
            // silently overwriting someone else's change.
            $table->unsignedInteger('version')->default(1);

            $table->unsignedBigInteger('created_by')->nullable()->index();
            $table->timestamp('revoked_at')->nullable();
            $table->timestamps();

            // One membership per person per business.
            $table->unique(['business_id', 'user_id'], 'business_memberships_unique_member');
            $table->index(['user_id', 'status'], 'business_memberships_user_status');
        });

        /*
         * One ACTIVE owner per business, enforced by the database rather than by
         * remembering to check. The generated column is the business id only for
         * an active owner row and NULL otherwise; a unique index over it allows
         * any number of NULLs, so staff and revoked owners are unconstrained
         * while a second active owner cannot be inserted at all.
         */
        DB::statement("
            ALTER TABLE `business_memberships`
            ADD COLUMN `active_owner_business_id` BIGINT UNSIGNED
                AS (CASE WHEN `role` = 'owner' AND `status` = 'active' THEN `business_id` END) VIRTUAL,
            ADD UNIQUE KEY `business_memberships_one_active_owner` (`active_owner_business_id`)
        ");
    }

    public function down(): void
    {
        Schema::dropIfExists('business_memberships');
    }
};
