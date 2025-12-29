<?php
namespace App\Models;

use App\Models\Scopes\VenueAccessScope;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Payment extends Model
{
    protected static function booted()
    {
        static::addGlobalScope(new VenueAccessScope);
    }
    protected $fillable = [
        'type',
        'date',
        'notes',
        'amount',
        'method',
        'status',
        'user_id',
        'charge_id',
        'payment_id',
        'booking_id',
        'payment_intent_id',
    ];

    protected $casts = [
        'amount' => 'decimal:2',
    ];

    public function user()
    {
        return $this->belongsTo(User::class);
    }

    public function booking()
    {
        return $this->belongsTo(Booking::class);
    }

    public function scopeMethod($query, $method)
    {
        return $query->when($method, fn($q) => $q->where('method', $method));
    }

    public function scopeStatus($query, $status)
    {
        return $query->when($status, fn($q) => $q->where('status', $status));
    }

    public function scopeDateRange($query, $from, $to)
    {
        return $query->when($from, fn($q) => $q->where('date', '>=', $from))
            ->when($to, fn($q) => $q->where('date', '<=', $to));
    }
}
