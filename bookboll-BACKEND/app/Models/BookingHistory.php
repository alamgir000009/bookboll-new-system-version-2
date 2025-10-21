<?php
namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class BookingHistory extends Model
{
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
}
