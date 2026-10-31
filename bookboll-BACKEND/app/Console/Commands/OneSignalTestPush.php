<?php

namespace App\Console\Commands;

use App\Models\OnesignalSubscription;
use App\Models\User;
use App\Services\OneSignalService;
use Illuminate\Console\Command;

/**
 * Send a test push through OneSignal — to one app's whole audience, to
 * specific users, or to raw subscription ids — or just list who would get it.
 *
 *   php artisan onesignal:test --app=customer --dry-run
 *   php artisan onesignal:test --app=customer --all
 *   php artisan onesignal:test --app=customer --user=12 --user=45
 *   php artisan onesignal:test --app=admin --email=owner@venue.com
 *   php artisan onesignal:test --app=customer --id=<subscription id>
 *   php artisan onesignal:test --app=customer --all --type=booking_created --data='{"booking_id":123}'
 *
 * Goes through OneSignalService, so it exercises the exact path a real
 * notification takes: the same per-app credentials, targeting and logging.
 */
class OneSignalTestPush extends Command
{
    protected $signature = 'onesignal:test
        {--app=customer : Which OneSignal app: customer or admin}
        {--all : Every active subscription of that app}
        {--user=* : Backend user id(s); repeat the option for several}
        {--email=* : User email(s); repeat the option for several}
        {--id=* : Raw OneSignal subscription id(s), bypassing the users table}
        {--title=Bookboll test : Notification title}
        {--message=Test push from the backend : Notification body}
        {--type=test : data.type the app routes on (booking_created, community_request, …)}
        {--data= : Extra data as a JSON object merged into the payload, e.g. a booking_id}
        {--dry-run : List the targets and payload without sending}';

    protected $description = 'Send a OneSignal test push to an app\'s subscribers, specific users, or subscription ids';

    public function handle(OneSignalService $oneSignal): int
    {
        $app = strtolower((string) $this->option('app'));
        if (! in_array($app, OnesignalSubscription::APPS, true)) {
            $this->error('--app must be customer or admin.');

            return self::INVALID;
        }

        $extra = [];
        if ($this->option('data')) {
            $extra = json_decode((string) $this->option('data'), true);
            if (! is_array($extra)) {
                $this->error('--data must be a JSON object.');

                return self::INVALID;
            }
        }
        $data = array_merge(['type' => (string) $this->option('type')], $extra);
        $title = (string) $this->option('title');
        $message = (string) $this->option('message');

        // ── Who ───────────────────────────────────────────────────────────
        $rawIds = array_values(array_filter(array_map('trim', (array) $this->option('id'))));
        if ($rawIds) {
            $this->line("Target: {$app} app, " . count($rawIds) . ' raw subscription id(s)');
            $this->table(['subscription id'], array_map(fn ($id) => [$id], $rawIds));
            $this->describePayload($title, $message, $data);

            if ($this->option('dry-run')) {
                $this->info('Dry run — nothing sent.');

                return self::SUCCESS;
            }

            return $this->report($oneSignal->sendToPlayerIds($rawIds, $title, $message, $data, $app));
        }

        $query = OnesignalSubscription::query()->forApp($app)->active()->with('user:id,name,email');

        if ($this->option('all')) {
            // everyone with an active device in this app
        } else {
            $userIds = array_map('intval', (array) $this->option('user'));
            $emails = array_map('strtolower', array_filter((array) $this->option('email')));
            if ($emails) {
                $userIds = array_merge($userIds, User::whereIn('email', $emails)->pluck('id')->all());
                $unknown = array_diff($emails, User::whereIn('email', $emails)->pluck('email')->map('strtolower')->all());
                foreach ($unknown as $e) {
                    $this->warn("No user with email {$e}");
                }
            }
            $userIds = array_values(array_unique(array_filter($userIds)));
            if (! $userIds) {
                $this->error('Give --all, --user=, --email= or --id=.');

                return self::INVALID;
            }
            $query->whereIn('user_id', $userIds);

            $withoutDevice = array_diff($userIds, (clone $query)->pluck('user_id')->map(fn ($v) => (int) $v)->all());
            foreach ($withoutDevice as $uid) {
                $this->warn("User {$uid} has no active {$app}-app subscription — they will not be notified.");
            }
        }

        $rows = $query->orderBy('user_id')->get();
        if ($rows->isEmpty()) {
            $this->warn("No active {$app}-app subscriptions match.");

            return self::SUCCESS;
        }

        $this->line("Target: {$app} app, {$rows->count()} device(s) across {$rows->pluck('user_id')->unique()->count()} user(s)");
        $this->table(
            ['user id', 'name', 'email', 'device', 'subscription id'],
            $rows->map(fn ($s) => [
                $s->user_id,
                $s->user?->name ?? '—',
                $s->user?->email ?? '—',
                trim(($s->device_type ?? '') . ' ' . ($s->device_info ?? '')) ?: '—',
                $s->onesignal_id,
            ])->all()
        );
        $this->describePayload($title, $message, $data);

        if ($this->option('dry-run')) {
            $this->info('Dry run — nothing sent.');

            return self::SUCCESS;
        }

        if ($rows->count() > 5 && ! $this->confirm("Send to {$rows->count()} devices?", false)) {
            $this->info('Cancelled.');

            return self::SUCCESS;
        }

        return $this->report($oneSignal->sendToUsers($rows->pluck('user_id')->unique()->values()->all(), $title, $message, $data));
    }

    protected function describePayload(string $title, string $message, array $data): void
    {
        $this->line('Title:   ' . $title);
        $this->line('Message: ' . $message);
        $this->line('Data:    ' . json_encode($data, JSON_UNESCAPED_SLASHES));
    }

    protected function report(bool $delivered): int
    {
        if ($delivered) {
            $this->info('Sent. OneSignal accepted the notification — check storage/logs/laravel.log for the notification id.');

            return self::SUCCESS;
        }

        $this->error('Not delivered. See storage/logs/laravel.log: missing credentials for this app, or OneSignal reached nobody (unsubscribed / wrong app).');

        return self::FAILURE;
    }
}
