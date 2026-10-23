<?php

namespace App\Console\Commands;

use App\Models\OnesignalSubscription;
use App\Models\User;
use App\Models\Venue;
use App\Support\Capabilities;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Why did (or didn't) someone get a push for this venue?
 *
 * A push that never arrives has four places to die, and from the outside they
 * all look identical — silence. This walks them in order and says which one it
 * was, so nobody has to guess again:
 *
 *   1. the venue is not linked to the business   (venues.owner_user_id / business_id)
 *   2. the user is not in the recipient list     (role not resolved — the original bug)
 *   3. the user has no active device registered  (nothing to send to)
 *   4. OneSignal is not configured               (no app id / key)
 *
 * Read-only. Sends nothing.
 *
 *   php artisan notifications:diagnose 17
 *   php artisan notifications:diagnose 17 --email=lahore@yopmail.com
 */
class DiagnoseVenueNotifications extends Command
{
    protected $signature = 'notifications:diagnose
                            {venue : Venue id}
                            {--email= : Focus on one user, by email}';

    protected $description = 'Explain who would receive a push notification for a venue, and why someone would not';

    public function handle(): int
    {
        $venueId = (int) $this->argument('venue');

        $venue = Venue::find($venueId);

        if (! $venue) {
            $this->error("Venue {$venueId} not found.");

            return self::FAILURE;
        }

        $this->info("Venue {$venue->id}: {$venue->name}");

        // ── 1. Schema and venue → business wiring ──────────────────────────
        $hasBusinessId = schemaHas('venues', 'business_id');

        $this->line('  owner_user_id : ' . var_export($venue->owner_user_id, true));
        $this->line('  business_id   : ' . ($hasBusinessId
            ? var_export($venue->business_id, true)
            : 'column not on this schema'));
        $this->line('  country / city: ' . var_export($venue->country_id, true) . ' / ' . var_export($venue->city_id, true));

        if (! $venue->owner_user_id && ! ($hasBusinessId && $venue->business_id)) {
            $this->warn('  This venue is not linked to any business, so only platform admins can be notified.');
        }

        // ── 2. Recipient resolution ────────────────────────────────────────
        $recipientIds = venueNotificationRecipientIds($venue->id);

        $this->newLine();
        $this->info('Recipients resolved: ' . count($recipientIds));

        if (! $recipientIds) {
            $this->error('  Nobody resolved. Nothing will be sent to anyone.');

            return self::FAILURE;
        }

        // ── 3. Device registration, per recipient ──────────────────────────
        $subsByUser = OnesignalSubscription::whereIn('user_id', $recipientIds)
            ->where('is_active', true)
            ->get(['user_id', 'device_type'])
            ->groupBy('user_id');

        $rows = [];

        foreach ($recipientIds as $id) {
            $user = User::find($id);
            $subs = $subsByUser->get($id);

            $rows[] = [
                $id,
                $user?->email ?? '(missing user)',
                $user?->roles->pluck('name')->implode(',') ?? '',
                $subs ? $subs->count() : 0,
                $subs ? $subs->pluck('device_type')->unique()->sort()->implode('/') : '—',
            ];
        }

        $this->table(['user', 'email', 'roles', 'devices', 'platforms'], $rows);

        $noDevice = array_values(array_filter($rows, fn ($r) => $r[3] === 0));

        if ($noDevice) {
            $this->warn('These recipients resolve correctly but have NO active device, so they receive nothing:');
            foreach ($noDevice as $r) {
                $this->line("  {$r[0]}  {$r[1]}");
            }
            $this->line('  They need to open the app and log in again so it re-registers the device.');
        }

        // ── 4. Transport configuration ─────────────────────────────────────
        $this->newLine();
        $appId = trim((string) config('services.onesignal.app_id'));
        $key   = trim((string) config('services.onesignal.rest_api_key'));

        $this->line('OneSignal app id : ' . ($appId !== '' ? substr($appId, 0, 8) . '… (set)' : 'NOT SET'));
        $this->line('OneSignal api key: ' . ($key !== '' ? 'set' : 'NOT SET'));

        if ($appId === '' || $key === '') {
            $this->error('  OneSignal is not configured on this environment — nothing can be sent.');

            return self::FAILURE;
        }

        // ── Focused answer for one user ─────────────────────────────────────
        if ($email = $this->option('email')) {
            $this->newLine();
            $user = User::where('email', $email)->first();

            if (! $user) {
                $this->error("No user with email {$email}.");

                return self::FAILURE;
            }

            $this->info("Verdict for {$email} (id {$user->id}):");
            $this->line('  roles              : ' . $user->roles->pluck('name')->implode(','));
            $this->line('  status             : ' . var_export($user->status, true)
                . ($user->status === User::ACTIVE ? '' : '   <- inactive users are never notified'));

            $inList = in_array((int) $user->id, $recipientIds, true);
            $this->line('  in recipient list  : ' . ($inList ? 'YES' : 'NO'));

            $deviceCount = OnesignalSubscription::where('user_id', $user->id)->where('is_active', true)->count();
            $this->line('  active devices     : ' . $deviceCount);

            if (! $inList) {
                $this->error('  Not resolved as a recipient — this is a scoping problem, not a device problem.');
                $this->line('  Provider roles are reached through the venue\'s business; check that');
                $this->line('  venues.owner_user_id or venues.business_id points at this user\'s business,');
                $this->line('  and that their role is one of: ' . implode(', ', Capabilities::providerRoles()) . '.');
            } elseif ($deviceCount === 0) {
                $this->error('  Resolved, but no active device — the push has nowhere to go.');
                $this->line('  Ask them to log in on the app again so it calls subscribe-onesignal.');

                $stale = OnesignalSubscription::where('user_id', $user->id)->count();
                if ($stale > 0) {
                    $this->line("  ({$stale} subscription row(s) exist but are inactive.)");
                }
            } else {
                $this->info('  Resolved AND has a device — a push for this venue should reach them.');
            }
        }

        // ── Shared test handsets ───────────────────────────────────────────
        // onesignal_id is unique per install, so logging a second account into
        // the same handset does not duplicate the row — it MOVES it
        // (UserService::subscribeOneSignal reassigns user_id). The previous
        // account silently keeps zero devices, which looks exactly like this
        // bug. Surface recent hand-offs so a swapped test phone is obvious.
        $this->newLine();
        $recentlyMoved = OnesignalSubscription::whereIn('user_id', $recipientIds)
            ->where('is_active', true)
            ->where('updated_at', '>', now()->subDays(3))
            ->whereColumn('updated_at', '>', 'created_at')
            ->get(['user_id', 'device_type', 'updated_at']);

        if ($recentlyMoved->isNotEmpty()) {
            $this->warn('Devices re-registered recently (an account swap on a shared handset moves the device, leaving the previous account with none):');
            foreach ($recentlyMoved as $sub) {
                $this->line("  user {$sub->user_id}  {$sub->device_type}  at {$sub->updated_at}");
            }
        }

        return self::SUCCESS;
    }
}
