<?php
namespace App\Services;

use App\Models\AddOn;
use App\Models\Booking;
use App\Models\Listing;
use App\Models\LoyaltyPoint;
use App\Models\Payment;
use App\Models\Promocode;
use App\Models\Resource;
use App\Models\SpecialDay;
use App\Models\User;
use App\Models\Venue;
use App\Notifications\BookingCancelledUserNotification;
use App\Notifications\BookingConfirmedUserNotification;
use BookingPaymentMethod;
use BookingPaymentStatus;
use BookingStatus;
use Carbon\Carbon;
use Exception;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
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
        $user_id          = $request->user_id ?? null;

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
            ->when($request->status, fn($q, $status) => $q->where('status', $status))
            ->when($request->from_date, fn($q, $from) => $q->whereDate('created_at', '>=', $from))
            ->when($request->to_date, fn($q, $to) => $q->whereDate('created_at', '<=', $to))
            ->when($request->venue, function ($q, $venue) {
                $q->where('venue_id', 'LIKE', "%{$venue}%");
            })
            ->when($request->user, function ($q, $user) {
                $q->whereHas('user', fn($u) => $u->where('name', 'LIKE', "%{$user}%"));
            })
            ->when($user_id, function ($q, $user_id) {
                $q->whereRelation('user', 'id', $user_id);
            })
            ->latest('id');

        $bookings = $records_per_page == 'all' ? $bookings->get() : $bookings->paginate($records_per_page);

        return sendSuccess('Bookings fetched successfully', compact('bookings'));
    }

    public function getBooking($booking_id)
    {
        $booking = $this->bookingModel::with([
            'addOns',
            'history',
            'payments',
            'invoices',
            'promocode',
            'venue:id,name',
            'listing:id,name',
            'resourcesAllocated',
            'history.user:id,name',
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

    public function cancel($request)
    {
        $booking = $this->bookingModel->findOrFail($request->booking_id);

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
            'action'      => 'booking_cancelled',
            'description' => $request->reason ?? 'Cancelled by ' . auth()->user()->name ?? 'Unknow',
        ]);

        $booking->load('venue:id,name,maps_url');
        $booking->user->notify(new BookingCancelledUserNotification($booking));

        return sendSuccess('Booking cancelled successfully.', compact('booking'));
    }

    public function dashboardData()
    {
        $recentBookings = $this->bookingModel->whereNotIn('status', [BookingStatus::COMPLETED])->latest('id')->take(3)->get();

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

        $now = now();

        $dueBookings = $this->bookingModel::with(['listing', 'user'])
            ->where('user_id', $validated['customer_id'])
            ->where('venue_id', $validated['venue_id'])
            ->whereRaw("STR_TO_DATE(CONCAT(date, ' ', end_time), '%Y-%m-%d %H:%i:%s') < ?", [$now])
            ->whereIn('payment_status', [BookingPaymentStatus::PENDING, BookingPaymentStatus::DEPOSIT_PAID])
            ->when(! empty($validated['editing_booking_id']), function ($q) use ($validated) {
                $q->where('id', '!=', $validated['editing_booking_id']);
            })
            ->get()
            ->map(function ($b) {
                return [
                    'id'             => $b->id,
                    'date'           => $b->date,
                    'end_time'       => $b->end_time,
                    'booking_id'     => $b->booking_id,
                    'start_time'     => $b->start_time,
                    'due_amount'     => $b->due_amount,
                    'listing_name'   => $b->listing?->name,
                    'payment_status' => $b->payment_status,
                ];
            });

        return sendSuccess('Due bookings fetched successfully', [
            'dueBookings' => $dueBookings,
        ]);
    }

    public function store($request)
    {
        $formData = $request->validate([
            'venue_id'           => 'required|exists:venues,id',
            'listing_id'         => 'required|exists:listings,id',
            'user_id'            => 'required|exists:users,id',
            'date'               => 'required|date',
            'start_time'         => 'required|date_format:H:i',
            'end_time'           => 'required|date_format:H:i',
            'payment_method'     => 'nullable|in:free,cash_on_arrival,paid_cheque,paid_wire_transfer',
            'selected_add_ons'   => 'nullable|array',
            'selected_add_ons.*' => 'exists:add_ons,id',
            'promocode'          => 'nullable|string|max:50',
            'notes'              => 'nullable|string|max:1000',
            'subtotal'           => 'nullable|numeric',
            'total_price'        => 'nullable|numeric',
        ]);

        $listing = Listing::findOrFail($formData['listing_id']);
        $user    = User::findOrFail($formData['user_id']);

        $validationErrors = $this->validateBooking($formData);
        if (! empty($validationErrors)) {
            return sendError('Validation failed', $validationErrors, 422);
        }

        $subtotal      = $request->subtotal;
        $discount      = $request->discount_amount ?? 0;
        $totalPrice    = $request->total_price;
        $paymentMethod = $request->payment_method;

        $promocode = null;
        if (! empty($formData['promocode'])) {
            $promocode = Promocode::where('code', strtoupper($formData['promocode']))->active()->first();
            if (! $promocode) {
                return sendError('Invalid promocode.');
            }
        }

        DB::beginTransaction();

        try {

            $paymentStatus = match (trim($paymentMethod)) {
                'cash_on_arrival'    => BookingPaymentStatus::PENDING,
                'paid_cheque'        => BookingPaymentStatus::FULLY_PAID,
                'paid_wire_transfer' => BookingPaymentStatus::FULLY_PAID,
                'free'               => BookingPaymentStatus::FULLY_PAID,
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
                'subtotal'              => $subtotal,
                'discount_amount'       => $discount,
                'total_price'           => $totalPrice,
                'payment_status'        => $paymentStatus,
                'payment_method'        => $paymentMethod,
                'promocode_id'          => $promocode?->id,
                'date'                  => $formData['date'],
                'notes'                 => $formData['notes'],
                'venue_id'              => $formData['venue_id'],
                'end_time'              => $formData['end_time'],
                'status'                => BookingStatus::PENDING,
                'start_time'            => $formData['start_time'],
                'listing_id'            => $formData['listing_id'],
                'loyalty_points_earned' => $listing->loyalty_points ?? 0,
                'full_payment_received' => in_array($paymentMethod, [
                    BookingPaymentMethod::FREE,
                    BookingPaymentMethod::PAID_CHEQUE,
                    BookingPaymentMethod::PAID_WIRE_TRANSFER,
                ]),
                'is_free'               => $paymentMethod === BookingPaymentMethod::FREE,
                'payment_breakdown'     => [
                    'deposit_amount'   => $paymentStatus == BookingPaymentStatus::DEPOSIT_PAID ? $depositAmount : 0,
                    'deposit_method'   => $paymentStatus == BookingPaymentStatus::DEPOSIT_PAID ? PaymentMethod::ONLINE : null,
                    'remaining_amount' => $paymentStatus == BookingPaymentStatus::DEPOSIT_PAID ? $totalPrice - $depositAmount : 0,
                    'remaining_method' => $paymentStatus == BookingPaymentStatus::DEPOSIT_PAID ? PaymentMethod::CASH : null,
                ],
            ]);

            if (! empty($formData['selected_add_ons'])) {
                $booking->addOns()->attach($formData['selected_add_ons']);
            }

            $resources = $listing->resourceRequirements()->select('resources.id', 'listing_resource.quantity')->get();

            foreach ($resources as $resource) {
                $booking->resourcesAllocated()->attach($resource->id, ['quantity' => $resource->pivot->quantity]);
            }

            if ($promocode) {
                $promocode->incrementUsage($user->id);
            }

            $booking->history()->create([
                'user_id'     => $user->id,
                'action'      => 'booking_created',
                'description' => 'Booking created by ' . auth()->user()->name,
            ]);

            if (! $booking->is_free) {

                $this->paymentModel::create([
                    'user_id'    => $user->id,
                    'booking_id' => $booking->id,
                    'type'       => PaymentType::FULL,
                    'amount'     => $booking->total_price,
                    'payment_id' => generatePaymentId($booking->id, PaymentType::FULL),
                    'date'       => $paymentStatus == BookingPaymentStatus::FULLY_PAID ? now()->format('Y-m-d') : null,
                    'status'     => $paymentStatus == BookingPaymentStatus::FULLY_PAID ? PaymentStatus::PAID : PaymentStatus::PENDING,
                    'notes'      => $paymentStatus == BookingPaymentStatus::FULLY_PAID ? "Full payment for booking #{$booking->booking_id}" : "Payment pending for booking #{$booking->booking_id}",
                    'method' => $paymentMethod == BookingPaymentMethod::PAID_CHEQUE ? PaymentMethod::CHEQUE : ($paymentMethod == BookingPaymentMethod::PAID_WIRE_TRANSFER ? PaymentMethod::WIRE_TRANSFER : PaymentMethod::CASH),
                ]);

                if ($paymentStatus == BookingPaymentStatus::FULLY_PAID) {
                    $booking->history()->create([
                        'user_id'     => $user->id,
                        'action'      => $request->payment_method,
                        'description' => 'Payment ' . str_replace('_', ' ', $request->payment_method),
                    ]);
                }

            }

            if (! $booking->is_free) {
                createBookingInvoiceWithNotification($booking, $user);
            }

            unlockSlot($request, $listing, $user, $booking);

            if ($booking->loyalty_points_earned > 0) {
                $user->addLoyaltyPoints($booking->loyalty_points_earned, $booking->id);
            }

            DB::commit();

            return sendSuccess('Booking created successfully', [
                'booking' => $booking->load(['user', 'venue', 'listing', 'payments', 'addOns']),
            ]);

        } catch (Exception $e) {
            DB::rollBack();
            Log::error('Booking creation failed: ' . $e->getMessage(), ["Exception" => $e]);
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
            // Check resource usage for overlapping time slots only
            $currentUsage = DB::table('booking_resource')
                ->join('bookings', 'booking_resource.booking_id', '=', 'bookings.id')
                ->where('bookings.date', $data['date'])
                ->where('bookings.status', '!=', 'cancelled')
                ->where('bookings.id', '!=', $editingId)
                ->where('booking_resource.resource_id', $resource->id)
                ->where(function ($q) use ($data) {
                    // Only count bookings that overlap with the requested time slot
                    $q->whereRaw('TIME(bookings.start_time) < ?', [$data['end_time']])
                        ->whereRaw('TIME(bookings.end_time) > ?', [$data['start_time']]);
                })
                ->sum('booking_resource.quantity');

            $totalQuantity = $resource->total_quantity ?? 0;
            $available     = $totalQuantity - $currentUsage;
            $needed        = $resource->pivot?->quantity ?? 1;

            if ($needed > $available) {
                $resourceName        = $resource->name ?? 'Unknown';
                $resourceConflicts[] = "Resource {$resourceName}: Need {$needed}, available {$available}";
            }
        }
        if (! empty($resourceConflicts)) {
            $errors['resources'] = implode(', ', $resourceConflicts);
        }

        // Overlaps (time conflicts on the listing for the date)
        // Use date column for filtering, then check time overlaps
        $overlaps = $this->bookingModel::where('date', $data['date'])
            ->where('listing_id', $data['listing_id'])
            ->where('status', '!=', 'cancelled')
            ->where('id', '!=', $editingId)
            ->where(function ($q) use ($data) {
                // Check for time overlaps: bookings overlap if start_time < new_end_time AND end_time > new_start_time
                $q->where(function ($query) use ($data) {
                    // Case 1: Existing booking starts before new booking ends AND existing booking ends after new booking starts
                    $query->whereRaw('TIME(start_time) < ?', [$data['end_time']])
                        ->whereRaw('TIME(end_time) > ?', [$data['start_time']]);
                });
            })
            ->count();

        // Get booking quantity from data if provided (for validation)
        $bookingQuantity = $data['booking_quantity'] ?? 1;
        $maxBookings     = $listing->max_bookings_per_slot ?? 1;

        // Check if we can fit the requested quantity
        $availableSlots = $maxBookings - $overlaps;
        if ($bookingQuantity > $availableSlots) {
            $errors['capacity'] = "Requested {$bookingQuantity} booking(s) but only {$availableSlots} slot(s) available. Max {$maxBookings} bookings per slot ({$overlaps} already booked)";
        }

        return $errors;
    }

    public function bookingsForCalendar($request)
    {
        $bookings = $this->bookingModel::with(['listing:id,name', 'venue:id,name', 'user:id,name', 'user.tags'])
            ->when($request->date, fn($q) =>
                $q->whereDate('date', $request->date))
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

    public function validateBookingRequest($request)
    {
        $formData = $request->validate([
            'venue_id'                        => 'required|exists:venues,id',
            'listing_id'                      => 'required|exists:listings,id',
            'promocode'                       => 'nullable|string',
            'date'                            => 'required|date|after_or_equal:today',
            'start_time'                      => 'required|date_format:H:i',
            'end_time'                        => 'required|date_format:H:i',
            'phone'                           => 'required|string|min:5',
            'add_ons'                         => 'nullable|array',
            'add_ons.*'                       => 'exists:add_ons,id',
            'booking_quantity'                => 'required|numeric|min:1',
            'recurring_bookings'              => 'nullable|array',
            'recurring_bookings.*.date'       => 'required|date|after:date',
            'recurring_bookings.*.start_time' => 'required|date_format:H:i',
            'recurring_bookings.*.end_time'   => 'required|date_format:H:i',
            'recurring_bookings.*.price'      => 'required|numeric|min:0',
            'recurring_bookings.*.add_ons'    => 'nullable|array',
            'recurring_bookings.*.add_ons.*'  => 'exists:add_ons,id',
        ]);

        $errors = [];

        $baseValidationErrors = $this->validateBooking($formData);
        if (! empty($baseValidationErrors)) {
            $errors['base_booking'] = $baseValidationErrors;
        }

        $recurringBookings = $formData['recurring_bookings'] ?? [];
        if (! empty($recurringBookings)) {
            $baseDate      = Carbon::parse($formData['date']);
            $baseStartTime = $formData['start_time'];
            $baseEndTime   = $formData['end_time'];

            foreach ($recurringBookings as $index => $recurring) {
                $recurringErrors = [];

                if ($recurring['start_time'] !== $baseStartTime || $recurring['end_time'] !== $baseEndTime) {
                    $recurringErrors['times'] = 'Recurring booking times must match base booking times';
                }

                $recurringDate = Carbon::parse($recurring['date']);
                if ($recurringDate->lte($baseDate)) {
                    $recurringErrors['date'] = 'Recurring booking date must be after the base booking date';
                }

                $recurringValidationData = array_merge($formData, [
                    'date' => $recurring['date'],
                ]);

                $validationErrors = $this->validateBooking($recurringValidationData);
                if (! empty($validationErrors)) {
                    $recurringErrors = array_merge($recurringErrors, $validationErrors);
                }

                if (! empty($recurringErrors)) {
                    $errors['recurring_bookings'][$index] = $recurringErrors;
                }
            }
        }

        if (! empty($errors)) {
            Log::error("Booking validation errors", ["Errors" => $errors]);
            return sendError('Validation failed', $errors, 422);
        }

        return sendSuccess('Booking validation passed', [
            'valid'   => true,
            'message' => 'All validations passed. Booking can be processed.',
        ]);
    }

    public function createBooking($request)
    {
        $user = auth()->user();

        $formData = $request->validate([
            'venue_id'                        => 'required|exists:venues,id',
            'listing_id'                      => 'required|exists:listings,id',
            'promocode'                       => 'nullable|string',
            'date'                            => 'required|date|after_or_equal:today',
            'start_time'                      => 'required|date_format:H:i',
            'end_time'                        => 'required|date_format:H:i',
            'payment_method'                  => 'required|in:deposit_remaining_on_arrival,online_payment,cash_on_arrival',
            'agree_to_terms'                  => 'required|accepted',
            'notes'                           => 'nullable|string|max:500',
            'add_ons'                         => 'nullable|array',
            'add_ons.*'                       => 'exists:add_ons,id',
            'phone'                           => 'required|string|min:5',
            'payment_method_id'               => 'nullable|string',
            'payment_intent_id'               => 'nullable|required_if:payment_method,online_payment,deposit_remaining_on_arrival',
            'save_payment_details'            => 'nullable|boolean',
            'booking_quantity'                => 'required|numeric|min:1',
            'recurring_bookings'              => 'nullable|array',
            'recurring_bookings.*.date'       => 'required|date|after:date',
            'recurring_bookings.*.start_time' => 'required|date_format:H:i',
            'recurring_bookings.*.end_time'   => 'required|date_format:H:i',
            'recurring_bookings.*.price'      => 'required|numeric|min:0',
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

                if (! empty($formData['payment_method_id']) && $formData['save_payment_details']) {

                    $user->addPaymentMethod($formData['payment_method_id']);
                    $user->updateDefaultPaymentMethod($formData['payment_method_id']);

                    $pm = $user->findPaymentMethod($formData['payment_method_id']);
                    if ($pm && $pm->type === 'card') {
                        $card = $pm->card;

                        $user->paymentMethods()->updateOrCreate(
                            [
                                'user_id'                  => $user->id,
                                'stripe_payment_method_id' => $formData['payment_method_id'],
                            ],
                            [
                                'brand'     => $card->brand,
                                'exp_year'  => $card->exp_year,
                                'exp_month' => $card->exp_month,
                                'last_four' => $card->last4,
                            ]
                        );
                    }
                }
            }

            $createdBookings = collect();
            $listing         = Listing::findOrFail($formData['listing_id']);

            $weekday = strtolower(Carbon::parse($request->date)->format('l'));

            $specialDay = SpecialDay::with('timeBlocks')
                ->where('venue_id', $listing->venue_id)
                ->where(function ($q) use ($listing) {
                    $q->whereNull('listing_id')->orWhere('listing_id', $listing->id);
                })
                ->whereDate('date', $request->date)->active()->first();

            $basePrice = determineSlotPrice(
                $listing,
                $weekday,
                $formData['start_time'],
                $formData['end_time'],
                $specialDay
            );

            $addOnCost = ! empty($formData['add_ons'])
                ? AddOn::whereIn('id', $formData['add_ons'])->sum('price')
                : 0;

            $singleSubtotal = round($basePrice + $addOnCost, 2);

            // Calculate total bookings (base + recurring)
            $recurringBookings      = $formData['recurring_bookings'] ?? [];
            $totalRecurringBookings = count($recurringBookings) * $formData['booking_quantity'];
            $totalBookings          = $formData['booking_quantity'] + $totalRecurringBookings;

            // Calculate combined subtotal including recurring bookings
            $baseSubtotal      = round($singleSubtotal * $formData['booking_quantity'], 2);
            $recurringSubtotal = 0;
            foreach ($recurringBookings as $recurring) {
                // Add add-ons cost to recurring price
                $recurringSingleSubtotal = round($recurring['price'] + $addOnCost, 2);
                $recurringSubtotal += $recurringSingleSubtotal * $formData['booking_quantity'];
            }
            $combinedSubtotal = round($baseSubtotal + $recurringSubtotal, 2);

            // Apply promocode to total combined subtotal
            $totalDiscount = 0;
            $promocode     = null;
            if ($formData['promocode']) {
                $promocode = Promocode::where('code', $formData['promocode'])->active()->first();

                if (! $promocode || ! $promocode->isValidForOrder(
                    $combinedSubtotal,
                    $user->id,
                    $formData['payment_method'],
                    $formData['venue_id'],
                    $formData['listing_id']
                )) {
                    return sendError('Invalid or inapplicable promocode');
                }

                $totalDiscount = $promocode->applyDiscount($combinedSubtotal);
                $promocode->incrementUsage($user->id);
            }

            // Calculate discount per booking
            $discountPerBooking = $totalBookings > 0 ? round($totalDiscount / $totalBookings, 2) : 0;

            // Create base bookings
            for ($i = 0; $i < $formData['booking_quantity']; $i++) {
                $booking = $this->createSingleBooking(
                    $user,
                    $formData,
                    $formData['date'],
                    $formData['start_time'],
                    $formData['end_time'],
                    $singleSubtotal,
                    $discountPerBooking,
                    $promocode,
                    $paymentIntentId,
                    $chargeId,
                    $listing,
                    $request
                );

                if (! $booking) {
                    DB::rollBack();
                    return sendError('Failed to create booking');
                }

                $createdBookings->push($booking);
            }

            // Create recurring bookings
            foreach ($recurringBookings as $recurring) {
                // Validate recurring booking times match base booking times
                if ($recurring['start_time'] !== $formData['start_time'] || $recurring['end_time'] !== $formData['end_time']) {
                    DB::rollBack();
                    return sendError('Recurring booking times must match base booking times');
                }

                // Validate recurring booking date
                $recurringDate = Carbon::parse($recurring['date']);
                $baseDate      = Carbon::parse($formData['date']);
                if ($recurringDate->lte($baseDate)) {
                    DB::rollBack();
                    return sendError('Recurring booking dates must be after the base booking date');
                }

                // Validate recurring booking
                $recurringValidationData = array_merge($formData, [
                    'date' => $recurring['date'],
                ]);

                $validationErrors = $this->validateBooking($recurringValidationData);
                if (! empty($validationErrors)) {
                    DB::rollBack();
                    return sendError('Validation failed for recurring booking on ' . $recurring['date'], $validationErrors, 422);
                }

                // Calculate price for recurring booking (use provided price + add-ons)
                $recurringSingleSubtotal = round($recurring['price'] + $addOnCost, 2);

                // Create booking_quantity bookings for this recurring date
                for ($i = 0; $i < $formData['booking_quantity']; $i++) {
                    $booking = $this->createSingleBooking(
                        $user,
                        $formData,
                        $recurring['date'],
                        $recurring['start_time'],
                        $recurring['end_time'],
                        $recurringSingleSubtotal,
                        $discountPerBooking,
                        $promocode,
                        $paymentIntentId,
                        $chargeId,
                        $listing,
                        $request
                    );

                    if (! $booking) {
                        DB::rollBack();
                        return sendError('Failed to create recurring booking');
                    }

                    $createdBookings->push($booking);
                }
            }

            DB::commit();

            return sendSuccess('Booking confirmed successfully', ['bookings' => $createdBookings]);

        } catch (Exception $e) {

            DB::rollBack();
            Log::error("Order placing Failed: " . $e->getMessage(), ["Exception" => $e]);
            return sendError("Something went wrong please try again later: " . $e->getMessage());
        }
    }

    public function update($request, $bookingId)
    {
        $booking = $this->bookingModel::with(['user', 'venue', 'listing', 'payments', 'addOns', 'resourcesAllocated', 'promocode'])
            ->findOrFail($bookingId);

        if (in_array($booking->status, [BookingStatus::CANCELLED, BookingStatus::COMPLETED])) {
            return sendError('Cannot update ' . $booking->status . ' bookings.');
        }

        $formData = $request->validate([
            'user_id'            => 'sometimes|exists:users,id',
            'venue_id'           => 'sometimes|exists:venues,id',
            'listing_id'         => 'sometimes|exists:listings,id',
            'date'               => 'sometimes|date',
            'start_time'         => 'sometimes|date_format:H:i',
            'end_time'           => 'sometimes|date_format:H:i|after:start_time',
            'total_price'        => 'sometimes|numeric|min:0',
            'subtotal'           => 'sometimes|numeric|min:0',
            'discount_amount'    => 'sometimes|numeric|min:0',
            'notes'              => 'nullable|string|max:1000',
            'selected_add_ons'   => 'nullable|array',
            'selected_add_ons.*' => 'exists:add_ons,id',
            'remove_promocode'   => 'sometimes|boolean',
        ]);

        try {
            DB::beginTransaction();

            $oldBooking   = clone $booking;
            $oldPrice     = $booking->total_price;
            $oldUserId    = $booking->user_id;
            $oldDate      = $booking->date;
            $oldStartTime = $booking->start_time;
            $oldEndTime   = $booking->end_time;

            // Prepare update data
            $updateData = [];

            // Handle user change
            if (isset($formData['user_id']) && $formData['user_id'] != $booking->user_id) {
                $newUser               = User::findOrFail($formData['user_id']);
                $updateData['user_id'] = $newUser->id;
            }

            // Handle venue/listing change
            if (isset($formData['venue_id'])) {
                $updateData['venue_id'] = $formData['venue_id'];
            }
            if (isset($formData['listing_id'])) {
                $updateData['listing_id'] = $formData['listing_id'];
            }

            // Handle date/time changes - validate if changed
            $timeSlotChanged = false;
            if (isset($formData['date']) || isset($formData['start_time']) || isset($formData['end_time'])) {
                $timeSlotChanged = true;

                $validationData = [
                    'date'       => $formData['date'] ?? $booking->date,
                    'start_time' => $formData['start_time'] ?? $booking->start_time,
                    'end_time'   => $formData['end_time'] ?? $booking->end_time,
                    'venue_id'   => $formData['venue_id'] ?? $booking->venue_id,
                    'listing_id' => $formData['listing_id'] ?? $booking->listing_id,
                ];

                // Validate new time slot
                $validationErrors = $this->validateBooking($validationData, $bookingId);
                if (! empty($validationErrors)) {
                    DB::rollBack();
                    return sendError('Validation failed', $validationErrors, 422);
                }

                if (isset($formData['date'])) {
                    $updateData['date'] = $formData['date'];
                }
                if (isset($formData['start_time'])) {
                    $updateData['start_time'] = $formData['start_time'];
                }
                if (isset($formData['end_time'])) {
                    $updateData['end_time'] = $formData['end_time'];
                }

                // Recalculate duration
                if (isset($updateData['start_time']) || isset($updateData['end_time'])) {
                    $startTime              = Carbon::parse($updateData['start_time'] ?? $booking->start_time);
                    $endTime                = Carbon::parse($updateData['end_time'] ?? $booking->end_time);
                    $updateData['duration'] = abs($endTime->diffInHours($startTime));
                }
            }

            // Handle notes
            if (isset($formData['notes'])) {
                $updateData['notes'] = $formData['notes'];
            }

            // Handle promocode removal
            if (($formData['remove_promocode'] ?? false) && $booking->promocode) {
                $originalDiscount = $booking->discount_amount ?? 0;
                $booking->promocode->decrementUsage($booking->user_id);
                $updateData['promocode_id']    = null;
                $updateData['discount_amount'] = 0;

                // Adjust total price if promocode was removed
                if (isset($formData['total_price'])) {
                    $updateData['total_price'] = $formData['total_price'];
                } else {
                    $updateData['total_price'] = ($booking->subtotal ?? $booking->total_price) + $originalDiscount;
                }
            }

            // Handle price changes
            $priceChanged = false;
            $newPrice     = $oldPrice;

            if (isset($formData['total_price'])) {
                $newPrice                  = $formData['total_price'];
                $priceChanged              = ($newPrice != $oldPrice);
                $updateData['total_price'] = $newPrice;
            }

            if (isset($formData['subtotal'])) {
                $updateData['subtotal'] = $formData['subtotal'];
            }

            if (isset($formData['discount_amount'])) {
                $updateData['discount_amount'] = $formData['discount_amount'];
            }

            // Update booking
            if (! empty($updateData)) {
                $booking->update($updateData);
            }

            // Handle add-ons update
            if (isset($formData['selected_add_ons'])) {
                $booking->addOns()->sync($formData['selected_add_ons']);
            }

            // Handle resources update if listing changed
            if (isset($formData['listing_id'])) {
                $listing   = Listing::findOrFail($formData['listing_id']);
                $resources = $listing->resourceRequirements()->select('resources.id', 'listing_resource.quantity')->get();

                $booking->resourcesAllocated()->detach();
                foreach ($resources as $resource) {
                    $booking->resourcesAllocated()->attach($resource->id, ['quantity' => $resource->pivot->quantity]);
                }
            }

            // Handle payment reconciliation if price changed
            if ($priceChanged) {
                $totalPaid       = $booking->payments()->where('status', PaymentStatus::PAID)->sum('amount');
                $priceDifference = $newPrice - $oldPrice;

                if ($priceDifference < 0) {
                    // Price decreased - refund excess to wallet
                    $refundAmount = abs($priceDifference);

                    if ($totalPaid > 0 && $totalPaid > $newPrice) {
                        // Only refund if they actually paid more than the new price
                        $excessPaid = $totalPaid - $newPrice;

                        if ($excessPaid > 0) {
                            // Add to user's wallet (Transaction)
                            $booking->user->transactions()->create([
                                'type'   => 'credit',
                                'amount' => $excessPaid,
                                'reason' => "Refund for booking #{$booking->booking_id} price adjustment (QAR {$oldPrice} -> QAR {$newPrice})",
                                'booking_id' => $booking->id,
                            ]);

                            // If booking was fully paid and now has excess, update payment status
                            if ($booking->payment_status == BookingPaymentStatus::FULLY_PAID && $totalPaid > $newPrice) {
                                // Still fully paid, but we refunded excess
                                // Payment status remains FULLY_PAID
                            }

                            $booking->history()->create([
                                'user_id'     => auth()->id(),
                                'action'      => 'price_adjusted',
                                'description' => "Price reduced by QAR {$refundAmount}. QAR {$excessPaid} refunded to wallet.",
                            ]);
                        }
                    }
                } else {
                    // Price increased - update payment records
                    if ($booking->payment_status == BookingPaymentStatus::DEPOSIT_PAID) {
                        // Update remaining payment amount
                        $paymentBreakdown = $booking->payment_breakdown ?? [];
                        $currentRemaining = $paymentBreakdown['remaining_amount'] ?? 0;
                        $newRemaining     = $currentRemaining + $priceDifference;

                        $paymentBreakdown['remaining_amount'] = max(0, $newRemaining);
                        $booking->payment_breakdown           = $paymentBreakdown;

                        // Update or create remaining payment record
                        $remainingPayment = $booking->payments()
                            ->where('type', PaymentType::REMAINING)
                            ->where('status', PaymentStatus::PENDING)
                            ->first();

                        if ($remainingPayment) {
                            $remainingPayment->update(['amount' => $newRemaining]);
                        } else {
                            $booking->payments()->create([
                                'user_id' => $booking->user_id,
                                'amount'  => $newRemaining,
                                'type'    => PaymentType::REMAINING,
                                'method'  => PaymentMethod::CASH,
                                'status'  => PaymentStatus::PENDING,
                                'date'    => null,
                                'notes'   => "Remaining payment for booking #{$booking->booking_id} (updated)",
                                'payment_id' => generatePaymentId($booking->id, PaymentType::REMAINING),
                            ]);
                        }
                    } elseif ($booking->payment_status == BookingPaymentStatus::PENDING) {
                        // Update pending payment amount
                        $pendingPayment = $booking->payments()
                            ->where('status', PaymentStatus::PENDING)
                            ->first();

                        if ($pendingPayment) {
                            $pendingPayment->update(['amount' => $newPrice]);
                        } else {
                            $booking->payments()->create([
                                'user_id' => $booking->user_id,
                                'amount'  => $newPrice,
                                'type'    => PaymentType::FULL,
                                'method'  => PaymentMethod::CASH,
                                'status'  => PaymentStatus::PENDING,
                                'date'    => null,
                                'notes'   => "Payment for booking #{$booking->booking_id} (updated)",
                                'payment_id' => generatePaymentId($booking->id, PaymentType::FULL),
                            ]);
                        }
                    } elseif ($booking->payment_status == BookingPaymentStatus::FULLY_PAID) {
                        // If fully paid and price increased, create a new pending payment for the difference
                        if ($totalPaid < $newPrice) {
                            $additionalAmount = $newPrice - $totalPaid;

                            $booking->payments()->create([
                                'user_id' => $booking->user_id,
                                'amount'  => $additionalAmount,
                                'type'    => PaymentType::FULL,
                                'method'  => PaymentMethod::CASH,
                                'status'  => PaymentStatus::PENDING,
                                'date'    => null,
                                'notes'   => "Additional payment required for booking #{$booking->booking_id} (price increased)",
                                'payment_id' => generatePaymentId($booking->id, PaymentType::FULL),
                            ]);

                            // Update payment status to deposit_paid if there was a deposit, otherwise keep as pending
                            if ($totalPaid > 0) {
                                $booking->payment_status              = BookingPaymentStatus::DEPOSIT_PAID;
                                $paymentBreakdown                     = $booking->payment_breakdown ?? [];
                                $paymentBreakdown['remaining_amount'] = $additionalAmount;
                                $booking->payment_breakdown           = $paymentBreakdown;
                            }
                        }
                    }

                    $booking->history()->create([
                        'user_id'     => auth()->id(),
                        'action'      => 'price_adjusted',
                        'description' => "Price increased by QAR {$priceDifference}. Payment updated.",
                    ]);
                }

                $booking->save();
            }

            // Handle user change - transfer payments and history
            if (isset($updateData['user_id']) && $updateData['user_id'] != $oldUserId) {
                // Update all payments to new user
                $booking->payments()->update(['user_id' => $updateData['user_id']]);

                // Update invoices to new user
                $booking->invoices()->update(['user_id' => $updateData['user_id']]);

                $booking->history()->create([
                    'user_id'     => auth()->id(),
                    'action'      => 'user_changed',
                    'description' => "Booking transferred from user #{$oldUserId} to user #{$updateData['user_id']}",
                ]);
            }

            // Create history entry for changes
            $changes = [];
            if ($timeSlotChanged) {
                $newDate      = $updateData['date'] ?? $booking->date;
                $newStartTime = $updateData['start_time'] ?? $booking->start_time;
                $newEndTime   = $updateData['end_time'] ?? $booking->end_time;
                $changes[]    = "Time slot changed from {$oldDate} {$oldStartTime}-{$oldEndTime} to {$newDate} {$newStartTime}-{$newEndTime}";
            }
            if ($priceChanged) {
                $changes[] = "Price changed from QAR {$oldPrice} to QAR {$newPrice}";
            }
            if (isset($formData['venue_id']) && $formData['venue_id'] != $booking->venue_id) {
                $changes[] = "Venue changed";
            }
            if (isset($formData['listing_id']) && $formData['listing_id'] != $booking->listing_id) {
                $changes[] = "Listing changed";
            }

            if (! empty($changes)) {
                $booking->history()->create([
                    'user_id'     => auth()->id(),
                    'action'      => 'booking_updated',
                    'description' => 'Booking updated: ' . implode(', ', $changes),
                ]);
            }

            DB::commit();

            // Reload booking with all relationships
            $booking->load([
                'user',
                'addOns',
                'history',
                'payments',
                'invoices',
                'promocode',
                'venue:id,name,maps_url',
                'listing:id,name',
                'resourcesAllocated',
                'history.user:id,name',
            ]);

            return sendSuccess('Booking updated successfully', compact('booking'));

        } catch (Exception $e) {
            DB::rollBack();
            Log::error("Failed to update booking #{$bookingId}: " . $e->getMessage(), [
                'request'    => $request->all(),
                'booking_id' => $booking->id ?? $bookingId,
                'exception'  => $e,
            ]);

            return sendError('Failed to update booking: ' . $e->getMessage());
        }
    }

    public function confirm($request)
    {
        $booking = $this->bookingModel::with('user')->findOrFail($request->booking_id);

        try {

            DB::beginTransaction();

            if ($booking->status !== BookingStatus::PENDING) {
                return sendError('Booking cannot be confirmed (not pending)');
            }

            $booking->update(['status' => BookingStatus::CONFIRMED]);

            $booking->history()->create([
                'user_id'     => auth()->id(),
                'action'      => 'booking_confirmed',
                'description' => 'Booking confirmed by ' . auth()->user()->name,
            ]);

            try {
                $booking->load('venue:id,name,maps_url');
                $user = $booking->user;
                $user->notify(new BookingConfirmedUserNotification($booking));
            } catch (Exception $e) {
                Log::error("Booking confirmation notification failed: " . $e->getMessage(), ['booking_id' => $booking->id]);
            }

            DB::commit();

            $booking->load([
                'user',
                'addOns',
                'history',
                'payments',
                'invoices',
                'promocode',
                'venue:id,name',
                'listing:id,name',
                'resourcesAllocated',
                'history.user:id,name',
            ]);

            return sendSuccess('Booking confirmed and email sent successfully', compact('booking'));

        } catch (Exception $e) {
            DB::rollBack();
            Log::error("Failed to confirm booking #{$booking->id}: " . $e->getMessage(), [
                'request'    => $request->all(),
                'booking_id' => $booking->id,
                'exception'  => $e,
            ]);
            return sendError('Failed to confirm booking: ' . $e->getMessage());
        }
    }
    public function completed($request)
    {
        $booking = $this->bookingModel::with('user')->findOrFail($request->booking_id);

        try {

            DB::beginTransaction();

            if ($booking->status != BookingStatus::CONFIRMED) {
                return sendError('Booking cannot be completed (not confirmed)');
            }

            $booking->update(['status' => BookingStatus::COMPLETED]);

            $booking->history()->create([
                'user_id'     => auth()->id(),
                'action'      => 'booking_completed',
                'description' => 'Booking completed by ' . auth()->user()->name,
            ]);

            // try {
            //     $booking->load('venue:id,name,maps_url');
            //     $user = $booking->user;
            //     $user->notify(new BookingConfirmedUserNotification($booking));
            // } catch (Exception $e) {
            //     Log::error("Booking confirmation notification failed: " . $e->getMessage(), ['booking_id' => $booking->id]);
            // }

            DB::commit();

            $booking->load([
                'user',
                'addOns',
                'history',
                'payments',
                'invoices',
                'promocode',
                'venue:id,name',
                'listing:id,name',
                'resourcesAllocated',
                'history.user:id,name',
            ]);

            return sendSuccess('Booking marked as completed successfully', compact('booking'));

        } catch (Exception $e) {
            DB::rollBack();
            Log::error("Failed to completed booking #{$booking->id}: " . $e->getMessage(), [
                'request'    => $request->all(),
                'booking_id' => $booking->id,
                'exception'  => $e,
            ]);
            return sendError('Failed to confirm booking: ' . $e->getMessage());
        }
    }

    public function generateInvoice($request)
    {
        $booking = $this->bookingModel::with('user')->findOrFail($request->booking_id);

        $validated = $request->validate(['regenerate' => 'sometimes|boolean']);

        try {
            DB::beginTransaction();

            $user = $booking->user;

            $existingInvoice = $user->invoices()->where('booking_id', $booking->id)->latest()->first();

            if ($existingInvoice && ! ($validated['regenerate'] ?? false)) {
                if (Storage::disk('public')->exists($existingInvoice->path)) {
                    return response()->download(
                        Storage::disk('public')->path($existingInvoice->path),
                        $existingInvoice->invoice_number . '.pdf',
                        [
                            'Content-Type' => 'application/pdf',
                        ]
                    );
                } else {
                    Log::warning("Existing invoice file missing for booking #{$booking->id}, regenerating.");
                }
            }

            $invoiceNumber = generateInvoiceId();
            $invoicePath   = generateAndStoreInvoicePdf($booking, $invoiceNumber);

            $user->invoices()->create([
                'path'           => $invoicePath,
                'booking_id'     => $booking->id,
                'status'         => 're_generated',
                'invoice_number' => $invoiceNumber,
            ]);

            DB::commit();

            if (! Storage::disk('public')->exists($invoicePath)) {
                return sendError('Invoice file not found after generation');
            }

            return response()->download(
                Storage::disk('public')->path($invoicePath),
                $invoiceNumber . '_' . time() . '.pdf',
                [
                    'Content-Type'        => 'application/pdf',
                    'Content-Disposition' => 'attachment; filename="' . $invoiceNumber . '_' . time() . '.pdf"',
                ]
            );

        } catch (Exception $e) {
            DB::rollBack();
            Log::error("Failed to generate invoice for booking #{$booking->id}: " . $e->getMessage(), [
                'request'    => $request->all(),
                'booking_id' => $booking->id,
                'exception'  => $e,
            ]);
            return sendError('Failed to generate invoice: ' . $e->getMessage());
        }
    }

    public function upcoming($request)
    {
        $today = Carbon::today();

        $bookings = $this->bookingModel::with(['user', 'venue', 'listing', 'promocode', 'addOns', 'user', 'latestInvoice'])
            ->where('user_id', auth()->id())->where('date', '>=', $today)
            ->orderBy('date', 'desc')->orderBy('start_time', 'asc')->get();

        return sendSuccess("My bookings fetched successfully", compact('bookings'));
    }

    public function recent($request)
    {
        $today = Carbon::today();

        $bookings = $this->bookingModel::with(['user', 'venue', 'listing', 'promocode', 'addOns', 'user', 'latestInvoice'])
            ->where('user_id', auth()->id())->where('date', '<', $today)
            ->orderBy('date', 'desc')->orderBy('start_time', 'asc')->get();

        return sendSuccess("Recent bookings fetched successfully", compact('bookings'));
    }

    protected function createSingleBooking(
        $user,
        array $formData,
        string $date,
        string $startTime,
        string $endTime,
        float $subtotal,
        float $discount,
        $promocode,
        $paymentIntentId,
        $chargeId,
        $listing,
        $request
    ) {
        try {
            $startTimeCarbon = Carbon::parse($startTime);
            $endTimeCarbon   = Carbon::parse($endTime);

            $totalPrice = round($subtotal - $discount, 2);

            $paymentStatus = match (trim($formData['payment_method'])) {
                'cash_on_arrival'              => BookingPaymentStatus::PENDING,
                'online_payment'               => BookingPaymentStatus::FULLY_PAID,
                'deposit_remaining_on_arrival' => BookingPaymentStatus::DEPOSIT_PAID,
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
                'subtotal'              => $subtotal,
                'discount_amount'       => $discount,
                'total_price'           => $totalPrice,
                'payment_status'        => $paymentStatus,
                'promocode_id'          => $promocode?->id,
                'date'                  => $date,
                'notes'                 => $formData['notes'],
                'venue_id'              => $formData['venue_id'],
                'status'                => BookingStatus::PENDING,
                'start_time'            => $startTimeCarbon,
                'end_time'              => $endTimeCarbon,
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
                $booking->resourcesAllocated()->attach($resource->id, ['quantity' => $resource->pivot->quantity]);
            }

            if (! empty($formData['add_ons'])) {
                $booking->addOns()->sync($formData['add_ons']);
            }

            if ($paymentStatus == BookingPaymentStatus::DEPOSIT_PAID) {
                $paymentOptions = $listing ? $listing->payment_options : null;

                if (! $paymentOptions || empty($paymentOptions['deposit'])) {
                    throw new Exception('Deposit configuration missing for this listing.');
                }

                $deposit = $paymentOptions['deposit'];

                if (! empty($deposit['amount']) && $deposit['amount'] > 0) {
                    $depositAmount = $deposit['amount'];
                } elseif (! empty($deposit['percentage']) && $deposit['percentage'] > 0) {
                    $depositAmount = round($totalPrice * ($deposit['percentage'] / 100), 2);
                } else {
                    throw new Exception('Invalid deposit configuration: must specify amount or percentage.');
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
                    'notes'             => 'Deposit payment for booking #' . $booking->booking_id,
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
                    'notes'      => 'Remaining payment pending for booking #' . $booking->booking_id,
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
                    'notes'             => $paymentStatus == BookingPaymentStatus::FULLY_PAID ? "Full payment for booking #{$booking->booking_id}" : "Payment pending for booking #{$booking->booking_id}",
                ]);
            }

            $booking->history()->create([
                'user_id'     => $user->id,
                'action'      => 'booking_created',
                'description' => 'Booking created by customer.',
            ]);

            if ($paymentStatus == BookingPaymentStatus::DEPOSIT_PAID) {
                $booking->history()->create([
                    'user_id'     => $user->id,
                    'description' => 'Deposit payment processed.',
                    'action'      => BookingPaymentStatus::DEPOSIT_PAID,
                ]);
            }

            createBookingInvoiceWithNotification($booking, $user);

            unlockSlot($request, $listing, $user, $booking);

            if ($booking->loyalty_points_earned > 0) {
                $user->addLoyaltyPoints($booking->loyalty_points_earned, $booking->id);
            }

            if ($paymentStatus == BookingPaymentStatus::FULLY_PAID) {
                autoConfirmBookingIfPaid($booking);
            }

            $booking->load(['listing:id,name', 'venue:id,name', 'user:id,name', 'invoices']);

            return $booking;

        } catch (Exception $e) {
            Log::error("Failed to create single booking: " . $e->getMessage(), [
                'date'       => $date,
                'start_time' => $startTime,
                'end_time'   => $endTime,
                'exception'  => $e,
            ]);
            return null;
        }
    }
}
