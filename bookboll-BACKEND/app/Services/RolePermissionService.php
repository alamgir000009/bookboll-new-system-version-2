<?php

namespace App\Services;

use App\Models\User;
use App\Support\RolePermissionMatrix;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

class RolePermissionService
{
    public function catalog()
    {
        return sendSuccess('Role permission catalog fetched successfully', RolePermissionMatrix::catalog());
    }

    public function index(Request $request)
    {
        $pivotTable = config('permission.table_names.model_has_roles', 'model_has_roles');
        $rolePivot  = config('permission.column_names.role_pivot_key') ?? 'role_id';

        $roles = Role::with('permissions:id,name')
            ->select('roles.*')
            ->selectSub(function ($query) use ($pivotTable, $rolePivot) {
                $query->from($pivotTable)
                    ->selectRaw('count(*)')
                    ->whereColumn("{$pivotTable}.{$rolePivot}", 'roles.id')
                    ->where("{$pivotTable}.model_type", User::class);
            }, 'users_count')
            ->when(
                $request->boolean('staff_only', true),
                fn($query) => $query->whereIn('name', config('roles.staff_roles', []))
            )
            ->orderBy('name')
            ->get();

        return sendSuccess(
            'Roles fetched successfully',
            ['roles' => RolePermissionMatrix::formatRoleSummary($roles)]
        );
    }

    public function show(string $roleName)
    {
        $role = $this->findRole($roleName);

        return sendSuccess('Role fetched successfully', [
            'role' => $this->rolePayload($role),
        ]);
    }

    public function store(Request $request)
    {
        $data = $request->validate(array_merge([
            'name' => ['required', 'string', 'max:100', 'regex:/^[a-z][a-z0-9_]*$/', 'unique:roles,name'],
        ], $this->permissionRules()));

        if (RolePermissionMatrix::isProtectedRole($data['name'])) {
            return sendError('This role name is reserved and cannot be created.', [], 422);
        }

        try {
            DB::beginTransaction();

            app()[PermissionRegistrar::class]->forgetCachedPermissions();

            $role = Role::create([
                'name'       => $data['name'],
                'guard_name' => $this->permissionGuard(),
            ]);
            $this->syncRolePermissions($role, $data);

            DB::commit();

            return sendSuccess('Role created successfully', [
                'role' => $this->rolePayload($role->fresh()->load('permissions:id,name')),
            ]);
        } catch (\Throwable $exception) {
            DB::rollBack();

            return sendError('Failed to create role: ' . $exception->getMessage(), [], 500);
        }
    }

    public function updatePermissions(Request $request, string $roleName)
    {
        $role = $this->findRole($roleName);

        $data = $request->validate($this->permissionRules());

        if ($role->name === 'super_admin' && empty($data['permissions'])) {
            return sendError('Super admin must keep at least one module assigned.', [], 422);
        }

        try {
            DB::beginTransaction();

            app()[PermissionRegistrar::class]->forgetCachedPermissions();

            $this->syncRolePermissions($role, $data);

            DB::commit();

            return sendSuccess('Role permissions updated successfully', [
                'role' => $this->rolePayload($role->fresh()->load('permissions:id,name')),
            ]);
        } catch (\Throwable $exception) {
            DB::rollBack();

            return sendError('Failed to update role permissions: ' . $exception->getMessage(), [], 500);
        }
    }

    public function destroy(string $roleName)
    {
        $role = $this->findRole($roleName);

        if (RolePermissionMatrix::isProtectedRole($role->name)) {
            return sendError('This role is protected and cannot be deleted.', [], 422);
        }

        if ($this->roleHasUsers($role)) {
            return sendError('Cannot delete a role that is assigned to users.', [], 422);
        }

        app()[PermissionRegistrar::class]->forgetCachedPermissions();

        $role->delete();

        return sendSuccess('Role deleted successfully');
    }

    protected function findRole(string $roleName): Role
    {
        return Role::where('name', $roleName)
            ->where('guard_name', $this->permissionGuard())
            ->with('permissions:id,name')
            ->firstOrFail();
    }

    protected function permissionGuard(): string
    {
        return config('roles.guard', 'web');
    }

    protected function permissionRules(): array
    {
        $modules      = config('roles.modules', []);
        $restrictions = config('roles.restrictions', []);

        return [
            'permissions'      => ['required', 'array', function ($attribute, $value, $fail) use ($modules) {
                $moduleActions = RolePermissionMatrix::moduleActions();

                foreach ($value as $module => $actions) {
                    if (! in_array($module, $modules, true)) {
                        $fail("Unknown module: {$module}.");
                        continue;
                    }

                    $allowed = $moduleActions[$module] ?? [];

                    foreach ((array) $actions as $action) {
                        if (! in_array($action, $allowed, true)) {
                            $fail("Invalid action '{$action}' for module '{$module}'.");
                        }
                    }
                }
            }],
            'restrictions'     => ['nullable', 'array'],
            'restrictions.*'   => ['nullable', 'string', 'in:' . implode(',', $restrictions)],
            'venue_restricted' => ['nullable', 'boolean'],
        ];
    }

    protected function syncRolePermissions(Role $role, array $payload): void
    {
        $permissionNames = RolePermissionMatrix::permissionNamesFromPayload($payload);
        $guardName       = $this->permissionGuard();

        $permissions = collect($permissionNames)->map(function (string $name) use ($guardName) {
            return Permission::findOrCreate($name, $guardName);
        });

        $role->syncPermissions($permissions);
    }

    protected function rolePayload(Role $role): array
    {
        $permissionNames = $role->permissions->pluck('name')->all();
        $payload         = RolePermissionMatrix::payloadFromPermissionNames($permissionNames);

        return [
            'id'               => $role->id,
            'name'             => $role->name,
            'label'            => str_replace('_', ' ', ucwords($role->name, '_')),
            'users_count'      => $role->users_count ?? $this->countRoleUsers($role->id),
            'is_protected'     => RolePermissionMatrix::isProtectedRole($role->name),
            'permission_names' => $permissionNames,
            'permissions'      => $payload['permissions'],
            'modules'          => $payload['modules'],
            'restrictions'     => $payload['restrictions'],
            'venue_restricted' => $payload['venue_restricted'],
        ];
    }

    protected function countRoleUsers(int $roleId): int
    {
        return DB::table(config('permission.table_names.model_has_roles', 'model_has_roles'))
            ->where(config('permission.column_names.role_pivot_key') ?? 'role_id', $roleId)
            ->where('model_type', User::class)
            ->count();
    }

    protected function roleHasUsers(Role $role): bool
    {
        return $this->countRoleUsers($role->id) > 0;
    }
}
