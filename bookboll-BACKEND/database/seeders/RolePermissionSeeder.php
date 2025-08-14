<?php

namespace Database\Seeders;

use App\Models\User;
use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;

class RolePermissionSeeder extends Seeder
{
    public function run(): void
    {
        // Create roles
        $adminRole = Role::create(['name' => 'superadmin']);
        $adminRole = Role::create(['name' => 'admin']);
        $userRole = Role::create(['name' => 'customer']);

        // Create permissions
        $editPermission = Permission::create(['name' => 'edit articles']);
        $viewPermission = Permission::create(['name' => 'view articles']);

        // Assign permissions to roles
        $adminRole->givePermissionTo($editPermission, $viewPermission);
        $userRole->givePermissionTo($viewPermission);

        // Assign role to user
        $user = User::find(1);
        $user->assignRole('admin');
    }
}
