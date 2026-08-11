<?php

use Illuminate\Database\Migrations\Migration;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

return new class extends Migration
{
    /**
     * Matches RolePermissionTableSeeder super_admin actions for the locations module.
     *
     * @var list<string>
     */
    private array $permissionNames = [
        'locations.create',
        'locations.read',
        'locations.update',
        'locations.delete',
        'locations.manage_users',
        'locations.system_settings',
    ];

    public function up(): void
    {
        app()[PermissionRegistrar::class]->forgetCachedPermissions();

        $role = Role::where('name', 'super_admin')->first();

        if (! $role) {
            return;
        }

        foreach ($this->permissionNames as $permissionName) {
            $permission = Permission::firstOrCreate(['name' => $permissionName]);

            if (! $role->hasPermissionTo($permission)) {
                $role->givePermissionTo($permission);
            }
        }
    }

    public function down(): void
    {
        app()[PermissionRegistrar::class]->forgetCachedPermissions();

        $role = Role::where('name', 'super_admin')->first();

        foreach ($this->permissionNames as $permissionName) {
            $permission = Permission::where('name', $permissionName)->first();

            if (! $permission) {
                continue;
            }

            if ($role) {
                $role->revokePermissionTo($permission);
            }

            $permission->delete();
        }
    }
};
