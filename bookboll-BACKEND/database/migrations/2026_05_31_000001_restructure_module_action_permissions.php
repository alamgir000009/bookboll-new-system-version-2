<?php

use App\Support\RolePermissionMatrix;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Spatie\Permission\PermissionRegistrar;

return new class extends Migration
{
    public function up(): void
    {
        $guard = config('roles.guard', 'web');
        $now = now();

        // Rename legacy "<module>.read" permissions to "<module>.view".
        foreach (config('roles.modules', []) as $module) {
            $legacy = "{$module}.read";
            $target = "{$module}.view";

            $existingTarget = DB::table('permissions')
                ->where('name', $target)
                ->where('guard_name', $guard)
                ->exists();

            if ($existingTarget) {
                DB::table('permissions')
                    ->where('name', $legacy)
                    ->where('guard_name', $guard)
                    ->delete();
                continue;
            }

            DB::table('permissions')
                ->where('name', $legacy)
                ->where('guard_name', $guard)
                ->update(['name' => $target, 'updated_at' => $now]);
        }

        $names = RolePermissionMatrix::fullPermissionNames();
        $existing = DB::table('permissions')
            ->where('guard_name', $guard)
            ->whereIn('name', $names)
            ->pluck('name')
            ->all();
        $existing = array_flip($existing);

        $insert = [];
        foreach ($names as $name) {
            if (! isset($existing[$name])) {
                $insert[] = [
                    'name'       => $name,
                    'guard_name' => $guard,
                    'created_at' => $now,
                    'updated_at' => $now,
                ];
            }
        }
        if ($insert) {
            DB::table('permissions')->insert($insert);
        }

        $superAdmin = DB::table('roles')
            ->where('name', 'super_admin')
            ->where('guard_name', $guard)
            ->first();

        if ($superAdmin) {
            $permissionIds = DB::table('permissions')
                ->whereIn('name', $names)
                ->where('guard_name', $guard)
                ->pluck('id');

            $rows = $permissionIds->map(fn ($id) => [
                'permission_id' => $id,
                'role_id'       => $superAdmin->id,
            ])->all();

            foreach (array_chunk($rows, 100) as $chunk) {
                DB::table('role_has_permissions')->insertOrIgnore($chunk);
            }
        }

        app()[PermissionRegistrar::class]->forgetCachedPermissions();
    }

    public function down(): void
    {
        $guard = config('roles.guard', 'web');

        foreach (config('roles.modules', []) as $module) {
            $view = "{$module}.view";
            $read = "{$module}.read";

            $existingRead = DB::table('permissions')
                ->where('name', $read)
                ->where('guard_name', $guard)
                ->exists();

            if ($existingRead) {
                continue;
            }

            DB::table('permissions')
                ->where('name', $view)
                ->where('guard_name', $guard)
                ->update(['name' => $read, 'updated_at' => now()]);
        }

        app()[PermissionRegistrar::class]->forgetCachedPermissions();
    }
};
