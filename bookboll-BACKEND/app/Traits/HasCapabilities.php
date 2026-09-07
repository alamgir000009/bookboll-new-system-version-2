<?php

namespace App\Traits;

use App\Support\Capabilities;

/**
 * Capability checks that mirror lib/permissions.js `can(cap, role, overrides)`.
 *
 * Resolution order, exactly as the dashboard does it:
 *   1. super_admin (and the legacy `admin`) always pass — an override must never
 *      be able to lock the owner out.
 *   2. an explicit revoke wins over the role's base set.
 *   3. the role's base set.
 *   4. an explicit grant (My Team's "Advanced — custom permissions").
 *
 * Deliberately NOT named can(): Illuminate\Foundation\Auth\Access\Authorizable
 * already defines that for Gate/Policy checks, and shadowing it would break them.
 */
trait HasCapabilities
{
    /** @var string[]|null memoised per request */
    protected ?array $capabilityCache = null;

    public function hasCapability(?string $cap): bool
    {
        if (! $cap) {
            return false;
        }

        if ($this->hasAnyRole(Capabilities::globalRoles())) {
            return true;
        }

        $overrides = $this->capabilityOverrides();

        if (in_array($cap, $overrides['revoke'], true)) {
            return false;
        }

        if (in_array($cap, $this->roleCapabilities(), true)) {
            return true;
        }

        return in_array($cap, $overrides['grant'], true);
    }

    /** True when the user holds ANY of these. Mirrors an array in PAGE_CAP. */
    public function hasAnyCapability(array $caps): bool
    {
        foreach ($caps as $cap) {
            if ($this->hasCapability($cap)) {
                return true;
            }
        }

        return false;
    }

    /**
     * The effective capability list, role base plus overrides — what /me hands
     * the dashboard so its can() checks agree with the API's.
     *
     * @return string[]
     */
    public function capabilities(): array
    {
        if ($this->capabilityCache !== null) {
            return $this->capabilityCache;
        }

        if ($this->hasAnyRole(Capabilities::globalRoles())) {
            return $this->capabilityCache = Capabilities::all();
        }

        $overrides = $this->capabilityOverrides();
        $revoke = $overrides['revoke'];

        $set = array_diff($this->roleCapabilities(), $revoke);
        foreach ($overrides['grant'] as $g) {
            if (! in_array($g, $revoke, true)) {
                $set[] = $g;
            }
        }

        // Stable order, matching Capabilities::all().
        return $this->capabilityCache = array_values(
            array_filter(Capabilities::all(), fn ($c) => in_array($c, $set, true))
        );
    }

    /** The capabilities this user's ROLES grant, ignoring overrides. @return string[] */
    protected function roleCapabilities(): array
    {
        $all = Capabilities::all();

        return $this->getAllPermissions()
            ->pluck('name')
            ->filter(fn ($n) => in_array($n, $all, true))
            ->unique()
            ->values()
            ->all();
    }

    /** @return array{grant: string[], revoke: string[]} */
    public function capabilityOverrides(): array
    {
        $raw = $this->custom_permissions;
        if (is_string($raw)) {
            $raw = json_decode($raw, true);
        }
        if (! is_array($raw)) {
            return ['grant' => [], 'revoke' => []];
        }

        $clean = function ($v) {
            return is_array($v)
                ? array_values(array_filter($v, fn ($x) => is_string($x) && $x !== ''))
                : [];
        };

        return [
            'grant'  => $clean($raw['grant'] ?? []),
            'revoke' => $clean($raw['revoke'] ?? []),
        ];
    }
}
