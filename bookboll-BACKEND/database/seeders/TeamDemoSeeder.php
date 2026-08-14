<?php

namespace Database\Seeders;

use App\Models\Business;
use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;
use Nnjeim\World\Models\Country;
use Spatie\Permission\Models\Role;

/**
 * Demo data for apps/admin My Team (/team).
 *
 * Password for every demo account: 12345678
 *
 * Platform:
 *   super_admin@bookboll.com      (existing UserTableSeeder — role ensured)
 *   country_admin@bookboll.com
 *   platform_staff@bookboll.com
 *
 * Providers (Owner) + staff:
 *   owner.doha@bookboll.com       → @dohacourts  (Manager, Supervisor, Staff, Viewer)
 *   owner.dubai@bookboll.com      → @dubaiarena  (Manager + Staff)
 *   owner.pending@bookboll.com    → @pendinggym  (pending business, no staff)
 */
class TeamDemoSeeder extends Seeder
{
    private const PASSWORD = '12345678';

    public function run(): void
    {
        $this->ensureRolesExist();

        $qatar = $this->countryId('QA');
        $uae   = $this->countryId('AE');
        $ksa   = $this->countryId('SA');

        // ── Platform team ─────────────────────────────────────────────────────
        $this->upsertUser([
            'email'                => 'super_admin@bookboll.com',
            'name'                 => 'Super Admin',
            'role'                 => 'super_admin',
            'country_id'           => $qatar,
            'assigned_country_ids' => [],
        ]);

        $this->upsertUser([
            'email'                => 'admin@bookboll.com',
            'name'                 => 'Legacy Admin',
            'role'                 => 'admin',
            'country_id'           => $qatar,
            'assigned_country_ids' => [],
        ]);

        $this->upsertUser([
            'email'                => 'country_admin@bookboll.com',
            'name'                 => 'Country Admin Qatar',
            'role'                 => 'country_admin',
            'country_id'           => $qatar,
            'assigned_country_ids' => array_values(array_filter([$qatar])),
            'phone'                => '+97450001001',
        ]);

        $this->upsertUser([
            'email'                => 'platform_staff@bookboll.com',
            'name'                 => 'Platform Staff',
            'role'                 => 'platform_staff',
            'country_id'           => $uae,
            'assigned_country_ids' => array_values(array_filter([$uae, $qatar])),
            'phone'                => '+97150001002',
        ]);

        // ── Provider: Doha Courts (active) + full team ─────────────────────────
        $dohaOwner = $this->upsertUser([
            'email'      => 'owner.doha@bookboll.com',
            'name'       => 'Doha Courts Owner',
            'role'       => 'provider_admin',
            'country_id' => $qatar,
            'phone'      => '+97455510001',
        ]);

        $this->upsertBusiness($dohaOwner, [
            'name'           => 'Doha Courts',
            'username'       => 'dohacourts',
            'status'         => Business::STATUS_ACTIVE,
            'country_id'     => $qatar,
            'business_types' => ['sports_facility', 'academy'],
            'brand_name'     => 'Doha Courts',
            'brand_enabled'  => true,
            'brand_primary'  => '#0f766e',
            'brand_accent'   => '#14b8a6',
        ]);

        $this->upsertUser([
            'email'         => 'manager.doha@bookboll.com',
            'name'          => 'Sara Manager',
            'role'          => 'facility_admin',
            'country_id'    => $qatar,
            'owner_user_id' => $dohaOwner->id,
            'phone'         => '+97455510002',
        ]);

        $this->upsertUser([
            'email'         => 'supervisor.doha@bookboll.com',
            'name'          => 'Omar Supervisor',
            'role'          => 'provider_supervisor',
            'country_id'    => $qatar,
            'owner_user_id' => $dohaOwner->id,
            'phone'         => '+97455510003',
        ]);

        $this->upsertUser([
            'email'         => 'staff.doha@bookboll.com',
            'name'          => 'Ali Front Desk',
            'role'          => 'staff',
            'country_id'    => $qatar,
            'owner_user_id' => $dohaOwner->id,
            'phone'         => '+97455510004',
        ]);

        $this->upsertUser([
            'email'         => 'viewer.doha@bookboll.com',
            'name'          => 'Nora Viewer',
            'role'          => 'provider_viewer',
            'country_id'    => $qatar,
            'owner_user_id' => $dohaOwner->id,
        ]);

        // ── Provider: Dubai Arena (active) + lean team ─────────────────────────
        $dubaiOwner = $this->upsertUser([
            'email'      => 'owner.dubai@bookboll.com',
            'name'       => 'Dubai Arena Owner',
            'role'       => 'provider_admin',
            'country_id' => $uae,
            'phone'      => '+97155520001',
        ]);

        $this->upsertBusiness($dubaiOwner, [
            'name'           => 'Dubai Arena',
            'username'       => 'dubaiarena',
            'status'         => Business::STATUS_ACTIVE,
            'country_id'     => $uae,
            'business_types' => ['gym', 'classes'],
            'brand_name'     => 'Dubai Arena',
            'brand_enabled'  => true,
            'brand_primary'  => '#0369a1',
            'brand_accent'   => '#38bdf8',
        ]);

        $this->upsertUser([
            'email'         => 'manager.dubai@bookboll.com',
            'name'          => 'Layla Manager',
            'role'          => 'facility_admin',
            'country_id'    => $uae,
            'owner_user_id' => $dubaiOwner->id,
        ]);

        $this->upsertUser([
            'email'         => 'staff.dubai@bookboll.com',
            'name'          => 'Hassan Staff',
            'role'          => 'staff',
            'country_id'    => $uae,
            'owner_user_id' => $dubaiOwner->id,
        ]);

        // ── Provider: pending gym (no staff) ──────────────────────────────────
        $pendingOwner = $this->upsertUser([
            'email'      => 'owner.pending@bookboll.com',
            'name'       => 'Pending Gym Owner',
            'role'       => 'provider_admin',
            'country_id' => $ksa,
            'phone'      => '+96655530001',
        ]);

        $this->upsertBusiness($pendingOwner, [
            'name'           => 'Riyadh Fit Hub',
            'username'       => 'pendinggym',
            'status'         => Business::STATUS_PENDING,
            'country_id'     => $ksa,
            'business_types' => ['gym', 'other_venue'],
            'brand_name'     => 'Riyadh Fit Hub',
            'brand_subdomain' => 'pendinggym',
        ]);

        $this->command?->info('TeamDemoSeeder done. Login password for all demo users: ' . self::PASSWORD);
        $this->command?->table(
            ['Role / account', 'Email'],
            [
                ['Super Admin', 'super_admin@bookboll.com'],
                ['Country Admin', 'country_admin@bookboll.com'],
                ['Platform Staff', 'platform_staff@bookboll.com'],
                ['Owner (Doha)', 'owner.doha@bookboll.com'],
                ['Manager (Doha)', 'manager.doha@bookboll.com'],
                ['Owner (Dubai)', 'owner.dubai@bookboll.com'],
                ['Owner (Pending)', 'owner.pending@bookboll.com'],
            ]
        );
    }

    private function ensureRolesExist(): void
    {
        $guard = config('roles.guard', 'web');
        $names = array_unique(array_merge(
            config('roles.staff_roles', []),
            config('roles.provider_roles', []),
            config('roles.provider_team_roles', []),
            ['customer'],
        ));

        foreach ($names as $name) {
            Role::firstOrCreate(['name' => $name, 'guard_name' => $guard]);
        }
    }

    private function countryId(string $iso2): ?int
    {
        try {
            $id = Country::where('iso2', strtoupper($iso2))->value('id');

            return $id ? (int) $id : null;
        } catch (\Throwable) {
            return null;
        }
    }

    private function upsertUser(array $data): User
    {
        $email = strtolower($data['email']);
        $role  = $data['role'];

        $user = User::firstOrNew(['email' => $email]);
        $user->fill([
            'name'                 => $data['name'],
            'password'             => self::PASSWORD,
            'status'               => User::ACTIVE,
            'phone'                => $data['phone'] ?? $user->phone,
            'country_id'           => $data['country_id'] ?? null,
            'assigned_country_ids' => $data['assigned_country_ids'] ?? null,
            'owner_user_id'        => $data['owner_user_id'] ?? null,
            'last_login_at'        => $data['last_login_at'] ?? $user->last_login_at ?? now()->subDays(rand(0, 14)),
        ]);
        $user->save();

        if (! $user->hasRole($role)) {
            $user->syncRoles([$role]);
        }

        return $user->fresh();
    }

    private function upsertBusiness(User $owner, array $data): Business
    {
        $username = strtolower($data['username']);

        $business = Business::firstOrNew(['owner_id' => $owner->id]);
        $business->fill([
            'name'            => $data['name'],
            'username'        => $username,
            'status'          => $data['status'] ?? Business::STATUS_PENDING,
            'country_id'      => $data['country_id'] ?? $owner->country_id,
            'business_types'  => $data['business_types'] ?? [],
            'brand_enabled'   => $data['brand_enabled'] ?? false,
            'brand_subdomain' => $data['brand_subdomain'] ?? $username,
            'brand_name'      => $data['brand_name'] ?? $data['name'],
            'brand_primary'   => $data['brand_primary'] ?? null,
            'brand_accent'    => $data['brand_accent'] ?? null,
        ]);
        $business->save();

        return $business;
    }
}
