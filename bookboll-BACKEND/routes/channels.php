<?php

use Illuminate\Support\Facades\Broadcast;

/*
|--------------------------------------------------------------------------
| Broadcast Channels
|--------------------------------------------------------------------------
|
| Here you may register all of the event broadcasting channels that your
| application supports. The given channel authorization callbacks are
| used to check if an authenticated user can listen to the channel.
|
*/

/**
 * Per-user "refresh" channel.
 *
 * Wire name on the client: private-refresh.{userId}
 *
 * Only the authenticated user whose ID matches {userId} may subscribe,
 * which guarantees a staff member only ever receives refreshes meant for them.
 */
Broadcast::channel('refresh.{userId}', function ($user, $userId) {
    return (int) $user->id === (int) $userId;
});
