<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;

/**
 * Turn the existing owner/staff relationships into memberships.
 *
 * Two facts are being read, in this order:
 *   1. `businesses.owner_id`      -> the owner membership
 *   2. `users.owner_user_id`      -> that owner's staff
 *
 * Nothing is deleted and no id changes: this only writes rows into the new
 * table, so it can be re-run and can be rolled back by emptying that table.
 *
 * Staff whose owner no longer corresponds to a business are reported rather
 * than guessed at — an orphaned staff row is a data problem to look at, not
 * something to attach to an arbitrary business.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('business_memberships') || ! Schema::hasTable('businesses')) {
            return;
        }

        $now = now();
        $ownerToBusiness = [];
        $owners = 0;

        foreach (DB::table('businesses')->whereNotNull('owner_id')->get(['id', 'owner_id']) as $business) {
            $ownerToBusiness[(int) $business->owner_id] = (int) $business->id;

            // updateOrInsert keeps this idempotent, and respects the unique
            // (business_id, user_id) key if the row is already there.
            DB::table('business_memberships')->updateOrInsert(
                ['business_id' => (int) $business->id, 'user_id' => (int) $business->owner_id],
                [
                    'role' => 'owner', 'scope' => 'all', 'location_ids' => null,
                    'status' => 'active', 'version' => 1,
                    'created_at' => $now, 'updated_at' => $now,
                ]
            );
            $owners++;
        }

        $staff = 0;
        $orphans = [];

        if (Schema::hasColumn('users', 'owner_user_id')) {
            $rows = DB::table('users')
                ->whereNotNull('owner_user_id')
                ->get(['id', 'owner_user_id']);

            foreach ($rows as $user) {
                $ownerId = (int) $user->owner_user_id;

                // A staff row pointing at its own owner is the owner; skip.
                if ((int) $user->id === $ownerId) {
                    continue;
                }

                $businessId = $ownerToBusiness[$ownerId] ?? null;
                if (! $businessId) {
                    $orphans[] = (int) $user->id;

                    continue;
                }

                DB::table('business_memberships')->updateOrInsert(
                    ['business_id' => $businessId, 'user_id' => (int) $user->id],
                    [
                        // The specific role lives in the existing role system; a
                        // membership created here records belonging and full
                        // business scope, which is what owner_user_id meant. The
                        // assignment endpoints set a narrower role explicitly.
                        'role' => 'staff', 'scope' => 'all', 'location_ids' => null,
                        'status' => 'active', 'version' => 1,
                        'created_at' => $now, 'updated_at' => $now,
                    ]
                );
                $staff++;
            }
        }

        Log::info('Backfilled business memberships', [
            'owners' => $owners,
            'staff' => $staff,
            'orphaned_staff_user_ids' => $orphans,
        ]);

        if ($orphans) {
            // Visible in migrate output, not just the log.
            echo "  ! {$owners} owner(s), {$staff} staff migrated. "
                . count($orphans) . " staff user(s) have an owner_user_id with no business: "
                . implode(', ', array_slice($orphans, 0, 20)) . PHP_EOL;
        }
    }

    public function down(): void
    {
        if (Schema::hasTable('business_memberships')) {
            DB::table('business_memberships')->delete();
        }
    }
};
