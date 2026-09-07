<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Per-user permission tuning and scope pins (Postgres: the matching admin_users
 * columns), so the Laravel API can enforce exactly what the dashboard shows.
 *
 *   custom_permissions     { grant: [cap…], revoke: [cap…] } — My Team's
 *                          "Advanced — custom permissions", layered over the role.
 *   assigned_location_ids  Managers/Staff can be pinned to specific venues;
 *   assigned_listing_ids   empty = no restriction (everything in their business).
 *   assigned_program_ids   Owners and platform admins are never narrowed by these.
 *   business_status        Approvals' suspend/approve on a provider's business —
 *                          a suspended business drops off the marketplace.
 *
 * assigned_country_ids, owner_user_id and country_id already exist.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('users')) {
            return;
        }

        Schema::table('users', function (Blueprint $table) {
            if (! Schema::hasColumn('users', 'custom_permissions')) {
                $table->json('custom_permissions')->nullable();
            }
            if (! Schema::hasColumn('users', 'assigned_location_ids')) {
                $table->json('assigned_location_ids')->nullable();
            }
            if (! Schema::hasColumn('users', 'assigned_listing_ids')) {
                $table->json('assigned_listing_ids')->nullable();
            }
            if (! Schema::hasColumn('users', 'assigned_program_ids')) {
                $table->json('assigned_program_ids')->nullable();
            }
            if (! Schema::hasColumn('users', 'business_status')) {
                $table->string('business_status', 20)->nullable();
            }
        });
    }

    public function down(): void
    {
        if (! Schema::hasTable('users')) {
            return;
        }

        Schema::table('users', function (Blueprint $table) {
            foreach ([
                'custom_permissions', 'assigned_location_ids',
                'assigned_listing_ids', 'assigned_program_ids', 'business_status',
            ] as $col) {
                if (Schema::hasColumn('users', $col)) {
                    $table->dropColumn($col);
                }
            }
        });
    }
};
