<?php

use App\Support\RolePermissionMatrix;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

return new class extends Migration
{
    public function up(): void
    {
        $guard = config('roles.guard', 'web');

        app()[PermissionRegistrar::class]->forgetCachedPermissions();

        // Rename legacy "<module>.read" permissions to "<module>.view".
        foreach (config('roles.modules', []) as $module) {
            $legacy = "{$module}.read";
            $target = "{$module}.view";

            $existingTarget = Permission::where('name', $target)->where('guard_name', $guard)->first();

            if ($existingTarget) {
                // Target already exists: drop the legacy duplicate.
                Permission::where('name', $legacy)->where('guard_name', $guard)->delete();
                continue;
            }

            Permission::where('name', $legacy)
                ->where('guard_name', $guard)
                ->update(['name' => $target]);
        }

        // Ensure every catalog permission exists.
        foreach (RolePermissionMatrix::fullPermissionNames() as $name) {
            Permission::findOrCreate($name, $guard);
        }

        app()[PermissionRegistrar::class]->forgetCachedPermissions();

        // Re-sync super_admin to the full catalog.
        $superAdmin = Role::where('name', 'super_admin')->where('guard_name', $guard)->first();

        if ($superAdmin) {
            $permissions = Permission::whereIn('name', RolePermissionMatrix::fullPermissionNames())
                ->where('guard_name', $guard)
                ->get();

            $superAdmin->syncPermissions($permissions);
        }

        app()[PermissionRegistrar::class]->forgetCachedPermissions();
    }

    public function down(): void
    {
        $guard = config('roles.guard', 'web');

        app()[PermissionRegistrar::class]->forgetCachedPermissions();

        // Reverse the "<module>.view" rename back to "<module>.read".
        foreach (config('roles.modules', []) as $module) {
            $view = "{$module}.view";
            $read = "{$module}.read";

            $existingRead = Permission::where('name', $read)->where('guard_name', $guard)->first();

            if ($existingRead) {
                continue;
            }

            Permission::where('name', $view)
                ->where('guard_name', $guard)
                ->update(['name' => $read]);
        }

        app()[PermissionRegistrar::class]->forgetCachedPermissions();
    }
};
