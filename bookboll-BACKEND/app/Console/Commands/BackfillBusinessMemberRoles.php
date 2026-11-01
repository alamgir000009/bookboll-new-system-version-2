<?php

namespace App\Console\Commands;

use App\Models\BusinessMembership;
use App\Models\User;
use Illuminate\Console\Command;
use Spatie\Permission\Models\Role;

/**
 * Give back the Spatie role to business members who were left without one.
 *
 * BusinessAssignmentService::commit() used to clear every role (`syncRoles([])`)
 * and then record the granted role on the membership row only. Nothing
 * authorises off business_memberships.role — hasAnyRole, getAllPermissions,
 * Capabilities and AccessScope all read Spatie — so those accounts verified
 * their password and were then turned away by the dashboard's own role gate.
 * The service is fixed; this repairs the accounts moved before that.
 *
 * The membership row is the authority for what the role should be: it is what
 * the actor picked, and it was written in the same transaction as the move.
 * Only an account with NO role at all is touched, so a member who kept or was
 * since given a role is never re-roled by a second run.
 *
 * Dry run by default; pass --apply to write.
 */
class BackfillBusinessMemberRoles extends Command
{
    protected $signature = 'business:backfill-member-roles
                            {--apply : Assign the roles (default is a dry run)}';

    protected $description = 'Restore Spatie roles for business members left roleless by a business move';

    /**
     * `owner` is a membership-only role — ASSIGNABLE_ROLES excludes it and there
     * is no Spatie row for it. It means the same thing provider_admin does on the
     * Spatie side: the account that runs the business.
     */
    protected const ROLE_MAP = [BusinessMembership::ROLE_OWNER => 'provider_admin'];

    public function handle(): int
    {
        $apply = (bool) $this->option('apply');

        $memberships = BusinessMembership::query()
            ->where('status', BusinessMembership::STATUS_ACTIVE)
            ->orderBy('business_id')
            ->orderBy('id')
            ->get();

        if ($memberships->isEmpty()) {
            $this->info('No active business memberships.');

            return self::SUCCESS;
        }

        $fixed = 0;
        $kept = 0;
        $problems = [];

        foreach ($memberships as $membership) {
            $user = User::find($membership->user_id);

            if (! $user) {
                $problems[] = "membership {$membership->id}: user {$membership->user_id} no longer exists";
                continue;
            }

            // Already has a role of any kind — leave it alone. Re-roling here would
            // overwrite a deliberate change made after the move.
            if ($user->roles()->count() > 0) {
                $kept++;
                continue;
            }

            $role = self::ROLE_MAP[$membership->role] ?? $membership->role;

            if (! Role::where('name', $role)->exists()) {
                $problems[] = "user {$user->id} ({$user->email}): membership role '{$membership->role}' has no Spatie role";
                continue;
            }

            $this->line(sprintf(
                '%s  business %-4d user %-7d %-38s -> %s',
                $apply ? 'FIX ' : 'PLAN',
                $membership->business_id,
                $user->id,
                $user->email,
                $role
            ));

            if ($apply) {
                $user->assignRole($role);
            }

            $fixed++;
        }

        $this->newLine();
        $this->info(sprintf(
            '%s: %d   already had a role: %d',
            $apply ? 'Assigned' : 'Would assign',
            $fixed,
            $kept
        ));

        foreach ($problems as $problem) {
            $this->warn("PROBLEM: {$problem}");
        }

        if (! $apply && $fixed > 0) {
            $this->newLine();
            $this->comment('Dry run. Re-run with --apply to write.');
            $this->comment('These accounts must sign in again — the move deleted their tokens.');
        }

        return self::SUCCESS;
    }
}
