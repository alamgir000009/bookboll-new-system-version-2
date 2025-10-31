<?php
namespace App\Models;

use Carbon\Carbon;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use PaymentStatus;

class Booking extends Model
{
    protected $fillable = [
        'date',
        'notes',
        'status',
        'user_id',
        'end_time',
        'duration',
        'subtotal',
        'venue_id',
        'listing_id',
        'start_time',
        'total_price',
        'refunded_at',
        'promocode_id',
        'cancelled_by',
        'cancelled_at',
        'cancel_reason',
        'payment_status',
        'payment_method',
        'refunded_amount',
        'discount_amount',
        'reference_number',
        'payment_breakdown',
        'full_payment_received',
        'loyalty_points_earned',
    ];

    protected $casts = [
        'payment_breakdown'     => 'array',
        'subtotal'              => 'decimal:2',
        'discount_amount'       => 'decimal:2',
        'total_price'           => 'decimal:2',
        'full_payment_received' => 'boolean',
    ];

    protected $appends = ['payment_status', 'due_amount'];

    public function getPaymentStatusAttribute()
    {
        $paidAmount = $this->payments()->where('status', PaymentStatus::PAID)->sum('amount');
        return ($paidAmount >= $this->total_price) ? 'completed' : 'pending';
    }

    public function getDueAmountAttribute()
    {
        $paidAmount = $this->payments()->where('status', PaymentStatus::PAID)->sum('amount');
        return max(0, $this->total_price - $paidAmount);
    }

    public function user()
    {
        return $this->belongsTo(User::class);
    }

    public function venue()
    {
        return $this->belongsTo(Venue::class);
    }

    public function listing()
    {
        return $this->belongsTo(Listing::class);
    }

    public function promocode()
    {
        return $this->belongsTo(Promocode::class);
    }

    public function addOns()
    {
        return $this->belongsToMany(AddOn::class);
    }

    public function payments()
    {
        return $this->hasMany(Payment::class);
    }

    public function history()
    {
        return $this->hasMany(BookingHistory::class, 'booking_id');
    }

    public function review()
    {
        return $this->hasOne(Review::class);
    }

    public function resourcesAllocated()
    {
        return $this->belongsToMany(Resource::class, 'booking_resource')->withPivot('quantity');
    }

    public function scopeActive($query)
    {
        return $query->whereIn('status', ['pending', 'confirmed']);
    }

    public function scopeForDate($query, $date)
    {
        return $query->whereDate('start_time', $date);
    }

    public function calculateDuration()
    {
        return $this->start_time->diffInHours($this->end_time);
    }

    public function isFullyPaid()
    {
        return $this->total_paid >= $this->total_price;
    }

    public function scopeWithTotals($query)
    {
        return $query->with(['payments' => function ($q) {
            $q->select('id', 'booking_id', 'amount', 'status');
        }]);
    }

    public function isAvailable()
    {
        $overlapping = self::where('listing_id', $this->listing_id)
            ->where('id', '!=', $this->id)
            ->where('status', '!=', 'cancelled')
            ->where(function ($q) {
                $q->whereBetween('start_time', [$this->start_time, $this->end_time])
                    ->orWhereBetween('end_time', [$this->start_time, $this->end_time])
                    ->orWhere(function ($q) {
                        $q->where('start_time', '<=', $this->start_time)
                            ->where('end_time', '>=', $this->end_time);
                    });
            })->exists();

        if ($overlapping) {
            return false;
        }

        $listingSchedule = $this->listing->schedules()->where('day', strtolower($this->start_time->format('l')))->first();
        if (! $listingSchedule || ! $listingSchedule->is_open) {
            return false;
        }

        $startHour = $this->start_time->format('H:i');
        $endHour   = $this->end_time->format('H:i');
        if ($startHour < $listingSchedule->start_time || $endHour > $listingSchedule->end_time) {
            return false;
        }

        return true;
    }

    protected static function booted()
    {
        static::saving(function ($booking) {
            if ($booking->start_time && $booking->end_time) {
                $start             = Carbon::parse($booking->start_time);
                $end               = Carbon::parse($booking->end_time);
                $booking->duration = abs($end->diffInHours($start));
            }
        });

        static::creating(function ($booking) {

            $year = now()->year;

            DB::transaction(function () use ($year, $booking) {

                $latestBooking = static::lockForUpdate()->whereYear('created_at', $year)->latest('id')->first();

                $nextBookingNumber = $latestBooking
                    ? (int) substr($latestBooking->reference_number, -5) + 1
                    : 1;

                $booking->reference_number = sprintf('BKN_%s_%05d', $year, $nextBookingNumber);
            });
        });
    }
}
