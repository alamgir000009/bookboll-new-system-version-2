<?php
namespace App\Services;

use App\Models\OnesignalSubscription;
use App\Models\User;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class OneSignalService
{
    protected $appId;
    protected $restApiKey;

    public function __construct()
    {
        $this->appId      = trim(config('services.onesignal.app_id'));
        $this->restApiKey = trim(config('services.onesignal.rest_api_key'));
    }

    public function sendToUsers(array $userIds, string $title, string $message, array $data = []): bool
    {
        if (empty($userIds) || ! $this->appId || ! $this->restApiKey) {
            Log::warning('OneSignal: Missing configuration or user IDs', ['user_ids' => $userIds]);
            return false;
        }

        $subscriptions = OnesignalSubscription::whereIn('user_id', $userIds)
            ->active()
            ->get(['user_id', 'onesignal_id']);

        $onesignalIds = $subscriptions->pluck('onesignal_id')->all();

        if (empty($onesignalIds)) {
            Log::info('OneSignal: No active subscriptions found for users', ['user_ids' => $userIds]);
            return false;
        }

        // A recipient with no registered device used to vanish without a trace:
        // this only warned when EVERY recipient was missing, so one admin with a
        // device was enough to make a send look completely healthy while the
        // business owner silently received nothing. Name them instead.
        $missing = array_values(array_diff(
            array_map('intval', $userIds),
            $subscriptions->pluck('user_id')->map(fn ($id) => (int) $id)->unique()->all()
        ));

        if ($missing) {
            Log::warning('OneSignal: recipients have no active subscription — they will not be notified', [
                'user_ids_without_subscription' => $missing,
                'notified_user_count'           => count($userIds) - count($missing),
                'title'                         => $title,
            ]);
        }

        return $this->sendToPlayerIds($onesignalIds, $title, $message, $data);
    }

    /**
     * Push to a set of device ids.
     *
     * `onesignal_subscriptions.onesignal_id` holds whatever the mobile SDK
     * handed us, and the current app builds hand us the push SUBSCRIPTION id
     * (`OneSignal.User.pushSubscription.id`) — not the OneSignal USER id.
     *
     * Those are different values, and `include_aliases.onesignal_id` resolves
     * USER ids only. Sending subscription ids that way matches nobody, and
     * OneSignal reports it as HTTP 200 with an empty `id` and
     * "All included players are not subscribed" — which the old success check
     * read as a successful send. A business owner whose only rows were
     * subscription ids therefore never received anything, while an older
     * account that still had a few genuine user ids among its rows kept
     * receiving and made the send look healthy.
     *
     * So target subscription ids first, which is what we actually store, and
     * fall back to aliases for legacy rows written by older app builds.
     */
    public function sendToPlayerIds(array $playerIds, string $title, string $message, array $data = []): bool
    {
        $playerIds = array_values(array_unique(array_filter(
            is_array($playerIds) ? $playerIds : [$playerIds]
        )));

        if (empty($playerIds) || ! $this->appId || ! $this->restApiKey) {
            return false;
        }

        if ($this->dispatch(['include_subscription_ids' => $playerIds], $title, $message, $data)) {
            return true;
        }

        // Nothing resolved as a subscription — the rows may predate the current
        // app build and hold OneSignal user ids instead.
        return $this->dispatch(
            ['include_aliases' => ['onesignal_id' => $playerIds]],
            $title,
            $message,
            $data,
            true
        );
    }

    /**
     * One attempt at the notifications endpoint.
     *
     * @param  array  $targeting  the recipient selector to merge into the payload
     * @param  bool   $isFallback whether this is the retry, so the log says which
     */
    protected function dispatch(array $targeting, string $title, string $message, array $data, bool $isFallback = false): bool
    {
        $strategy = $isFallback ? 'include_aliases.onesignal_id' : 'include_subscription_ids';

        try {
            $payload = array_merge([
                'app_id'         => $this->appId,
                'headings'       => ['en' => config('app.name', 'Bookboll')],
                'subtitle'       => ['en' => $title],
                'contents'       => ['en' => $message],
                'target_channel' => 'push',
                'data'           => $data,
            ], $targeting);

            $response = Http::withHeaders([
                'Authorization' => 'Basic ' . $this->restApiKey,
                'Content-Type'  => 'application/json',
            ])->post('https://api.onesignal.com/notifications', $payload);

            $body = $response->json();

            // OneSignal answers 200 even when it reached nobody: the giveaway is
            // an empty `id` plus an `errors` entry. `isset($body['id'])` is true
            // for an empty string, which is how a silent total failure used to
            // log as a success.
            $notificationId = is_array($body) ? ($body['id'] ?? null) : null;
            $delivered      = $response->successful() && is_string($notificationId) && $notificationId !== '';

            if ($delivered) {
                Log::info('OneSignal notification sent', [
                    'strategy'        => $strategy,
                    'notification_id' => $notificationId,
                    'recipients'      => $body['recipients'] ?? null,
                    'device_count'    => count($targeting['include_subscription_ids'] ?? $targeting['include_aliases']['onesignal_id'] ?? []),
                    'title'           => $title,
                ]);

                return true;
            }

            $log = $isFallback ? 'error' : 'info';

            Log::{$log}('OneSignal notification reached nobody', [
                'strategy' => $strategy,
                'status'   => $response->status(),
                'errors'   => is_array($body) ? ($body['errors'] ?? null) : null,
                'title'    => $title,
                'message'  => $message,
            ]);

            return false;
        } catch (\Exception $e) {
            Log::error('OneSignal notification exception', [
                'strategy' => $strategy,
                'message'  => $e->getMessage(),
                'trace'    => $e->getTraceAsString(),
            ]);

            return false;
        }
    }

    public function sendToRoles($roles, string $title, string $message, array $data = []): bool
    {
        $roles = is_array($roles) ? $roles : [$roles];

        $userIds = User::whereHas('roles', function ($query) use ($roles) {
            $query->whereIn('name', $roles);
        })->active()->pluck('id')->toArray();

        return $this->sendToUsers($userIds, $title, $message, $data);
    }
}
