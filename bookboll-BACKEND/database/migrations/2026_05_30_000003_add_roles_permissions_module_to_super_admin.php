<?php

use Illuminate\Database\Migrations\Migration;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

return new class extends Migration
{
    private array $permissionNames = [
        'roles-permissions.create',
        'roles-permissions.read',
        'roles-permissions.update',
        'roles-permissions.delete',
        'roles-permissions.manage_users',
        'roles-permissions.system_settings',
    ];

    public function up(): void
    {
        app()[PermissionRegistrar::class]->forgetCachedPermissions();

        $role = Role::query()->where('name', 'super_admin')->first();

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

        $role = Role::query()->where('name', 'super_admin')->first();

        foreach ($this->permissionNames as $permissionName) {
            $permission = Permission::query()->where('name', $permissionName)->first();

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
