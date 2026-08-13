<?php

namespace App\Support;

use Illuminate\Support\Collection;

class RolePermissionMatrix
{
    /**
     * Full per-module action catalog. Modules without an explicit
     * entry in config fall back to the standard CRUD action set.
     */
    public static function moduleActions(): array
    {
        $modules        = config('roles.modules', []);
        $standard       = config('roles.standard_actions', []);
        $moduleActions  = config('roles.module_actions', []);

        $catalog = [];

        foreach ($modules as $module) {
            $catalog[$module] = array_values($moduleActions[$module] ?? $standard);
        }

        return $catalog;
    }

    public static function actionsForModule(string $module): array
    {
        return self::moduleActions()[$module] ?? config('roles.standard_actions', []);
    }

    /**
     * Every "module.action" permission name in the catalog.
     */
    public static function fullPermissionNames(): array
    {
        $names = [];

        foreach (self::moduleActions() as $module => $actions) {
            foreach ($actions as $action) {
                $names[] = "{$module}.{$action}";
            }
        }

        return array_values(array_unique($names));
    }

    public static function catalog(): array
    {
        return [
            'modules'          => config('roles.modules', []),
            'module_labels'    => config('roles.module_labels', []),
            'module_actions'   => self::moduleActions(),
            'action_labels'    => config('roles.action_labels', []),
            'restrictions'     => config('roles.restrictions', []),
            'staff_roles'          => config('roles.staff_roles', []),
            'provider_roles'       => config('roles.provider_roles', []),
            'platform_team_roles'  => config('roles.platform_team_roles', []),
            'provider_team_roles'  => config('roles.provider_team_roles', []),
            'protected_roles'      => config('roles.protected_roles', []),
            'meta_permissions'     => config('roles.meta_permissions', []),
        ];
    }

    /**
     * Convert a structured role payload into flat permission names.
     *
     * Expected payload keys:
     *  - permissions: map of module => [actions], or the literal string "all"
     *  - restrictions: map of module => restriction
     *  - venue_restricted: bool
     */
    public static function permissionNamesFromPayload(array $payload): array
    {
        $permissionsInput = $payload['permissions'] ?? [];
        $restrictions     = $payload['restrictions'] ?? [];
        $venueRestricted  = (bool) ($payload['venue_restricted'] ?? false);

        $moduleActions = self::moduleActions();
        $permissions   = [];

        if ($permissionsInput === 'all') {
            $permissions = self::fullPermissionNames();
        } elseif (is_array($permissionsInput)) {
            foreach ($permissionsInput as $module => $actions) {
                $allowed = $moduleActions[$module] ?? config('roles.standard_actions', []);

                foreach ((array) $actions as $action) {
                    if (in_array($action, $allowed, true)) {
                        $permissions[] = "{$module}.{$action}";
                    }
                }
            }
        }

        foreach ($restrictions as $module => $restriction) {
            if ($module && $restriction) {
                $permissions[] = "{$module}.{$restriction}";
            }
        }

        if ($venueRestricted) {
            $permissions[] = config('roles.meta_permissions.venue_restricted', 'meta.venue_restricted');
        }

        return array_values(array_unique($permissions));
    }

    /**
     * Rebuild a structured payload from flat permission names.
     */
    public static function payloadFromPermissionNames(array $permissionNames): array
    {
        $moduleActions       = self::moduleActions();
        $catalogRestrictions = config('roles.restrictions', []);
        $venueMeta           = config('roles.meta_permissions.venue_restricted', 'meta.venue_restricted');

        $permissions     = [];
        $restrictions    = [];
        $venueRestricted = in_array($venueMeta, $permissionNames, true);

        foreach ($permissionNames as $permission) {
            if ($permission === $venueMeta || ! str_contains($permission, '.')) {
                continue;
            }

            [$module, $suffix] = explode('.', $permission, 2);

            $allowed = $moduleActions[$module] ?? config('roles.standard_actions', []);

            if (in_array($suffix, $allowed, true)) {
                $permissions[$module][] = $suffix;
                continue;
            }

            if (in_array($suffix, $catalogRestrictions, true)) {
                $restrictions[$module] = $suffix;
            }
        }

        foreach ($permissions as $module => $actions) {
            $permissions[$module] = array_values(array_unique($actions));
        }

        return [
            'permissions'      => $permissions,
            'modules'          => array_keys($permissions),
            'restrictions'     => $restrictions,
            'venue_restricted' => $venueRestricted,
        ];
    }

    public static function accessFromPermissionNames(array $permissionNames): array
    {
        return self::payloadFromPermissionNames($permissionNames);
    }

    public static function isProtectedRole(string $roleName): bool
    {
        return in_array($roleName, config('roles.protected_roles', []), true);
    }

    public static function isStaffRole(string $roleName): bool
    {
        return in_array($roleName, config('roles.staff_roles', []), true);
    }

    public static function formatRoleSummary(Collection $roles): Collection
    {
        return $roles->map(function ($role) {
            $permissionNames = $role->permissions->pluck('name')->all();
            $payload         = self::payloadFromPermissionNames($permissionNames);

            return [
                'id'                => $role->id,
                'name'              => $role->name,
                'label'             => str_replace('_', ' ', ucwords($role->name, '_')),
                'users_count'       => $role->users_count ?? 0,
                'permissions_count' => count($permissionNames),
                'venue_restricted'  => $payload['venue_restricted'],
                'is_protected'      => self::isProtectedRole($role->name),
            ];
        });
    }
}
