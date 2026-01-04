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

        $onesignalIds = OnesignalSubscription::whereIn('user_id', $userIds)
            ->active()
            ->pluck('onesignal_id')
            ->toArray();

        if (empty($onesignalIds)) {
            Log::info('OneSignal: No active subscriptions found for users', ['user_ids' => $userIds]);
            return false;
        }

        return $this->sendToPlayerIds($onesignalIds, $title, $message, $data);
    }

    public function sendToPlayerIds(array $playerIds, string $title, string $message, array $data = []): bool
    {
        if (empty($playerIds) || ! $this->appId || ! $this->restApiKey) {
            return false;
        }

        try {

            $payload = [
                'app_id'          => $this->appId,
                'headings'        => ['en' => config('app.name', 'Bookboll')],
                'subtitle'        => ['en' => $title],
                'contents'        => ['en' => $message],

                'target_channel'  => 'push',

                'include_aliases' => [
                    'onesignal_id' => is_array($playerIds) ? $playerIds : [$playerIds],
                ],
                'data'            => $data,
            ];

            $response = Http::withHeaders([
                'Authorization' => 'Basic ' . $this->restApiKey,
                'Content-Type'  => 'application/json',
            ])->post('https://api.onesignal.com/notifications', $payload);

            if ($response->successful() && isset($response['id'])) {
                Log::info('OneSignal notification sent successfully', [
                    'player_ids_count' => count($playerIds),
                    'title'            => $title,
                ]);
                return true;
            } else {
                Log::error('[PushNotificationService] Failed to send notification', [
                    'player_ids' => $playerIds,
                    'title'      => $title,
                    'message'    => $message,
                    'status'     => $response->status(),
                    'response'   => $response->json(),
                ]);
                return false;
            }

        } catch (\Exception $e) {
            Log::error('OneSignal notification exception', [
                'message' => $e->getMessage(),
                'trace'   => $e->getTraceAsString(),
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
