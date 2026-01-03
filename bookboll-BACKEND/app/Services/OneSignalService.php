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
        $this->appId      = trim(env('ONESIGNAL_APP_ID', ''));
        $this->restApiKey = trim(env('ONESIGNAL_REST_API_KEY', ''));
    }

    /**
     * Send push notification to specific users
     *
     * @param array $userIds Array of user IDs to send notification to
     * @param string $title Notification title
     * @param string $message Notification message
     * @param array $data Additional data to send with notification
     * @return bool
     */
    public function sendToUsers(array $userIds, string $title, string $message, array $data = []): bool
    {
        if (empty($userIds) || ! $this->appId || ! $this->restApiKey) {
            Log::warning('OneSignal: Missing configuration or user IDs', [
                'user_ids'    => $userIds,
                'has_app_id'  => ! empty($this->appId),
                'has_api_key' => ! empty($this->restApiKey),
            ]);
            return false;
        }

        // Get all active OneSignal IDs for the users
        $onesignalIds = OnesignalSubscription::whereIn('user_id', $userIds)
            ->where('is_active', true)
            ->pluck('onesignal_id')
            ->toArray();

        if (empty($onesignalIds)) {
            Log::info('OneSignal: No active subscriptions found for users', ['user_ids' => $userIds]);
            return false;
        }

        return $this->sendToPlayerIds($onesignalIds, $title, $message, $data);
    }

    /**
     * Send push notification to specific OneSignal player IDs
     *
     * @param array $playerIds Array of OneSignal player IDs
     * @param string $title Notification title
     * @param string $message Notification message
     * @param array $data Additional data to send with notification
     * @return bool
     */
    public function sendToPlayerIds(array $playerIds, string $title, string $message, array $data = []): bool
    {
        if (empty($playerIds) || ! $this->appId || ! $this->restApiKey) {
            return false;
        }

        try {

            $payload = [
                'app_id'          => env('ONESIGNAL_APP_ID'),
                'headings'        => ['en' => env('APP_NAME', 'Bookboll')],
                'subtitle'        => ['en' => $title],
                'contents'        => ['en' => $message],

                'target_channel'  => 'push',

                'include_aliases' => [
                    'onesignal_id' => is_array($playerIds) ? $playerIds : [$playerIds],
                ],
                'data'            => $data,
            ];

            logger("Debug OneSignal Payload: ", $payload);
            logger("ddd".env('ONESIGNAL_REST_API_KEY'));

            $response = Http::withHeaders([
                'Authorization' => 'Basic ' . env('ONESIGNAL_REST_API_KEY'),
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

    /**
     * Send push notification to all users with a specific role
     *
     * @param string|array $roles Role name(s) to send to
     * @param string $title Notification title
     * @param string $message Notification message
     * @param array $data Additional data to send with notification
     * @return bool
     */
    public function sendToRoles($roles, string $title, string $message, array $data = []): bool
    {
        $roles = is_array($roles) ? $roles : [$roles];

        $userIds = User::whereHas('roles', function ($query) use ($roles) {
            $query->whereIn('name', $roles);
        })->where('status', User::ACTIVE)
            ->pluck('id')
            ->toArray();

        return $this->sendToUsers($userIds, $title, $message, $data);
    }
}
