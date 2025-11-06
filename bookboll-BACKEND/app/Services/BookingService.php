<?php
namespace App\Services;

use App\Models\AddOn;
use App\Models\Booking;
use App\Models\Listing;
use App\Models\LoyaltyPoint;
use App\Models\Payment;
use App\Models\Promocode;
use App\Models\Resource;
use App\Models\SlotLock;
use App\Models\SpecialDay;
use App\Models\User;
use App\Models\Venue;
use App\Notifications\BookingCancelledUserNotification;
use App\Notifications\BookingCreatedAdminNotification;
use App\Notifications\BookingCreatedUserNotification;
use BookingPaymentStatus;
use BookingStatus;
use Carbon\Carbon;
use Exception;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use PaymentMethod;
use PaymentStatus;
use PaymentType;

class BookingService
{
    public function __construct(protected Booking $bookingModel, protected Payment $paymentModel)
    {}

    public function all($request)
    {
        $records_per_page = $request->records_per_page ?? 10;
        $search           = $request->search ?? null;

        $bookings = $this->bookingModel::with(['user', 'venue', 'listing', 'promocode', 'addOns', 'user'])
            ->when($search, function ($q) use ($search) {
                $q->where(function ($q) use ($search) {
                    $q->whereHas('listing', fn($l) => $l->where('name', 'LIKE', "%{$search}%"))
                        ->orWhere('duration', 'LIKE', "%{$search}%")
                        ->orWhere('total_price', 'LIKE', "%{$search}%")
                        ->orWhere('payment_method', 'LIKE', "%{$search}%")
                        ->orWhere('notes', 'LIKE', "%{$search}%")
                        ->orWhere('created_at', 'LIKE', "%{$search}%")
                        ->orWhere('subtotal', 'LIKE', "%{$search}%");
                });
            })
            ->when($request->status ?? null, fn($q, $status) => $q->where('status', $status))
            ->when($request->from_date ?? null, fn($q, $from) => $q->whereDate('created_at', '>=', $from))
            ->when($request->to_date ?? null, fn($q, $to) => $q->whereDate('created_at', '<=', $to))
            ->when($request->venue ?? null, function ($q, $venue) {
                $q->where('venue_id', 'LIKE', "%{$venue}%");
            })
            ->when($request->user ?? null, function ($q, $user) {
                $q->whereHas('user', fn($u) => $u->where('name', 'LIKE', "%{$user}%"));
            })
            ->latest('id')
            ->paginate($records_per_page);

        return sendSuccess('Bookings fetched successfully', compact('bookings'));
    }

    public function getBooking($booking_id)
    {
        $booking = $this->bookingModel::with([
            'addOns',
            'history',
            'payments',
            'promocode',
            'history.user',
            'venue:id,name',
            'listing:id,name',
            'resourcesAllocated',
            'user:id,name,email,phone',
        ])->findOrFail($booking_id);

        return sendSuccess("Booking fetched successfully", compact('booking'));
    }

    public function delete($id)
    {
        $booking = $this->bookingModel->findOrFail($id);
        $booking->delete();
        return sendSuccess('Booking delete successfully');
    }

    public function cancel($request, $id)
    {
        $booking = $this->bookingModel->findOrFail($id);

        $request->validate(['cancel_reason' => 'nullable|string|max:255']);

        if ($booking->status == BookingStatus::CANCELLED) {
            return sendError('Booking is already cancelled.');
        }

        $booking->update([
            'cancelled_at'  => now(),
            'cancelled_by'  => auth()->id(),
            'status'        => BookingStatus::CANCELLED,
            'cancel_reason' => $request->cancel_reason ?? 'Cancelled by ' . auth()->user()->name ?? 'Unknow',
        ]);

        $booking->history()->create([
            'user_id'     => auth()->id(),
            'action'      => 'booking cancelled',
            'description' => $request->reason ?? 'Cancelled by ' . auth()->user()->name ?? 'Unknow',
        ]);

        $booking->user->notify(new BookingCancelledUserNotification($booking));

        return sendSuccess('Booking cancelled successfully.', compact('booking'));
    }

    public function dashboardData()
    {
        $recentBookings = $this->bookingModel->take(3)->get();

        $total_redeemed = LoyaltyPoint::where('type', 'debit')->sum('points');
        $total_earned   = LoyaltyPoint::where('type', 'credit')->sum('points');

        $widgets = [
            [
                "name"       => "Total Revenue",
                "value"      => $this->bookingModel->sum('total_price'),
                "change"     => "",
                "changeType" => "increase",
                "icon"       => "CurrencyDollarIcon",
            ],
            [
                "name"       => "Total Bookings",
                "value"      => $this->bookingModel->count(),
                "change"     => "",
                "changeType" => "increase",
                "icon"       => "CalendarDaysIcon",
            ],
            [
                "name"       => "Active Venues",
                "value"      => Venue::active()->count(),
                "change"     => "",
                "changeType" => "increase",
                "icon"       => "BuildingOfficeIcon",
            ],
            [
                "name"       => "Total Customers",
                "value"      => User::whereRelation('roles', 'name', 'customer')->count(),
                "change"     => "",
                "changeType" => "increase",
                "icon"       => "UserGroupIcon",
            ],
        ];

        $loyalties = [
            "pointsIssued"   => $total_earned ?? 0,
            "pointsRedeemed" => $total_redeemed ?? 0,
            "redemptionRate" => $total_earned > 0 ? round(($total_redeemed / $total_earned) * 100, 2) : 0,
            "activeMembers"  => User::active()->whereRelation('roles', 'name', 'customer')->count(),
        ];

        $venues   = Venue::with(['listings:id,venue_id,name'])->get(['id', 'name']);
        $listings = Listing::with(['venue'])->get(['id', 'name', 'venue_id']);

        $stats = [
            "widgets"   => $widgets,
            "loyalties" => $loyalties,
        ];

        return sendSuccess('Bookings fetched successfully',
            compact('recentBookings', 'stats', 'venues', 'listings'));
    }

    public function chartData($request)
    {
        $filters = $request->validate([
            'timeRange'        => 'required|in:daily,weekly,monthly',
            'dateFrom'         => 'required|date',
            'dateTo'           => 'required|date|after_or_equal:dateFrom',
            'paymentMethods'   => 'array|nullable',
            'selectedVenues'   => 'array|nullable',
            'selectedListings' => 'array|nullable',
        ]);

        $payments = $this->paymentModel::whereBetween('payments.date', [$filters['dateFrom'], $filters['dateTo']])
            ->join('bookings', 'payments.booking_id', '=', 'bookings.id')
            ->when(! empty($filters['selectedVenues']), function ($q) use ($filters) {
                return $q->whereIn('bookings.venue_id', $filters['selectedVenues']);
            })
            ->when(! empty($filters['selectedListings']), function ($q) use ($filters) {
                return $q->whereIn('bookings.listing_id', $filters['selectedListings']);
            })->get([
            'payments.id',
            'payments.date',
            'payments.status',
            'payments.method',
            'payments.amount',
            'bookings.venue_id as venue_id',
        ])->toArray();

        if (! empty($filters['paymentMethods'])) {
            $payments = array_filter($payments, fn($p) => in_array($p['method'], $filters['paymentMethods']));
        }

        $grouped = $this->groupPaymentsByPeriod($payments, $filters['timeRange'], [$filters['dateFrom'], $filters['dateTo']]);

        $chartData      = [];
        $maxValue       = 0;
        $collectedTotal = 0;
        $pendingTotal   = 0;
        foreach ($grouped as $period => $data) {
            $collectedTotal += $data['collected'];
            $pendingTotal += $data['pending'];
            $maxValue    = max($maxValue, $data['collected'], $data['pending']);
            $chartData[] = [$period, $data['collected'], $data['pending']];
        }

        $transactionCount   = count($payments);
        $completedPayments  = array_filter($payments, fn($p) => $p['status'] == 'paid' && in_array($p['method'], ['cash', 'cheque', 'wire_transfer', 'online']));
        $averageTransaction = ! empty($completedPayments) ? $collectedTotal / count($completedPayments) : 0;

        return sendSuccess('Chart data fetched', [
            'chartData'    => $chartData,
            'maxValue'     => $maxValue ?: 1000,
            'paymentStats' => [
                'total_collected_payments' => $collectedTotal,
                'total_pending_payments'   => $pendingTotal,
                'total_transactions_count' => $transactionCount,
                'average_transaction'      => round($averageTransaction, 2),
            ],
        ]);
    }
    private function groupPaymentsByPeriod(array $payments, string $timeRange, array $dateRange): array
    {
        $grouped = [];
        $start   = Carbon::parse($dateRange[0]);
        $end     = Carbon::parse($dateRange[1]);

        $collectedMethods = ['cash', 'cheque', 'wire_transfer', 'online'];

        if ($timeRange == 'daily') {
            for ($d = $start->copy(); $d->lte($end); $d->addDay()) {
                $grouped[$d->format('Y-m-d')] = ['collected' => 0, 'pending' => 0];
            }
        } elseif ($timeRange == 'weekly') {
            $start->startOfWeek();
            while ($start->lte($end)) {
                $grouped[$start->format('Y-m-d')] = ['collected' => 0, 'pending' => 0];
                $start->addWeek();
            }
        } elseif ($timeRange == 'monthly') {
            $start->startOfMonth();
            while ($start->lte($end)) {
                $grouped[$start->format('Y-m')] = ['collected' => 0, 'pending' => 0];
                $start->addMonth();
            }
        }

        foreach ($payments as $payment) {
            $date = Carbon::parse($payment['date']);
            $key  = match ($timeRange) {
                'daily'   => $date->format('Y-m-d'),
                'weekly'  => $date->startOfWeek()->format('Y-m-d'),
                'monthly' => $date->format('Y-m'),
                default   => $date->format('Y-m-d'),
            };

            if (isset($grouped[$key])) {
                $amount = max(0, (float) $payment['amount']);
                if ($payment['status'] == PaymentStatus::PAID && in_array($payment['method'], $collectedMethods)) {
                    $grouped[$key]['collected'] += $amount;
                } else {
                    $grouped[$key]['pending'] += $amount;
                }
            }
        }

        ksort($grouped);
        return $grouped;
    }

    public function formData($request)
    {
        $venues      = Venue::with(['schedules', 'listings' => fn($q) => $q->active()])->active()->get();
        $listings    = Listing::with(['schedules', 'addOns' => fn($q) => $q->active()])->active()->get();
        $customers   = User::whereHas('roles', fn($q) => $q->where('name', 'customer'))->active()->get(['id', 'name', 'email']); // Adjust for your User model
        $resources   = Resource::active()->get();
        $addOns      = AddOn::active()->get();
        $specialDays = SpecialDay::with(['timeBlocks'])->get();

        return sendSuccess('Form data fetched', compact('venues', 'listings', 'customers', 'resources', 'addOns', 'specialDays'));
    }

    public function customerDueBookings($request)
    {
        $validated = $request->validate([
            'customer_id'        => 'required|exists:users,id',
            'venue_id'           => 'required|exists:venues,id',
            'editing_booking_id' => 'nullable|integer',
        ]);

        $dueBookings = $this->bookingModel::with(['listing', 'payments'])
            ->where('user_id', $validated['customer_id'])
            ->where('venue_id', $validated['venue_id'])
            ->where('end_time', '<', now())
            ->where('status', '!=', BookingStatus::CANCELLED)
            ->whereDoesntHave('payments', fn($q) => $q->where('status', PaymentStatus::PAID))
            ->where('id', '!=', $validated['editing_booking_id'] ?? 0)
            ->get()
            ->map(fn($b) => [
                'id'          => $b->id,
                'date'        => $b->date,
                'start_time'  => $b->start_time,
                'end_time'    => $b->end_time,
                'listingName' => $b->listing->name,
                'dueAmount'   => $b->due_amount ?? 0,
            ]);

        return sendSuccess('Due bookings fetched', ['dueBookings' => $dueBookings]);
    }

    public function store($request)
    {
        $data = $request->validate([
            'venue_id'                => 'required|exists:venues,id',
            'listing_id'              => 'required|exists:listings,id',
            'user_id'                 => 'required|exists:users,id',
            'date'                    => 'required|date',
            'start_time'              => 'required|date_format:H:i',
            'end_time'                => 'required|date_format:H:i',
            'payment_method'          => 'required|in:cash_on_arrival,online,deposit',
            'selected_add_ons'        => 'nullable|array',
            'selected_add_ons.*'      => 'exists:add_ons,id',
            'promocode'               => 'nullable|string|max:50',
            'notes'                   => 'nullable|string|max:1000',
            'selected_due_bookings'   => 'nullable|array',
            'selected_due_bookings.*' => 'exists:bookings,id',
            'total_price'             => 'nullable|numeric',
        ]);

        if (! empty($data['selected_due_bookings'])) {
            $dueBookings = $this->bookingModel::whereIn('id', $data['selected_due_bookings'])
                ->where('user_id', $data['user_id'])
                ->where('status', BookingStatus::CONFIRMED)
                ->whereRaw('total_price > COALESCE((SELECT SUM(amount) FROM payments WHERE booking_id = bookings.id), 0)')
                ->pluck('id');
            if ($dueBookings->count() != count($data['selected_due_bookings'])) {
                return response()->json(['error' => 'Some selected due bookings are invalid or already paid.'], 422);
            }
        }

        $errors = $this->validateBooking($data);
        if (! empty($errors)) {
            return sendError('Validation failed', $errors, 422);
        }

        $listing  = Listing::findOrFail($data['listing_id']);
        $addOns   = collect($data['selected_add_ons'] ?? [])->map(fn($id) => AddOn::findOrFail($id));
        $duration = $listing->slot_duration_hours ?? 1;
        $duration += $addOns->sum('hours_extension') ?? 0;

        $startDateTime = Carbon::parse("{$data['date']} {$data['start_time']}");
        $endDateTime   = $startDateTime->copy()->addHours($duration);
        // Override with provided end_time if different, but validate consistency
        $providedEnd = Carbon::parse("{$data['date']} {$data['end_time']}");
        if ($endDateTime->format('H:i') != $providedEnd->format('H:i')) {
            return response()->json(['error' => 'Provided end_time does not match calculated duration.'], 422);
        }

        $subtotal    = $listing->base_price_per_hour * $duration;
        $addOnsPrice = $addOns->sum('price') ?? 0;
        $subtotal += $addOnsPrice;

        $discount = 0;
        $promo    = null;
        if (! empty($data['promocode'])) {
            $promo = Promocode::where('code', strtoupper($data['promocode']))
                ->where('status', 'active')
                ->where('expires_at', '>', now())
                ->first();
            if ($promo) {
                $discount = $this->calculateDiscount($promo, $subtotal);
            } else {
                return response()->json(['error' => 'Invalid promocode.'], 422);
            }
        }

        $totalPrice = max(0, $subtotal - $discount);

        DB::beginTransaction();
        try {

            $booking = $this->paymentModel::create([
                'user_id'               => $data['user_id'],
                'venue_id'              => $data['venue_id'],
                'listing_id'            => $data['listing_id'],
                'promocode_id'          => $promo?->id,
                'start_time'            => $startDateTime->format('Y-m-d H:i:s'),
                'end_time'              => $endDateTime->format('Y-m-d H:i:s'),
                'subtotal'              => $subtotal,
                'discount_amount'       => $discount,
                'total_price'           => $totalPrice,
                'payment_method'        => $data['payment_method'],
                'status'                => BookingStatus::CONFIRMED,
                'notes'                 => $data['notes'],
                'full_payment_received' => in_array($data['payment_method'], ['online', 'deposit']),
            ]);

            if (! empty($data['selected_add_ons'])) {
                $booking->addOns()->attach($data['selected_add_ons'], ['quantity' => 1]);
            }

            $this->createPaymentsForBooking($booking, $data['payment_method'], $totalPrice);

            // Handle selected due bookings
            foreach ($data['selected_due_bookings'] ?? [] as $dueId) {
                $dueBooking = $this->bookingModel::findOrFail($dueId);
                $dueAmount  = $dueBooking->total_price - $dueBooking->payments()->sum('amount');

                if ($dueAmount > 0) {
                    $paymentId = 'PAY_' . Str::uuid();
                    $this->paymentModel::create([
                        'payment_id' => $paymentId,
                        'user_id'    => $data['user_id'],
                        'booking_id' => $dueId,
                        'type'       => 'remaining',
                        'amount'     => $dueAmount,
                        'method'     => 'cash',
                        'status'     => 'paid',
                        'date'       => now(),
                        'notes'      => 'Collected with new booking #' . $booking->id,
                    ]);
                }
            }

            // Apply promocode usage
            if ($promo) {
                $promo->usages()->create([
                    'user_id'          => $data['user_id'],
                    'booking_id'       => $booking->id,
                    'discount_applied' => $discount,
                ]);
            }

            // Award loyalty points
            if (($listing->loyalty_points_per_booking ?? 0) > 0) {
                LoyaltyPoint::create([
                    'user_id'    => $data['user_id'],
                    'booking_id' => $booking->id,
                    'points'     => $listing->loyalty_points_per_booking,
                    'type'       => 'credit',
                ]);
            }

            DB::commit();

            return sendSuccess('Booking created successfully', [
                'booking' => $booking->load(['user', 'venue', 'listing', 'payments', 'addOns']),
            ]);

        } catch (Exception $e) {
            DB::rollBack();
            Log::error('Booking creation failed: ' . $e->getMessage());
            return sendError('Failed to create booking', ['error' => $e->getMessage()], 500);
        }
    }

    private function validateBooking(array $data, $editingId = null)
    {
        $errors = [];

        if (empty($data['venue_id'])) {
            $errors['venue_id'] = 'Venue required';
        }

        $venue     = Venue::find($data['venue_id']);
        $listing   = Listing::find($data['listing_id']);
        $dayOfWeek = strtolower(Carbon::parse($data['date'])->format('l'));

        // Parse data times for consistent comparison
        $dataStartCarbon = Carbon::createFromFormat('H:i', $data['start_time']);
        $dataEndCarbon   = Carbon::createFromFormat('H:i', $data['end_time']);

        // Venue schedule validation
        $venueSchedule = $venue->schedules()->where('day', $dayOfWeek)->first();
        if (! $venueSchedule) {
            $errors['date'] = "No venue schedule for {$dayOfWeek}s";
        } elseif (! $venueSchedule->is_open) {
            $errors['date'] = "Venue closed on {$dayOfWeek}s";
        } else {
            $venueFromCarbon = Carbon::createFromFormat('H:i', $venueSchedule->from_time ?? '00:00');
            $venueToCarbon   = Carbon::createFromFormat('H:i', $venueSchedule->to_time ?? '23:59');
            if ($dataStartCarbon->lt($venueFromCarbon) || $dataEndCarbon->gt($venueToCarbon)) {
                $errors['start_time'] = "Outside venue hours: {$venueSchedule->from_time} - {$venueSchedule->to_time}";
            }
        }

        // Listing schedule validation
        $listingSchedule = $listing->schedules()->where('day', $dayOfWeek)->first();
        if (! $listingSchedule) {
            $errors['date'] = "No listing schedule for {$dayOfWeek}s";
        } elseif (! $listingSchedule->is_open) {
            $errors['date'] = "Listing closed on {$dayOfWeek}s";
        } else {
            $listingFromCarbon = Carbon::createFromFormat('H:i', $listingSchedule->from_time ?? '00:00');
            $listingToCarbon   = Carbon::createFromFormat('H:i', $listingSchedule->to_time ?? '23:59');
            if ($dataStartCarbon->lt($listingFromCarbon) || $dataEndCarbon->gt($listingToCarbon)) {
                $errors['start_time'] = "Outside listing hours: {$listingSchedule->from_time} - {$listingSchedule->to_time}";
            }
        }

        // Special day validation
        $specialDay = SpecialDay::where('date', $data['date'])
            ->where('venue_id', $data['venue_id'])
            ->whereNull('listing_id')
            ->first();
        if ($specialDay && $specialDay->type == 'holiday') {
            $errors['date'] = 'Holiday - closed';
        } elseif ($specialDay && $specialDay->time_blocks) {
            $validBlock = collect($specialDay->time_blocks)->first(function ($b) use ($dataStartCarbon, $dataEndCarbon) {
                $blockStart = Carbon::createFromFormat('H:i', $b->start_time);
                $blockEnd   = Carbon::createFromFormat('H:i', $b->end_time);
                return $dataStartCarbon->gte($blockStart) && $dataEndCarbon->lte($blockEnd);
            });
            if (! $validBlock) {
                $errors['start_time'] = 'Outside special day blocks';
            }
        }

        // Resource conflicts (using raw DB query to avoid withSum pivot issues)
        $resourceConflicts = [];
        foreach ($listing->resourceRequirements ?? [] as $resource) {
            // Direct sum from pivot table 'booking_resources' for this resource on date
            $currentUsage = DB::table('booking_resource')
                ->join('bookings', 'booking_resource.booking_id', '=', 'bookings.id')
                ->whereDate('bookings.start_time', $data['date'])
                ->where('bookings.status', '!=', 'cancelled')
                ->where('bookings.id', '!=', $editingId)
                ->where('booking_resource.resource_id', $resource->id)
                ->sum('booking_resource.quantity');

            $totalQuantity = $resource->total_quantity ?? 0;
            $available     = $totalQuantity - $currentUsage;
            $needed        = $req->pivot?->quantity ?? 1;

            if ($needed > $available) {
                $resourceName        = $resource->name ?? 'Unknown';
                $resourceConflicts[] = "Resource {$resourceName}: Need {$needed}, available {$available}";
            }
        }
        if (! empty($resourceConflicts)) {
            $errors['resources'] = implode(', ', $resourceConflicts);
        }

        // Overlaps (time conflicts on the listing for the date)
        $fullStart = Carbon::parse("{$data['date']} {$data['start_time']}");
        $fullEnd   = Carbon::parse("{$data['date']} {$data['end_time']}");
        $overlaps  = $this->bookingModel::whereDate('start_time', $data['date'])
            ->where('listing_id', $data['listing_id'])
            ->where('status', '!=', 'cancelled')
            ->where('id', '!=', $editingId)
            ->where(function ($q) use ($fullStart, $fullEnd) {
                $q->where('start_time', '<', $fullEnd)
                    ->where('end_time', '>', $fullStart);
            })
            ->count();

        $maxBookings = $listing->max_bookings_per_slot ?? 1;
        if ($overlaps >= $maxBookings) {
            $errors['capacity'] = "Max {$maxBookings} bookings per slot reached ({$overlaps} existing)";
        }

        return $errors;
    }

    private function createPaymentsForBooking($booking, string $method, float $totalPrice)
    {
        $paymentId = 'PAY_' . Str::uuid();
        if ($method == 'cash_on_arrival') {
            // Create pending full payment
            $this->paymentModel::create([
                'payment_id' => $paymentId,
                'user_id'    => $booking->user_id,
                'booking_id' => $booking->id,
                'type'       => 'full',
                'amount'     => $totalPrice,
                'method'     => 'cash',
                'status'     => 'pending',
                'date'       => null,
                'notes'      => 'Pending cash on arrival',
            ]);
        } else {
            // For online/deposit, create paid full
            $payMethod = $method == 'online' ? 'online' : ($method == 'deposit' ? 'wire_transfer' : 'cash');
            $this->paymentModel::create([
                'payment_id' => $paymentId,
                'user_id'    => $booking->user_id,
                'booking_id' => $booking->id,
                'type'       => 'full',
                'amount'     => $totalPrice,
                'method'     => $payMethod,
                'status'     => 'paid',
                'date'       => now(),
                'notes'      => "Paid via {$payMethod}",
            ]);
        }
    }

    private function calculateDiscount(Promocode $promo, float $subtotal): float
    {
        if ($promo->type == 'percentage') {
            return round($subtotal * ($promo->discount / 100), 2);
        }
        return min($promo->discount, $subtotal); // Flat, capped
    }

    protected function calculateSubtotal(Listing $listing, Carbon $startTime, int $duration)
    {
        $pricePerHour = $listing->base_price_per_hour ?? $listing->base_price;
        $day          = strtolower($startTime->format('l'));
        $startHour    = $startTime->format('H:i');

        // Find applicable pricing group (assuming relationship)
        $pricingGroup = $listing->pricingGroups()->whereJsonContains('days', $day)
            ->where('start_time', '<=', $startHour)
            ->where('end_time', '>=', $startHour)
            ->first();

        if ($pricingGroup) {
            $pricePerHour = $pricingGroup->price_per_hour;
        }

        return $pricePerHour * $duration;
    }

    protected function validatePromocode($code, $amount, $venueId, $listingId, $request)
    {
        $promocode = Promocode::where('code', $code)->firstOrFail();
        // Assume isValidForOrder method exists on model
        if (! $promocode->isValidForOrder($amount, $request->user()->id ?? null, $request->payment_method ?? null, $venueId, $listingId)) {
            throw ValidationException::withMessages(['promocode' => 'Invalid or expired promocode']);
        }
        return $promocode;
    }

    public function bookingsForCalendar($request)
    {
        $bookings = $this->bookingModel::with(['listing:id,name', 'venue:id,name', 'user:id,name', 'user.tags'])
            ->when($request->date, fn($q) =>
                $q->whereDate('start_time', $request->date))
            ->when($request->venue_id, fn($q) =>
                $q->where('venue_id', $request->venue_id))
            ->when($request->listing_id, fn($q) =>
                $q->where('listing_id', $request->listing_id))
            ->get();

        return sendSuccess("Bookings fetched successfully", compact('bookings'));
    }

    public function customerHistory($customerId)
    {
        $bookings = $this->bookingModel
            ->with(['listing:id,name', 'venue:id,name', 'user:id,name'])
            ->where('user_id', $customerId)
            ->orderBy('date', 'desc')->get();

        return sendSuccess('Customer booking history fetched', compact('bookings'));
    }

    public function confirmBooking($request)
    {
        $user = auth()->user();

        $formData = $request->validate([
            'venue_id'          => 'required|exists:venues,id',
            'listing_id'        => 'required|exists:listings,id',
            'promocode'         => 'nullable|string',
            'date'              => 'required|date|after_or_equal:today',
            'start_time'        => 'required|date_format:H:i',
            'end_time'          => 'required|date_format:H:i|after:start_time',
            'payment_method'    => 'required|in:deposit_remaining_on_arrival,online_payment,cash_on_arrival',
            'agree_to_terms'    => 'required|accepted',
            'notes'             => 'nullable|string|max:500',
            'add_ons'           => 'nullable|array',
            'add_ons.*'         => 'exists:add_ons,id',
            'phone'             => 'required|string|min:5',
            'payment_method_id' => 'nullable|string',
            'payment_intent_id' => 'nullable|required_if:payment_method,online_payment,deposit_remaining_on_arrival',
        ]);

        try {

            DB::beginTransaction();

            if (! $user->phone || $user->phone != $request->phone) {
                $user->phone = $request->phone;
                $user->save();
            }

            $paymentIntentId = null;
            $chargeId        = null;

            if (in_array($formData['payment_method'], ['online_payment', 'deposit_remaining_on_arrival'])) {

                if (! empty($formData['payment_intent_id'])) {
                    $payment = $user->findPayment($formData['payment_intent_id']);

                    if (! $payment || $payment->status !== 'succeeded') {
                        return sendError('Payment not confirmed');
                    }

                    $paymentIntentId = $formData['payment_intent_id'];
                    $chargeId        = $payment->latest_charge;
                }

                if (! empty($formData['payment_method_id'])) {

                    $user->addPaymentMethod($formData['payment_method_id']);
                    $user->updateDefaultPaymentMethod($formData['payment_method_id']);

                    $pm = $user->findPaymentMethod($formData['payment_method_id']);
                    if ($pm && $pm->type === 'card') {
                        $card = $pm->card;

                        $user->paymentMethods()->updateOrCreate(
                            ['last_four' => $card->last4],
                            [
                                'brand'     => $card->brand,
                                'exp_year'  => $card->exp_year,
                                'exp_month' => $card->exp_month,
                            ]
                        );
                    }
                }
            }

            $listing = Listing::findOrFail($formData['listing_id']);

            $weekday = strtolower(Carbon::parse($request->date)->format('l'));

            $specialDay = SpecialDay::with('timeBlocks')
                ->where('venue_id', $listing->venue_id)
                ->where(function ($q) use ($listing) {
                    $q->whereNull('listing_id')->orWhere('listing_id', $listing->id);
                })
                ->whereDate('date', $request->date)->active()->first();

            $basePrice = determineSlotPrice($listing, $weekday, $formData['start_time'], $formData['end_time'], $specialDay);

            $startTime = Carbon::parse($formData['start_time']);
            $endTime   = Carbon::parse($formData['end_time']);
            $duration  = abs($endTime->diffInHours($startTime));

            $subtotal = $basePrice;

            $addOnCost = 0;
            if (! empty($formData['add_ons'])) {
                $addOnCost = AddOn::whereIn('id', $formData['add_ons'])->sum('price');
            }
            $subtotal += $addOnCost;

            $promocode = null;
            $discount  = 0;
            if ($formData['promocode']) {

                $promocode = Promocode::where('code', $formData['promocode'])->active()->first();

                if (! $promocode || ! $promocode->isValidForOrder(
                    $subtotal,
                    $user->id,
                    $formData['payment_method'],
                    $formData['venue_id'],
                    $formData['listing_id']
                )) {
                    return sendError('Invalid or inapplicable promocode', [], 422);
                }

                $discount = $promocode->applyDiscount($subtotal);
                $promocode->incrementUsage($user->id);
            }

            $totalPrice = $subtotal - $discount;

            $paymentStatus = match ($formData['payment_method']) {
                'online_payment'               => 'fully_paid',
                'deposit_remaining_on_arrival' => 'deposit_paid',
                'cash_on_arrival'              => 'pending',
                default                        => 'pending',
            };

            $depositAmount = 0;

            if (! empty($listing->payment_options) && isset($listing->payment_options['deposit'])) {
                $deposit = $listing->payment_options['deposit'];

                if (! empty($deposit['amount']) && $deposit['amount'] > 0) {
                    $depositAmount = $deposit['amount'];
                } elseif (! empty($deposit['percentage']) && $deposit['percentage'] > 0) {
                    $depositAmount = floor($totalPrice * ($deposit['percentage'] / 100));
                }
            }

            $booking = $this->bookingModel::create([
                'user_id'               => $user->id,
                'duration'              => $duration,
                'subtotal'              => $subtotal,
                'discount_amount'       => $discount,
                'total_price'           => $totalPrice,
                'payment_status'        => $paymentStatus,
                'promocode_id'          => $promocode?->id,
                'date'                  => $formData['date'],
                'notes'                 => $formData['notes'],
                'venue_id'              => $formData['venue_id'],
                'end_time'              => $formData['end_time'],
                'status'                => BookingStatus::PENDING,
                'start_time'            => $formData['start_time'],
                'listing_id'            => $formData['listing_id'],
                'payment_method'        => $formData['payment_method'],
                'loyalty_points_earned' => $listing->loyalty_points ?? 0,
                'full_payment_received' => $paymentStatus == BookingPaymentStatus::FULLY_PAID,
                'payment_breakdown'     => [
                    'deposit_amount'   => $paymentStatus == BookingPaymentStatus::DEPOSIT_PAID ? $depositAmount : 0,
                    'deposit_method'   => $paymentStatus == BookingPaymentStatus::DEPOSIT_PAID ? PaymentMethod::ONLINE : null,
                    'remaining_amount' => $paymentStatus == BookingPaymentStatus::DEPOSIT_PAID ? $totalPrice - $depositAmount : 0,
                    'remaining_method' => $paymentStatus == BookingPaymentStatus::DEPOSIT_PAID ? PaymentMethod::CASH : null,
                ],
            ]);

            $resources = $listing->resourceRequirements()->select('resources.id', 'listing_resource.quantity')->get();

            foreach ($resources as $resource) {
                $booking->resourcesAllocated()->attach($resource->id, [
                    'quantity' => $resource->pivot->quantity,
                ]);
            }

            if (! empty($formData['add_ons'])) {
                $booking->addOns()->sync($formData['add_ons']);
            }

            if ($paymentStatus == 'deposit_paid') {
                $listing        = $booking->listing;
                $paymentOptions = $listing ? $listing->payment_options : null;
                $depositAmount  = round($totalPrice * 0.3, 2);

                if ($paymentOptions && isset($paymentOptions['deposit'])) {
                    $deposit = $paymentOptions['deposit'];
                    if (isset($deposit['amount']) && $deposit['amount'] > 0) {
                        $depositAmount = $deposit['amount'];
                    } elseif (isset($deposit['percentage']) && $deposit['percentage'] > 0) {
                        $depositAmount = round($totalPrice * ($deposit['percentage'] / 100), 2);
                    }
                }

                $this->paymentModel::create([
                    'charge_id'         => $chargeId,
                    'user_id'           => $user->id,
                    'booking_id'        => $booking->id,
                    'amount'            => $depositAmount,
                    'payment_intent_id' => $paymentIntentId,
                    'status'            => PaymentStatus::PAID,
                    'type'              => PaymentType::DEPOSIT,
                    'method'            => PaymentMethod::ONLINE,
                    'date'              => now()->format('Y-m-d'),
                    'notes'             => 'Deposit payment for booking #' . $booking->id,
                    'payment_id'        => generatePaymentId($booking->id, PaymentType::DEPOSIT),
                ]);

                $this->paymentModel::create([
                    'date'       => null,
                    'user_id'    => $user->id,
                    'booking_id' => $booking->id,
                    'method'     => PaymentMethod::CASH,
                    'type'       => PaymentType::REMAINING,
                    'status'     => PaymentStatus::PENDING,
                    'amount'     => $totalPrice - $depositAmount,
                    'notes'      => 'Remaining payment pending for booking #' . $booking->id,
                    'payment_id' => generatePaymentId($booking->id, PaymentType::REMAINING),
                ]);
            } else {
                $booking->payments()->create([
                    'charge_id'         => $chargeId,
                    'user_id'           => $user->id,
                    'amount'            => $totalPrice,
                    'payment_intent_id' => $paymentIntentId,
                    'type'              => PaymentType::FULL,
                    'payment_id'        => generatePaymentId($booking->id, PaymentType::FULL),
                    'date'              => $paymentStatus == BookingPaymentStatus::FULLY_PAID ? now()->format('Y-m-d') : null,
                    'method'            => $formData['payment_method'] == 'online_payment' ? PaymentMethod::ONLINE : PaymentMethod::CASH,
                    'status'            => $paymentStatus == BookingPaymentStatus::FULLY_PAID ? PaymentStatus::PAID : PaymentStatus::PENDING,
                    'notes'             => $paymentStatus == BookingPaymentStatus::FULLY_PAID ? "Full payment for booking #{$booking->id}" : "Payment pending for booking #{$booking->id}",
                ]);
            }

            $booking->history()->create([
                'user_id'     => $user->id,
                'action'      => 'booking_created',
                'description' => 'Booking created via API.',
            ]);

            if ($paymentStatus == BookingPaymentStatus::DEPOSIT_PAID) {
                $booking->history()->create([
                    'user_id'     => $user->id,
                    'description' => 'Deposit payment processed.',
                    'action'      => BookingPaymentStatus::DEPOSIT_PAID,
                ]);
            }

            try {

                $invoiceNumber = generateInvoiceId();

                $invoicePath = generateAndStoreInvoicePdf($booking, $invoiceNumber);

                $invoice = $user->invoices()->create([
                    'user_id'        => $user->id,
                    'status'         => 'generated',
                    'path'           => $invoicePath,
                    'booking_id'     => $booking->id,
                    'invoice_number' => $invoiceNumber,
                ]);

                $user->notify(new BookingCreatedUserNotification($booking, $invoice->path));

                $admins = User::whereHas("roles", function ($query) {
                    $query->whereIn("name", ["super_admin", "admin"]);
                })->get();

                Notification::send($admins, new BookingCreatedAdminNotification($booking));

            } catch (Exception $e) {
                Log::error("Booking created notification errors: " . $e->getMessage(), ["Exception" => $e]);
            }

            $ipAddress = $request->ip();
            $sessionId = $user ? 'user_' . $user->id : 'guest_' . md5($ipAddress . $request->userAgent());

            $activeLock = SlotLock::where('listing_id', $listing->id)->whereDate('date', $booking->date)
                ->where('session_id', $sessionId)
                ->delete();

            DB::commit();

            $booking->load(['listing:id,name', 'venue:id,name', 'user:id,name', 'invoices']);

            return sendSuccess('Booking confirmed successfully', compact('booking'));

        } catch (Exception $e) {

            DB::rollBack();
            Log::error("Order placing Failed: " . $e->getMessage(), ["Exception" => $e]);
            return sendError("Something went wrong please try again later: " . $e->getMessage());
        }
    }

    public function upcoming($request)
    {
        $today = Carbon::today();

        $bookings = $this->bookingModel::with(['user', 'venue', 'listing', 'promocode', 'addOns', 'user'])
            ->where('user_id', auth()->id())->where('date', '>=', $today)
            ->orderBy('date', 'desc')->orderBy('start_time', 'asc')->get();

        return sendSuccess("My bookings fetched successfully", compact('bookings'));
    }

    public function recent($request)
    {
        $today = Carbon::today();

        $bookings = $this->bookingModel::with(['user', 'venue', 'listing', 'promocode', 'addOns', 'user'])
            ->where('user_id', auth()->id())->where('date', '<', $today)
            ->orderBy('date', 'desc')->orderBy('start_time', 'asc')->get();

        return sendSuccess("Recent bookings fetched successfully", compact('bookings'));
    }

}
