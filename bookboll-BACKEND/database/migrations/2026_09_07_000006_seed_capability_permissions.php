<?php

use App\Support\Capabilities;
use Illuminate\Database\Migrations\Migration;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

/**
 * Register the dashboard's capabilities as Spatie permissions and attach them to
 * every role, per App\Support\Capabilities::roleMap().
 *
 * Until now the provider roles existed with ZERO permissions, so a provider could
 * authenticate but was authorised for nothing. This gives each role exactly the
 * set the dashboard's lib/permissions.js grants it.
 *
 * Additive and idempotent: the older `module.action` permissions are left alone,
 * and only capability names are synced, so re-running cannot strip a role of its
 * legacy grants.
 */
return new class extends Migration
{
    public function up(): void
    {
        $guard = config('auth.defaults.guard', 'web');
        $caps  = Capabilities::all();

        foreach ($caps as $cap) {
            Permission::firstOrCreate(['name' => $cap, 'guard_name' => $guard]);
        }

        foreach (Capabilities::roleMap() as $roleName => $roleCaps) {
            $role = Role::where('name', $roleName)->where('guard_name', $guard)->first();
            if (! $role) {
                continue;
            }

            // Keep whatever non-capability permissions the role already holds.
            $keep = $role->permissions
                ->pluck('name')
                ->reject(fn ($n) => in_array($n, $caps, true))
                ->all();

            $role->syncPermissions(array_values(array_unique(array_merge($keep, $roleCaps))));
        }

        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }

    public function down(): void
    {
        $guard = config('auth.defaults.guard', 'web');

        foreach (Capabilities::all() as $cap) {
            Permission::where('name', $cap)->where('guard_name', $guard)->delete();
        }

        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }
};
