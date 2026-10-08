<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Which business a customer belongs to, recorded explicitly.
 *
 * Until now this was only ever DERIVED — a customer was "yours" if they had booked
 * at one of your venues or subscribed to one of your programmes. A client your staff
 * typed in by hand had neither, so the moment the Customers list became
 * business-scoped such a client was created (201) and then invisible to the very
 * business that added them.
 *
 * The link is many-to-many on purpose: the same person can be a walk-in client of one
 * business, a booker at a second and a programme member at a third. `source` records
 * WHY the link exists so an "added" link is never mistaken for a booking history.
 *
 * Keyed on owner_user_id (the provider_admin's user id) rather than businesses.id,
 * because that is the identity AccessScope already scopes by, and not every provider
 * has a businesses row.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('business_customer')) {
            Schema::create('business_customer', function (Blueprint $table) {
                $table->id();
                $table->foreignId('owner_user_id')->constrained('users')->cascadeOnDelete();
                $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
                $table->enum('source', ['added', 'booked', 'subscribed'])->default('added');
                $table->timestamps();

                $table->unique(['owner_user_id', 'user_id', 'source']);
                // The read path asks "which customers belong to these businesses".
                $table->index(['owner_user_id', 'user_id']);
                $table->index('user_id');
            });
        }

        // ── Backfill, so nothing that is already someone's customer is orphaned ──
        // Set-based inserts; INSERT IGNORE leans on the unique index for idempotency.

        // 1. Booked at a venue the business owns.
        DB::statement("
            INSERT IGNORE INTO business_customer (owner_user_id, user_id, source, created_at, updated_at)
            SELECT DISTINCT v.owner_user_id, b.user_id, 'booked', NOW(), NOW()
            FROM bookings b
            JOIN venues v ON v.id = b.venue_id
            WHERE b.user_id IS NOT NULL AND v.owner_user_id IS NOT NULL
        ");

        // 2. Subscribed to one of the business's programmes. class_subscriptions
        //    carries only an email, so that is the join — matched case-insensitively,
        //    the same way the scope rule matches it.
        DB::statement("
            INSERT IGNORE INTO business_customer (owner_user_id, user_id, source, created_at, updated_at)
            SELECT DISTINCT owner_id, user_id, 'subscribed', NOW(), NOW()
            FROM (
                SELECT COALESCE(p.mapped_owner_user_id, v.owner_user_id) AS owner_id, u.id AS user_id
                FROM class_subscriptions cs
                JOIN class_programs p ON p.id = cs.program_id
                LEFT JOIN venues v ON v.id = p.venue_id
                JOIN users u ON LOWER(u.email) = LOWER(cs.customer_email)
                WHERE cs.customer_email IS NOT NULL AND cs.customer_email <> ''
            ) AS src
            WHERE owner_id IS NOT NULL
        ");

        // 3. Typed in by a business. users.created_by is the person who created the
        //    row (TracksCreator); the business is that person's own business, so a
        //    client added by a staff member lands under the owner, not the staff.
        DB::statement("
            INSERT IGNORE INTO business_customer (owner_user_id, user_id, source, created_at, updated_at)
            SELECT DISTINCT owner_id, user_id, 'added', NOW(), NOW()
            FROM (
                SELECT
                    CASE
                        WHEN EXISTS (
                            SELECT 1 FROM model_has_roles mhr
                            JOIN roles r ON r.id = mhr.role_id
                            WHERE mhr.model_id = creator.id
                              AND mhr.model_type = 'App\\\\Models\\\\User'
                              AND r.name = 'provider_admin'
                        ) THEN creator.id
                        ELSE creator.owner_user_id
                    END AS owner_id,
                    u.id AS user_id
                FROM users u
                JOIN users creator ON creator.id = u.created_by
                WHERE u.created_by IS NOT NULL AND u.created_by <> u.id
            ) AS src
            WHERE owner_id IS NOT NULL
        ");
    }

    public function down(): void
    {
        Schema::dropIfExists('business_customer');
    }
};
