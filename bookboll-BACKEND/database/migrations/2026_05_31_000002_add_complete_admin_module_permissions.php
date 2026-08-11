<?php

use App\Support\RolePermissionMatrix;
use Illuminate\Database\Migrations\Migration;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

return new class extends Migration
{
    public function up(): void
    {
        $guard = config('roles.guard', 'web');

        app()[PermissionRegistrar::class]->forgetCachedPermissions();

        foreach (RolePermissionMatrix::fullPermissionNames() as $name) {
            Permission::findOrCreate($name, $guard);
        }

        app()[PermissionRegistrar::class]->forgetCachedPermissions();

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
        // Permission records are shared; no destructive rollback.
    }
};
