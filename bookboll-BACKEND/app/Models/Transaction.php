<?php
namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Transaction extends Model
{
    protected $fillable = [
        'type',
        'amount',
        'reason',
        'user_id',
        'booking_id',
        'gift_card_id',
    ];

    public function scopeCredit($query)
    {
        return $query->where('type', 'credit');
    }

    public function scopeDebit($query)
    {
        return $query->where('type', 'debit');
    }

    public static function balance($userId)
    {
        $credit = self::where('user_id', $userId)->credit()->sum('amount');
        $debit  = self::where('user_id', $userId)->debit()->sum('amount');
        return $credit - $debit;
    }

    public function customer()
    {
        return $this->belongsTo(User::class);
    }
}
