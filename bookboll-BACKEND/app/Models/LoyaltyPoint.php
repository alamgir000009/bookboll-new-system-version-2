<?php
namespace App\Models;

use App\Traits\LogsModelActivity;

use Illuminate\Database\Eloquent\Model;

class LoyaltyPoint extends Model
{
    use LogsModelActivity;

    protected $fillable = [
        'type',
        'points',
        'reason',
        'user_id',
        'booking_id',
        'order_id',
    ];

    public function user()
    {
        return $this->belongsTo(User::class);
    }

    public static function balance($user_id)
    {
        $credit = self::where("user_id", $user_id)
            ->where("type", "credit")
            ->sum("points");

        $debit = self::where("user_id", $user_id)
            ->where("type", "debit")
            ->sum("points");

        return $credit - $debit;
    }

    public function scopeType($query, $type)
    {
        return $this->where("type", $type);
    }
    protected function activityLogName(): string
    {
        return 'loyalty_point';
    }

    protected function activityLogAttributes(): array
    {
        return [
            'type',
            'points',
            'reason',
            'user_id',
            'booking_id',
            'order_id',
        ];
    }

}