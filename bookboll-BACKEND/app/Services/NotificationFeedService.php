<?php

namespace App\Services;

use App\Notifications\AdminAlert;
use Illuminate\Support\Facades\DB;

/**
 * The dashboard notification bell.
 *
 * Notifications are stored (Laravel's `notifications` table) and written the
 * moment something happens — see App\Support\AdminAlerts. This service only
 * reads them back for the signed-in user, so the bell shows exactly what was
 * raised, and read state is shared across their devices.
 */
class NotificationFeedService
{
    private const LIMIT = 30;

    public function feed()
    {
        $user = auth()->user();
        if (! $user) {
            return sendSuccess('Notifications', ['items' => [], 'unread' => 0]);
        }

        $rows = $user->notifications()
            ->where('type', AdminAlert::class)
            ->latest('created_at')
            ->limit(self::LIMIT)
            ->get();

        return sendSuccess('Notifications', [
            'items'  => $rows->map(fn ($n) => [
                'id'    => (string) $n->id,
                'kind'  => $n->data['kind'] ?? 'info',
                'tone'  => $n->data['tone'] ?? 'info',
                'title' => $n->data['title'] ?? '',
                'sub'   => $n->data['sub'] ?? '',
                'to'    => $n->data['link'] ?? '/admin',
                'ts'    => optional($n->created_at)?->toIso8601String(),
                'read'  => (bool) $n->read_at,
            ])->values(),
            'unread' => $user->unreadNotifications()->where('type', AdminAlert::class)->count(),
        ]);
    }

    /** Mark every bell notification read (the "Mark all read" action). */
    public function markAllRead()
    {
        $user = auth()->user();
        if ($user) {
            $user->unreadNotifications()
                ->where('type', AdminAlert::class)
                ->update(['read_at' => now()]);
        }

        return sendSuccess('Marked read', ['unread' => 0]);
    }
}
