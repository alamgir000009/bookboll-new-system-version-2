<?php

namespace App\Models;

use App\Traits\LogsModelActivity;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class OnesignalSubscription extends Model
{
    use LogsModelActivity;

    /**
     * The two OneSignal apps devices can belong to. A subscription id is only
     * addressable through the app that issued it (see OneSignalService).
     */
    public const APP_ADMIN    = 'admin';
    public const APP_CUSTOMER = 'customer';

    public const APPS = [self::APP_ADMIN, self::APP_CUSTOMER];

    protected $fillable = [
        'app',
        'user_id',
        'is_active',
        'device_type',
        'device_info',
        'onesignal_id',
    ];

    protected $casts = [
        'is_active' => 'boolean',
    ];

    protected $attributes = [
        'app' => self::APP_ADMIN,
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function scopeActive($query)
    {
        return $query->where('is_active', true);
    }

    public function scopeForApp($query, string $app)
    {
        return $query->where('app', $app);
    }

    /**
     * Which app a request's `from` header identifies.
     *
     * The customer mobile app is the only client that registers customer-app
     * subscriptions. Everything else — the admin mobile app, the admin web
     * dashboard, and callers that send no header at all — is the admin app,
     * which is also what every row written before this column existed was.
     */
    public static function appFromSurface(?string $from): string
    {
        return $from === \MobileApps::CUSTOMER ? self::APP_CUSTOMER : self::APP_ADMIN;
    }

    protected function activityLogName(): string
    {
        return 'onesignal_subscription';
    }

    protected function activityLogAttributes(): array
    {
        return [
            'app',
            'user_id',
            'is_active',
            'device_type',
            'device_info',
            'onesignal_id',
        ];
    }
}
