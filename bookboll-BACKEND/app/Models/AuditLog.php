<?php
namespace App\Models;

use Carbon\Carbon;
use Illuminate\Database\Eloquent\Model;

class AuditLog extends Model
{
    protected $fillable = [
        'action',
        'device',
        'details',
        'actor_id',
        'actor_email',
        'resource',
        'timestamp',
        'ip_address',
        'resource_id',
    ];

    protected $casts = [
        'timestamp' => 'datetime',
    ];

    public function actor()
    {
        return $this->belongsTo(User::class, 'actor_id');
    }

    public function scopeRecent($query, $days = 30)
    {
        return $query->where('created_at', '>=', Carbon::now()->subDays($days));
    }

    public function scopeByAction($query, $action)
    {
        return $query->where('action', 'like', "%{$action}%");
    }

    public function scopeByResource($query, $resource)
    {
        return $query->where('resource', $resource);
    }

    public function scopeByActor($query, $actorId)
    {
        return $query->where('actor_id', $actorId);
    }

    public function getFormattedTimestampAttribute()
    {
        return $this->timestamp->format('m/d/Y h:i A');
    }
}
