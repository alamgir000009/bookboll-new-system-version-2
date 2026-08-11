<?php
namespace Database\Seeders;

use App\Models\User;
use App\Support\RolePermissionMatrix;
use Illuminate\Database\Seeder;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;

class RolePermissionTableSeeder extends Seeder
{
    public function run(): void
    {
        $guard           = config('roles.guard', 'web');
        $rolePermissions = config('roles.defaults', []);

        foreach ($rolePermissions as $roleName => $details) {
            $role = Role::firstOrCreate([
                'name'       => $roleName,
                'guard_name' => $guard,
            ]);

            $permissionNames = RolePermissionMatrix::permissionNamesFromPayload([
                'permissions'      => $details['permissions'] ?? [],
                'restrictions'     => $details['restrictions'] ?? [],
                'venue_restricted' => $details['venue_restricted'] ?? false,
            ]);

            $permissions = collect($permissionNames)->map(
                fn (string $name) => Permission::findOrCreate($name, $guard)
            );

            $role->givePermissionTo($permissions);
        }

        if ($user = User::find(1)) {
            $user->assignRole('super_admin');
        }

        if ($user = User::find(2)) {
            $user->assignRole('admin');
        }
    }
}
