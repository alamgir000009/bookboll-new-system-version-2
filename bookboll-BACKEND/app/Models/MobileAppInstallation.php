<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One native-app installation, bound to the account signed in when it called.
 *
 * `installation_id` is a random per-install UUID the app generates. It is not
 * a device or advertising identifier, and it is never returned through a
 * public endpoint — only the adoption report, behind a capability.
 */
class MobileAppInstallation extends Model
{
    protected $table = 'mobile_app_installations';

    protected $fillable = [
        'app',
        'installation_id',
        'user_id',
        'platform',
        'app_version',
        'first_authenticated_use_at',
        'last_authenticated_use_at',
        'rebound_at',
    ];

    protected $casts = [
        'first_authenticated_use_at' => 'datetime',
        'last_authenticated_use_at'  => 'datetime',
        'rebound_at'                 => 'datetime',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
