<?php
namespace App\Models;

use App\Traits\LogsModelActivity;

use Illuminate\Database\Eloquent\Model;

class Transaction extends Model
{
    use LogsModelActivity;

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

    public function user()
    {
        return $this->belongsTo(User::class);
    }

    public function giftCard()
    {
        return $this->belongsTo(GiftCard::class);
    }
    protected function activityLogName(): string
    {
        return 'transaction';
    }

    protected function activityLogAttributes(): array
    {
        return [
            'type',
            'amount',
            'reason',
            'user_id',
            'booking_id',
            'gift_card_id',
        ];
    }

}