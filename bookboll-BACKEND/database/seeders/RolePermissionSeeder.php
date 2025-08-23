<?php
namespace Database\Seeders;

use App\Models\User;
use Illuminate\Database\Seeder;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;

class RolePermissionSeeder extends Seeder
{
    public function run(): void
    {
        $rolePermissions = [
            'super_admin'     => [
                'modules'         => [
                    'dashboard', 'calendar', 'bookings', 'payments', 'venues', 'listings',
                    'special-days', 'resources', 'add-ons', 'promocodes', 'loyalty',
                    'sports-categories', 'users', 'customers', 'settings', 'invoices', 'audit-logs',
                ],
                'actions'         => [
                    'create', 'read', 'update', 'delete', 'manage_users', 'system_settings',
                ],
                'venueRestricted' => false,
            ],

            'admin'           => [
                'modules'         => [
                    'dashboard', 'calendar', 'bookings', 'payments', 'venues', 'listings',
                    'special-days', 'resources', 'add-ons', 'promocodes', 'loyalty',
                    'sports-categories', 'users', 'customers', 'invoices',
                ],
                'actions'         => [
                    'create', 'read', 'update', 'delete', 'manage_employees',
                ],
                'venueRestricted' => false,
                'restrictions'    => [
                    'users'      => 'cannot_manage_super_admin',
                    'settings'   => 'view_only',
                    'audit-logs' => 'view_only',
                ],
            ],

            'senior_employee' => [
                'modules'         => [
                    'dashboard', 'calendar', 'bookings', 'customers', 'payments',
                    'promocodes', 'loyalty',
                ],
                'actions'         => [
                    'create', 'read', 'update', 'cancel_bookings', 'manage_customers',
                ],
                'venueRestricted' => true,
                'restrictions'    => [
                    'venues'   => 'view_only',
                    'listings' => 'view_only',
                    'payments' => 'view_only',
                ],
            ],

            'junior_employee' => [
                'modules'         => [
                    'calendar', 'bookings', 'customers',
                ],
                'actions'         => [
                    'create', 'read', 'update_basic',
                ],
                'venueRestricted' => true,
                'restrictions'    => [
                    'customers' => 'view_and_create_only',
                    'bookings'  => 'no_financial_data',
                ],
            ],

            'guard'           => [
                'modules'         => [
                    'calendar', 'bookings',
                ],
                'actions'         => [
                    'read',
                ],
                'venueRestricted' => true,
                'restrictions'    => [
                    'bookings' => 'verification_view_only',
                    'calendar' => 'read_only',
                ],
            ],
        ];

        foreach ($rolePermissions as $roleName => $details) {
            $role = Role::firstOrCreate(['name' => $roleName]);

            foreach ($details['modules'] as $module) {
                foreach ($details['actions'] as $action) {
                    $permName   = "{$module}.{$action}";
                    $permission = Permission::firstOrCreate(['name' => $permName]);
                    $role->givePermissionTo($permission);
                }
            }

            if (isset($details['restrictions'])) {
                foreach ($details['restrictions'] as $module => $restriction) {
                    $permName   = "{$module}.{$restriction}";
                    $permission = Permission::firstOrCreate(['name' => $permName]);
                    $role->givePermissionTo($permission);
                }
            }
        }

        $user = User::find(1);
        $user->assignRole('super_admin');
    }
}
