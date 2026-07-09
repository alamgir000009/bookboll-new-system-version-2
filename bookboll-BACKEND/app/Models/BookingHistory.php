<?php
namespace App\Models;

use App\Traits\LogsModelActivity;

use Illuminate\Database\Eloquent\Model;

class BookingHistory extends Model
{
    use LogsModelActivity;

    protected $fillable = [
        'user_id',
        'action',
        'booking_id',
        'description',
    ];

    public function booking()
    {
        return $this->belongsTo(Booking::class);
    }

    public function user()
    {
        return $this->belongsTo(User::class);
    }
    protected function activityLogName(): string
    {
        return 'booking_history';
    }

    protected function activityLogAttributes(): array
    {
        return [
            'user_id',
            'action',
            'booking_id',
            'description',
        ];
    }

}