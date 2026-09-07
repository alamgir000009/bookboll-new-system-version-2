<?php

use Illuminate\Database\Migrations\Migration;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

/**
 * The provider-side roles the code already assumes exist.
 *
 * BusinessService::register() calls assignRole('provider_admin') for every new
 * provider, and UserService ranks the full provider ladder — but only
 * admin/customer/guard/junior_employee/senior_employee/super_admin were ever
 * seeded. The missing rows made every business signup fail with
 * "Could not create business account" (RoleDoesNotExist, caught and reported).
 *
 * Idempotent: firstOrCreate, so it is safe on an environment that already has them.
 */
return new class extends Migration
{
    /** Roles referenced by UserService::pickPrimaryRole and the admin portal. */
    private const ROLES = [
        // Platform
        'country_admin',
        'platform_staff',
        // Provider ladder — owner first, then their team
        'provider_admin',
        'facility_admin',
        'provider_supervisor',
        'staff',
        'provider_viewer',
    ];

    public function up(): void
    {
        $guard = config('auth.defaults.guard', 'web');

        foreach (self::ROLES as $name) {
            Role::firstOrCreate(['name' => $name, 'guard_name' => $guard]);
        }

        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }

    public function down(): void
    {
        // Left in place deliberately: users may already hold these roles, and
        // dropping them would silently strip access.
    }
};
