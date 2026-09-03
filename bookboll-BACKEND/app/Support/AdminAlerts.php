<?php

namespace App\Support;

use App\Events\AdminAppRefresh;
use App\Models\User;
use App\Models\Venue;
use App\Notifications\AdminAlert;
use Illuminate\Support\Facades\Log;

/**
 * Raise a dashboard-bell notification.
 *
 * Every method stores the notification and pushes a live refresh over Pusher, so
 * the bell updates without a reload. All of it is best-effort — a notification
 * must never break the action that triggered it.
 */
class AdminAlerts
{
    /** Bookboll staff who should see platform-wide alerts. */
    private const PLATFORM_ROLES = ['super_admin', 'admin', 'country_admin', 'platform_staff'];

    /** Send to specific users. */
    public static function toUsers($users, string $kind, string $tone, string $title, string $sub = '', string $link = '/admin'): void
    {
        try {
            foreach (collect($users)->filter() as $user) {
                $user->notify(new AdminAlert($kind, $tone, $title, $sub, $link));
                broadcast(new AdminAppRefresh((int) $user->id, ['reason' => 'notification']));
            }
        } catch (\Throwable $e) {
            Log::error('AdminAlerts failed: '.$e->getMessage(), ['title' => $title]);
        }
    }

    /** Send to one user id (the provider owner, a staff member, …). */
    public static function toUser(?int $userId, string $kind, string $tone, string $title, string $sub = '', string $link = '/admin'): void
    {
        if (! $userId) {
            return;
        }

        self::toUsers(User::where('id', $userId)->get(), $kind, $tone, $title, $sub, $link);
    }

    /** Send to Bookboll staff — country admins only for their own countries. */
    public static function toPlatform(string $kind, string $tone, string $title, string $sub = '', string $link = '/approvals', ?int $countryId = null): void
    {
        self::toUsers(self::platformStaff($countryId), $kind, $tone, $title, $sub, $link);
    }

    /** Bookboll staff who should receive platform alerts for this country. */
    public static function platformStaff(?int $countryId = null)
    {
        return User::whereHas('roles', fn ($q) => $q->whereIn('name', self::PLATFORM_ROLES))
            ->get()
            ->filter(function (User $u) use ($countryId) {
                if (! $countryId || $u->hasRole(['super_admin', 'admin'])) {
                    return true;
                }
                $assigned = $u->assigned_country_ids ?: [];

                return ! $assigned || in_array($countryId, array_map('intval', $assigned), true);
            });
    }

    /** Send to whoever owns the venue (their team sees it via the owner's feed). */
    public static function toVenueOwner(?int $venueId, string $kind, string $tone, string $title, string $sub = '', string $link = '/admin'): void
    {
        if (! $venueId) {
            return;
        }

        self::toUser((int) Venue::where('id', $venueId)->value('owner_user_id') ?: null, $kind, $tone, $title, $sub, $link);
    }
}
