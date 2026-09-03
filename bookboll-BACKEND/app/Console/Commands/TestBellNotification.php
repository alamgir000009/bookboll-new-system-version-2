<?php

namespace App\Console\Commands;

use App\Events\AdminAppRefresh;
use App\Models\User;
use Illuminate\Console\Command;
use Pusher\Pusher;

/**
 * Fire a live refresh at the dashboard notification bell.
 *
 *   php artisan bell:test              → every connected dashboard
 *   php artisan bell:test 2            → that user id
 *   php artisan bell:test admin@x.com  → that user
 */
class TestBellNotification extends Command
{
    protected $signature = 'bell:test {user? : User id or email (default: everyone connected)}';

    protected $description = 'Send a realtime refresh to the admin notification bell';

    public function handle(): int
    {
        $ids = $this->argument('user')
            ? $this->resolveUser($this->argument('user'))
            : $this->connectedUserIds();

        if (! $ids) {
            return self::FAILURE;
        }

        foreach ($ids as $id) {
            broadcast(new AdminAppRefresh($id, ['reason' => 'bell:test']));
            $this->info("Sent refresh to private-refresh.{$id}");
        }

        $this->line('');
        $this->line('In the dashboard: DevTools → Network → filter "notifications/feed".');
        $this->line('A new request means it arrived. The list only *looks* different if the underlying data changed.');

        return self::SUCCESS;
    }

    /** @return list<int> */
    protected function resolveUser(string $needle): array
    {
        $user = is_numeric($needle)
            ? User::find((int) $needle)
            : User::where('email', $needle)->first();

        if (! $user) {
            $this->error("No user matching \"{$needle}\".");

            return [];
        }

        return [(int) $user->id];
    }

    /** Ask Pusher which dashboards are subscribed right now. @return list<int> */
    protected function connectedUserIds(): array
    {
        $c = config('broadcasting.connections.pusher');

        try {
            $pusher = new Pusher($c['key'], $c['secret'], $c['app_id'], [
                'cluster' => $c['options']['cluster'] ?? 'mt1',
                'useTLS'  => true,
            ]);
            $result = $pusher->get('/channels', ['filter_by_prefix' => 'private-refresh.']);
        } catch (\Throwable $e) {
            $this->error('Could not reach Pusher: '.$e->getMessage());

            return [];
        }

        $channels = (array) (is_object($result) ? ($result->channels ?? []) : ($result['channels'] ?? []));

        $ids = [];
        foreach (array_keys($channels) as $name) {
            $ids[] = (int) str_replace('private-refresh.', '', $name);
        }

        if (! $ids) {
            $this->warn('No dashboard is connected.');
            $this->line('Open the admin app and check that broadcasting/auth returns 200 in the Network tab.');
        }

        return $ids;
    }
}
