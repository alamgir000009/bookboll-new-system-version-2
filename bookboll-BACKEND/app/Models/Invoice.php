<?php
namespace App\Models;

use App\Traits\LogsModelActivity;
use App\Traits\TracksCreator;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

class Invoice extends Model
{
    use LogsModelActivity, TracksCreator;

    protected $fillable = [
        'created_by',
        'path',
        'status',
        'user_id',
        'payment_id',
        'booking_id',
        'invoice_number',
    ];

    protected $appends = ['full_path'];

    public function user()
    {
        return $this->belongsTo(User::class);
    }

    public function booking()
    {
        return $this->belongsTo(Booking::class);
    }

    public function payment()
    {
        return $this->belongsTo(Payment::class);
    }

    public function getFullPathAttribute()
    {
        return $this->path ? url("storage/{$this->path}") : null;
    }
    protected function activityLogName(): string
    {
        return 'invoice';
    }

    protected function activityLogAttributes(): array
    {
        return [
            'path',
            'status',
            'user_id',
            'payment_id',
            'booking_id',
            'invoice_number',
        ];
    }

}