<?php

use Illuminate\Database\Migrations\Migration;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

return new class extends Migration
{
    public function up(): void
    {
        app()[PermissionRegistrar::class]->forgetCachedPermissions();

        $metaPermission = Permission::firstOrCreate([
            'name' => config('roles.meta_permissions.venue_restricted', 'meta.venue_restricted'),
        ]);

        foreach (config('roles.defaults', []) as $roleName => $details) {
            if (empty($details['venue_restricted'])) {
                continue;
            }

            $role = Role::query()->where('name', $roleName)->first();

            if ($role && ! $role->hasPermissionTo($metaPermission)) {
                $role->givePermissionTo($metaPermission);
            }
        }
    }

    public function down(): void
    {
        app()[PermissionRegistrar::class]->forgetCachedPermissions();

        $metaPermission = Permission::query()->where(
            'name',
            config('roles.meta_permissions.venue_restricted', 'meta.venue_restricted')
        )->first();

        if (! $metaPermission) {
            return;
        }

        foreach (config('roles.defaults', []) as $roleName => $details) {
            if (empty($details['venue_restricted'])) {
                continue;
            }

            $role = Role::query()->where('name', $roleName)->first();

            if ($role) {
                $role->revokePermissionTo($metaPermission);
            }
        }
    }
};
