<?php
namespace App\Models;

use App\Traits\GeneratesUuid;
use App\Traits\LogsModelActivity;
use App\Traits\TracksCreator;

use BookingStatus;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\Model;
use PaymentMethod;
use PaymentStatus;
use PaymentType;

class Booking extends Model
{
    use GeneratesUuid, LogsModelActivity, TracksCreator;

    protected $fillable = [
        'uuid',
        'date',
        'notes',
        'status',
        'user_id',
        'is_free',
        'end_time',
        'duration',
        'subtotal',
        'venue_id',
        'created_by',
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
        'gift_card_amount',
        'payment_breakdown',
        'full_payment_received',
        'loyalty_points_earned',
        'origin',
        'players',
        'program_id',
    ];

    protected $casts = [
        'payment_breakdown'     => 'array',
        'full_payment_received' => 'boolean',
        'subtotal'              => 'decimal:2',
        'discount_amount'       => 'decimal:2',
        'total_price'           => 'decimal:2',
    ];

    protected $appends = ['due_amount', 'status_label'];

    public function getStatusLabelAttribute()
    {
        if (
            $this->status == BookingStatus::PENDING
            && $this->payment_status === \BookingPaymentStatus::DEPOSIT_PAID
            && (
                $this->relationLoaded('payments')
                    ? $this->payments->count() > 1
                    : $this->payments()->count() > 1
            )
        ) {

            $remainingPaymentExists = $this->relationLoaded('payments')
                ? $this->payments
                ->where('type', PaymentType::REMAINING)
                ->where('method', PaymentMethod::CASH)
                ->where('status', PaymentStatus::PENDING)
                ->isNotEmpty()
                : $this->payments()
                ->where('type', PaymentType::REMAINING)
                ->where('method', PaymentMethod::CASH)
                ->where('status', PaymentStatus::PENDING)
                ->exists();

            if ($remainingPaymentExists) {
                return 'Deposit paid - confirmed';
            }
        }

        return $this->status;
    }

    public function getDueAmountAttribute()
    {
        if ($this->is_free) {
            return 0;
        }

        if ($this->status === BookingStatus::CANCELLED) {
            return 0;
        }

        if (
            $this->payment_status === \BookingPaymentStatus::FULLY_PAID
            || $this->payment_status === \BookingPaymentStatus::PAYMENT_SKIPPED
            || $this->full_payment_received
        ) {
            return 0;
        }

        $totalPrice = (float) $this->total_price;

        $totalPaid = $this->relationLoaded('payments')
            ? (float) $this->payments
                ->where('status', PaymentStatus::PAID)
                ->sum('amount')
            : (float) $this->payments()
                ->where('status', PaymentStatus::PAID)
                ->sum('amount');

        $dueAmount = $totalPrice - $totalPaid;

        if ($dueAmount <= 0) {
            return 0;
        }

        if ($this->payment_status === \BookingPaymentStatus::DEPOSIT_PAID && $totalPaid <= 0) {
            $breakdown = $this->payment_breakdown;
            return (float) ($breakdown['remaining_amount'] ?? $dueAmount);
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
        return $this->belongsToMany(AddOn::class)->withPivot(['quantity', 'unit_price', 'subtotal']);
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

    // public function calculateDuration()
    // {
    //     return $this->start_time->diffInHours($this->end_time);
    // }

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
        static::creating(function ($booking) {
            if (auth()->check()) {
                $booking->created_by = $booking->created_by ?? auth()->id();
            }
        });

        static::saving(function ($booking) {
            if ($booking->start_time && $booking->end_time) {
                // Use date if available, otherwise use today's date
                $date = $booking->date ?? now()->format('Y-m-d');

                // Parse start_time and end_time - they might be datetime strings or time strings
                $startTimeParsed = Carbon::parse($booking->start_time);
                $endTimeParsed   = Carbon::parse($booking->end_time);

                // Extract just the time portion (H:i format)
                $startTimeStr = $startTimeParsed->format('H:i');
                $endTimeStr   = $endTimeParsed->format('H:i');

                // Create datetime objects using the booking date
                $start = Carbon::parse($date . ' ' . $startTimeStr);
                $end   = Carbon::parse($date . ' ' . $endTimeStr);

                // If end time is less than start time, it means end time is on the next day
                if ($end->lt($start)) {
                    $end->addDay();
                }

                $booking->duration = abs($end->diffInHours($start));
            }
        });

        static::creating(function ($booking) {

            $now        = now();
            $year       = $now->format('Y');
            $month      = $now->format('m');
            $prefix     = "BKN_{$year}_{$month}_";
            $maxRetries = 5;
            $attempts   = 0;

            do {

                $attempts++;

                $latestBooking = static::lockForUpdate()
                    ->where('booking_id', 'like', "{$prefix}%")
                    ->orderByDesc('id')
                    ->first();

                $nextNumber = $latestBooking
                    ? ((int) substr($latestBooking->booking_id, -5)) + 1
                    : 1;

                $bookingId = sprintf('%s%05d', $prefix, $nextNumber);

                $exists = static::where('booking_id', $bookingId)->exists();

                if ($attempts >= $maxRetries && $exists) {
                    throw new \Exception('Failed to generate unique booking ID after multiple attempts');
                }

            } while ($exists);

            $booking->booking_id = $bookingId;
        });
    }
    protected function activityLogName(): string
    {
        return 'booking';
    }

    protected function activityLogAttributes(): array
    {
        return [
            'date',
            'notes',
            'status',
            'user_id',
            'is_free',
            'end_time',
            'duration',
            'subtotal',
            'venue_id',
            'created_by',
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
            'gift_card_amount',
            'payment_breakdown',
            'full_payment_received',
            'loyalty_points_earned',
        ];
    }

}