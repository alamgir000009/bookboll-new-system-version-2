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
            'payment_method'     => 'required|in:cash_on_arrival,paid_cheque,paid_wire_transfer',
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
                'full_payment_received' => in_array($paymentMethod, [BookingPaymentMethod::PAID_CHEQUE, BookingPaymentMethod::PAID_WIRE_TRANSFER]),
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

            createBookingInvoiceWithNotification($booking, $user);

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

        $maxBookings = $listing->max_bookings_per_slot ?? 1;
        if ($overlaps >= $maxBookings) {
            $errors['capacity'] = "Max {$maxBookings} bookings per slot reached ({$overlaps} existing)";
        }

        return $errors;
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

    public function createBooking($request)
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

            // Validate booking before creating
            $validationErrors = $this->validateBooking($formData);
            if (! empty($validationErrors)) {
                DB::rollBack();
                return sendError('Validation failed', $validationErrors, 422);
            }

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
                    return sendError('Invalid or inapplicable promocode');
                }

                $discount = $promocode->applyDiscount($subtotal);
                $promocode->incrementUsage($user->id);
            }

            $totalPrice = $subtotal - $discount;

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
                'description' => 'Booking created via API.',
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

            DB::commit();

            $booking->load(['listing:id,name', 'venue:id,name', 'user:id,name', 'invoices']);

            return sendSuccess('Booking confirmed successfully', compact('booking'));

        } catch (Exception $e) {

            DB::rollBack();
            Log::error("Order placing Failed: " . $e->getMessage(), ["Exception" => $e]);
            return sendError("Something went wrong please try again later: " . $e->getMessage());
        }
    }

    public function update($request, $bookingId)
    {
        $booking = $this->bookingModel::with('promocode')->findOrFail($bookingId);

        $formData = $request->validate([
            'remove_promocode' => 'sometimes|boolean',
        ]);

        try {
            DB::beginTransaction();

            $oldPromocode = $booking->promocode;

            if (($formData['remove_promocode'] ?? false) && $oldPromocode) {

                $originalDiscount = $booking->discount_amount ?? 0;

                $oldPromocode->decrementUsage($booking->user_id);

                $booking->update([
                    'discount_amount' => 0,
                    'promocode_id'    => null,
                    'total_price'     => $booking->subtotal ?? $booking->total_price,
                ]);

                if ($booking->payment_method === 'deposit_remaining_on_arrival' && $booking->payment_breakdown) {
                    $updatedBreakdown                     = $booking->payment_breakdown;
                    $currentRemaining                     = $updatedBreakdown['remaining_amount'] ?? 0;
                    $updatedBreakdown['remaining_amount'] = $currentRemaining + $originalDiscount;
                    $booking->payment_breakdown           = $updatedBreakdown;
                    $booking->save();
                }

                $booking->history()->create([
                    'user_id'     => auth()->id(),
                    'action'      => 'promocode_removed',
                    'description' => "Promocode {$oldPromocode->code} removed. Discount (QAR {$originalDiscount}) restored.",
                ]);

                Log::info("Promocode removed from booking #{$booking->booking_id} by admin " . auth()->id());
            }

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

            DB::commit();

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

}
