<?php
namespace App\Models;

use App\Models\Scopes\VenueAccessScope;
use BookingStatus;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;

class Booking extends Model
{
    protected $fillable = [
        'date',
        'notes',
        'status',
        'user_id',
        'is_free',
        'end_time',
        'duration',
        'subtotal',
        'venue_id',
        'listing_id',
        'start_time',
        'booking_id',
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
        'payment_breakdown',
        'full_payment_received',
        'loyalty_points_earned',
    ];

    protected $casts = [
        'payment_breakdown'     => 'array',
        'full_payment_received' => 'boolean',
        'subtotal'              => 'decimal:2',
        'discount_amount'       => 'decimal:2',
        'total_price'           => 'decimal:2',
    ];

    protected $appends = ['due_amount'];

    public function getDueAmountAttribute()
    {
        $dueAmount = 0;
        if ($this->is_free) {
            return $dueAmount;
        } elseif ($this->payment_status === \BookingPaymentStatus::DEPOSIT_PAID) {
            $breakdown = $this->payment_breakdown;
            $dueAmount = $breakdown['remaining_amount'] ?? 0;
        } else {
            $dueAmount = $this->total_price;
        }
        return $dueAmount;
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

    public function invoices()
    {
        return $this->hasMany(Invoice::class);
    }

    public function latestInvoice()
    {
        return $this->hasOne(Invoice::class)->latestOfMany();
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
        return $query->whereIn('status', [BookingStatus::PENDING, BookingStatus::CONFIRMED]);
    }

    public function scopeCompleted($query)
    {
        return $query->where('status', BookingStatus::COMPLETED);
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
            ->where('status', '!=', BookingStatus::CANCELLED)
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
        // Apply global venue access scope
        static::addGlobalScope(new VenueAccessScope);

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
                    ? (int) substr($latestBooking->booking_id, -5) + 1
                    : 1;

                $booking->booking_id = sprintf('BKN_%s_%05d', $year, $nextBookingNumber);
            });
        });
    }
}
