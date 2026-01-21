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
use App\Models\SportsCategory;
use App\Models\Transaction;
use App\Models\User;
use App\Models\Venue;
use App\Notifications\BookingCancelledUserNotification;
use App\Notifications\BookingConfirmedUserNotification;
use App\Notifications\BookingUpdatedUserNotification;
use App\Services\OneSignalService;
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
use Stripe\PaymentIntent;
use Stripe\Stripe;

class BookingService
{
    public function __construct(
        protected Booking $bookingModel,
        protected Payment $paymentModel,
        protected OneSignalService $oneSignalService
    ) {}

    public function all($request)
    {
        $records_per_page = $request->records_per_page ?? 10;
        $search           = $request->search ?? null;
        $user_id          = $request->user_id ?? null;

        $query = $this->bookingModel::with(['user', 'venue', 'listing', 'promocode', 'addOns', 'user'])
            ->when($search, function ($q) use ($search) {
                $q->where(function ($q) use ($search) {
                    $q->whereHas('listing', fn($l) => $l->where('name', 'LIKE', "%{$search}%"))
                        ->orWhere('duration', 'LIKE', "%{$search}%")
                        ->orWhere('total_price', 'LIKE', "%{$search}%")
                        ->orWhere('payment_method', 'LIKE', "%{$search}%")
                        ->orWhere('notes', 'LIKE', "%{$search}%")
                        ->orWhere('date', 'LIKE', "%{$search}%")
                        ->orWhere('created_at', 'LIKE', "%{$search}%")
                        ->orWhere('booking_id', 'LIKE', "%{$search}%")
                        ->orWhere('subtotal', 'LIKE', "%{$search}%");
                });
            })
            ->when($request->status, fn($q, $status) => $q->where('status', $status))
            ->when($request->from_date, fn($q, $from) => $q->whereDate('date', '>=', $from))
            ->when($request->to_date, fn($q, $to) => $q->whereDate('date', '<=', $to))
            ->when($request->venue, function ($q, $venue) {
                $q->where('venue_id', 'LIKE', "%{$venue}%");
            })
            ->when($request->user, function ($q, $user) {
                $q->whereHas('user', fn($u) => $u->where('name', 'LIKE', "%{$user}%")->orWhere('email', 'LIKE', "%{$user}%"));
            })
            ->when($user_id, function ($q, $user_id) {
                $q->whereRelation('user', 'id', $user_id);
            })
            ->latest('id');

        $query = applyVenueFilter($query, 'venue_id');

        $bookings = $records_per_page == 'all' ? $query->get() : $query->paginate($records_per_page);

        return sendSuccess('Bookings fetched successfully', compact('bookings'));
    }

    public function getBookingsForExport($request)
    {
        $search    = $request->search ?? null;
        $status    = $request->status ?? null;
        $venue     = $request->venue ?? null;
        $user      = $request->user ?? null;
        $user_id   = $request->user_id ?? null;
        $from_date = $request->from_date ?? null;
        $to_date   = $request->to_date ?? null;
        $exportAll = $request->export_all == 1 || $request->export_all === '1';

        $query = $this->bookingModel::with(['user', 'venue', 'listing', 'promocode', 'addOns'])
            ->when($search, function ($q) use ($search) {
                $q->where(function ($q) use ($search) {
                    $q->whereHas('listing', fn($l) => $l->where('name', 'LIKE', "%{$search}%"))
                        ->orWhere('duration', 'LIKE', "%{$search}%")
                        ->orWhere('total_price', 'LIKE', "%{$search}%")
                        ->orWhere('payment_method', 'LIKE', "%{$search}%")
                        ->orWhere('notes', 'LIKE', "%{$search}%")
                        ->orWhere('date', 'LIKE', "%{$search}%")
                        ->orWhere('created_at', 'LIKE', "%{$search}%")
                        ->orWhere('booking_id', 'LIKE', "%{$search}%")
                        ->orWhere('subtotal', 'LIKE', "%{$search}%");
                });
            })
            ->when($status, fn($q, $status) => $q->where('status', $status))
            ->when($from_date, fn($q, $from) => $q->whereDate('date', '>=', $from))
            ->when($to_date, fn($q, $to) => $q->whereDate('date', '<=', $to))
            ->when($venue, function ($q, $venue) {
                $q->where('venue_id', 'LIKE', "%{$venue}%");
            })
            ->when($user, function ($q, $user) {
                $q->whereHas('user', fn($u) => $u->where('name', 'LIKE', "%{$user}%"));
            })
            ->when($user_id, function ($q, $user_id) {
                $q->whereRelation('user', 'id', $user_id);
            })
            ->latest('id');

        $query = applyVenueFilter($query, 'venue_id');

        if (! $exportAll) {
            $records_per_page = $request->records_per_page ?? 10;
            $page             = $request->page ?? 1;
            $query->skip(($page - 1) * $records_per_page)->take($records_per_page);
        }

        return $query->get();
    }

    public function getBooking($booking_id)
    {
        $booking = $this->bookingModel::with([
            'addOns',
            'history',
            'payments',
            'invoices',
            'promocode',
            'latestInvoice',
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

        try {
            DB::beginTransaction();

            $giftCardAmount = $booking->gift_card_amount ?? 0;
            $user           = $booking->user;
            if ($giftCardAmount > 0) {
                $existingRefund = Transaction::where('booking_id', $booking->id)
                    ->where('type', 'credit')
                    ->where('reason', 'LIKE', '%Gift card balance refunded%')
                    ->first();

                if (! $existingRefund) {
                    $user->transactions()->create([
                        'type'       => 'credit',
                        'booking_id' => $booking->id,
                        'amount'     => $giftCardAmount,
                        'reason'     => "Gift card balance refunded for cancelled booking #{$booking->booking_id}",
                    ]);
                }
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

            $this->sendBookingNotification($booking, 'cancelled');

            DB::commit();

            $booking->load('venue:id,name,maps_url');
            try {
                $user->notify(new BookingCancelledUserNotification($booking));
            } catch (Exception $e) {
                Log::error("Booking cancellation email error: " . $e->getMessage(), ["Exception" => $e]);
            }

            return sendSuccess('Booking cancelled successfully.', compact('booking'));

        } catch (Exception $e) {
            DB::rollBack();
            Log::error("Booking cancellation error: " . $e->getMessage(), ["Exception" => $e]);
            return sendError("Something went wrong: " . $e->getMessage());
        }
    }

    public function dashboardData()
    {
        $recentBookingsQuery = $this->bookingModel->whereNotIn('status', [BookingStatus::COMPLETED])->latest('id');
        $recentBookingsQuery = applyVenueFilter($recentBookingsQuery, 'venue_id');
        $recentBookings      = $recentBookingsQuery->take(3)->get();

        $total_redeemed = LoyaltyPoint::where('type', 'debit')->sum('points');
        $total_earned   = LoyaltyPoint::where('type', 'credit')->sum('points');

        $totalCollectedQuery = $this->paymentModel->query()->where('status', PaymentStatus::PAID);
        $totalCollectedQuery = applyVenueFilterToPayments($totalCollectedQuery);
        $totalCollected      = $totalCollectedQuery->sum('amount');

        $totalPendingQuery = $this->paymentModel->query()->where('status', PaymentStatus::PENDING);
        $totalPendingQuery = applyVenueFilterToPayments($totalPendingQuery);
        $totalPending      = $totalPendingQuery->sum('amount');

        $widgets = [
            [
                "name"       => "Total Revenue",
                "value"      => round($totalCollected, 2),
                "change"     => "",
                "changeType" => "increase",
                "icon"       => "CurrencyDollarIcon",
            ],
            [
                "name"       => "Pending Payments",
                "value"      => round($totalPending, 2),
                "change"     => "",
                "changeType" => "increase",
                "icon"       => "ClockIcon",
            ],
            [
                "name"       => "Total Bookings",
                "value"      => applyVenueFilter($this->bookingModel->newQuery(), 'venue_id')->count(),
                "change"     => "",
                "changeType" => "increase",
                "icon"       => "CalendarDaysIcon",
            ],
            [
                "name"       => "Active Venues",
                "value"      => applyVenueFilter(Venue::active(), 'id')->count(),
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

        $venuesQuery = Venue::with(['listings:id,venue_id,name']);
        $venuesQuery = applyVenueFilter($venuesQuery, 'id');
        $venues      = $venuesQuery->get(['id', 'name']);

        $listingsQuery = Listing::with(['venue']);
        $listingsQuery = applyVenueFilter($listingsQuery, 'venue_id');
        $listings      = $listingsQuery->get(['id', 'name', 'venue_id']);

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

        $query = $this->paymentModel::join('bookings', 'payments.booking_id', '=', 'bookings.id')
            ->where(function ($q) use ($filters) {
                $q->whereBetween('payments.date', [$filters['dateFrom'], $filters['dateTo']])
                    ->orWhere(function ($subQ) use ($filters) {
                        $subQ->whereNull('payments.date')
                            ->whereBetween('payments.created_at', [
                                Carbon::parse($filters['dateFrom'])->startOfDay(),
                                Carbon::parse($filters['dateTo'])->endOfDay(),
                            ]);
                    });
            })
            ->when(! empty($filters['selectedVenues']), function ($q) use ($filters) {
                return $q->whereIn('bookings.venue_id', $filters['selectedVenues']);
            })
            ->when(! empty($filters['selectedListings']), function ($q) use ($filters) {
                return $q->whereIn('bookings.listing_id', $filters['selectedListings']);
            });

        $user = auth()->user();
        if ($user && ! $user->hasAnyRole(['super_admin', 'admin'])) {
            $venueIds = DB::table('user_venue')
                ->where('user_id', $user->id)
                ->pluck('venue_id')
                ->toArray();
            if (! empty($venueIds)) {
                $query->whereIn('bookings.venue_id', $venueIds);
                if (! empty($filters['selectedVenues'])) {
                    $intersected = array_intersect($filters['selectedVenues'], $venueIds);
                    if (empty($intersected)) {
                        $query->whereRaw('1 = 0');
                    } else {
                        $query->whereIn('bookings.venue_id', $intersected);
                    }
                }
            } else {
                $query->whereRaw('1 = 0');
            }
        }

        $payments = $query->select([
            'payments.id',
            DB::raw('DATE_FORMAT(payments.date, "%Y-%m-%d") as date'),
            'payments.status',
            'payments.method',
            'payments.amount',
            'payments.created_at',
            'bookings.venue_id as venue_id',
        ])->get()->map(function ($payment) {
            $paymentArray = $payment->toArray();
            if (isset($paymentArray['date']) && $paymentArray['date']) {
                $paymentArray['date'] = substr($paymentArray['date'], 0, 10);
            }
            return $paymentArray;
        })->toArray();

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
            $pendingTotal   += $data['pending'];
            $maxValue        = max($maxValue, $data['collected'], $data['pending']);
            $chartData[]     = [$period, $data['collected'], $data['pending']];
        }

        $transactionCount   = count($payments);
        $paidPayments       = array_filter($payments, fn($p) => $p['status'] === PaymentStatus::PAID);
        $averageTransaction = ! empty($paidPayments) ? $collectedTotal / count($paidPayments) : 0;

        return sendSuccess('Chart data fetched', [
            'chartData'    => $chartData,
            'maxValue'     => $maxValue ?: 1000,
            'paymentStats' => [
                'total_collected_payments' => round($collectedTotal, 2),
                'total_pending_payments'   => round($pendingTotal, 2),
                'total_transactions_count' => $transactionCount,
                'average_transaction'      => round($averageTransaction, 2),
            ],
        ]);
    }

    private function groupPaymentsByPeriod(array $payments, string $timeRange, array $dateRange)
    {
        $grouped                                 = [];
        list($startYear, $startMonth, $startDay) = explode('-', $dateRange[0]);
        list($endYear, $endMonth, $endDay)       = explode('-', $dateRange[1]);

        $start = Carbon::createFromDate((int) $startYear, (int) $startMonth, (int) $startDay, config('app.timezone'))->startOfDay();
        $end   = Carbon::createFromDate((int) $endYear, (int) $endMonth, (int) $endDay, config('app.timezone'))->endOfDay();

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
            $amount = max(0, (float) ($payment['amount'] ?? 0));

            $paymentDate = $payment['date'] ?? null;
            $createdAt   = $payment['created_at'] ?? null;

            if (! $paymentDate && ! $createdAt) {
                continue;
            }

            if ($paymentDate) {

                $dateStr = is_string($paymentDate)
                    ? substr($paymentDate, 0, 10)
                    : ($paymentDate instanceof \Carbon\Carbon
                        ? $paymentDate->format('Y-m-d')
                        : (string) $paymentDate);

                list($year, $month, $day) = explode('-', $dateStr);
                $date                     = Carbon::createFromDate((int) $year, (int) $month, (int) $day, config('app.timezone'))
                    ->startOfDay();
            } else {
                $date = Carbon::parse($createdAt)->setTimezone(config('app.timezone'))->startOfDay();
            }

            if ($paymentDate && ($date->lt($start) || $date->gt($end))) {
                continue;
            }

            $key = match ($timeRange) {
                'daily'   => $date->format('Y-m-d'),
                'weekly'  => $date->copy()->startOfWeek()->format('Y-m-d'),
                'monthly' => $date->format('Y-m'),
                default   => $date->format('Y-m-d'),
            };

            if ($payment['status'] === PaymentStatus::PAID) {
                if (isset($grouped[$key])) {
                    $grouped[$key]['collected'] += $amount;
                }
            } else {
                if (! isset($grouped[$key]) && ! $payment['date']) {
                    $lastKey = array_key_last($grouped);
                    if ($lastKey) {
                        $grouped[$lastKey]['pending'] += $amount;
                    }
                } elseif (isset($grouped[$key])) {
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
        $customers   = User::whereHas('roles', fn($q) => $q->where('name', 'customer'))->active()->get(['id', 'name', 'email']);
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
            ->where('status', '!=', BookingStatus::CANCELLED)
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

        return sendSuccess('Due bookings fetched successfully', compact('dueBookings'));
    }

    public function store($request)
    {
        $formData = $request->validate([
            'bookings'                      => 'required|array',
            'bookings.*.venue_id'           => 'required|exists:venues,id',
            'bookings.*.listing_id'         => 'required|exists:listings,id',
            'bookings.*.user_id'            => 'required|exists:users,id',
            'bookings.*.date'               => 'required|date',
            'bookings.*.start_time'         => 'required|date_format:H:i',
            'bookings.*.end_time'           => 'required|date_format:H:i',
            'bookings.*.payment_method'     => 'nullable|in:payment_skipped,cash_on_arrival,paid_cheque,paid_wire_transfer',
            'bookings.*.selected_add_ons'   => 'nullable|array',
            'bookings.*.selected_add_ons.*' => 'exists:add_ons,id',
            'bookings.*.promocode'          => 'nullable|string|exists:promocodes,code',
            'bookings.*.notes'              => 'nullable|string|max:1000',
            'bookings.*.subtotal'           => 'nullable|numeric',
            'bookings.*.total_price'        => 'nullable|numeric',
        ]);

        $validationErrors = [];
        foreach ($formData['bookings'] as $index => $bookingData) {
            $errors = $this->validateBooking($bookingData);
            if (! empty($errors)) {
                $validationErrors[$index] = $errors;
            }
        }

        if (! empty($validationErrors)) {
            return sendError('Validation failed', $validationErrors, 422);
        }

        DB::beginTransaction();

        try {
            $bookings = [];
            foreach ($formData['bookings'] as $bookingData) {

                $listing = Listing::find($bookingData['listing_id']);
                $user    = User::find($bookingData['user_id']);

                $subtotal      = $bookingData['subtotal'];
                $discount      = $bookingData['discount_amount'] ?? 0;
                $totalPrice    = $bookingData['total_price'];
                $paymentMethod = $bookingData['payment_method'];

                $promocode = null;
                if (! empty($bookingData['promocode'])) {
                    $promocode = Promocode::where('code', strtoupper($bookingData['promocode']))->active()->first();
                    if (! $promocode) {
                        return sendError('Invalid promocode.');
                    }
                }

                $paymentStatus = match (trim($paymentMethod)) {
                    'cash_on_arrival'    => BookingPaymentStatus::PENDING,
                    'paid_cheque'        => BookingPaymentStatus::FULLY_PAID,
                    'paid_wire_transfer' => BookingPaymentStatus::FULLY_PAID,
                    'payment_skipped'    => BookingPaymentStatus::PAYMENT_SKIPPED,
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
                    'date'                  => $bookingData['date'],
                    'notes'                 => $bookingData['notes'],
                    'venue_id'              => $bookingData['venue_id'],
                    'end_time'              => $bookingData['end_time'],
                    'status'                => BookingStatus::PENDING,
                    'start_time'            => $bookingData['start_time'],
                    'listing_id'            => $bookingData['listing_id'],
                    'loyalty_points_earned' => $listing->loyalty_points ?? 0,
                    'full_payment_received' => in_array($paymentMethod, [
                        BookingPaymentMethod::PAID_CHEQUE,
                        BookingPaymentMethod::PAID_WIRE_TRANSFER,
                    ]),
                    'is_free'               => $paymentMethod === BookingPaymentMethod::PAYMENT_SKIPPED,
                    'payment_breakdown'     => [
                        'deposit_amount'   => $paymentStatus == BookingPaymentStatus::DEPOSIT_PAID ? $depositAmount : 0,
                        'deposit_method'   => $paymentStatus == BookingPaymentStatus::DEPOSIT_PAID ? PaymentMethod::ONLINE : null,
                        'remaining_amount' => $paymentStatus == BookingPaymentStatus::DEPOSIT_PAID ? $totalPrice - $depositAmount : 0,
                        'remaining_method' => $paymentStatus == BookingPaymentStatus::DEPOSIT_PAID ? PaymentMethod::CASH : null,
                    ],
                ]);

                if (! empty($bookingData['selected_add_ons'])) {
                    $booking->addOns()->attach($bookingData['selected_add_ons']);
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

                $isFreeBooking = $paymentMethod === BookingPaymentMethod::PAYMENT_SKIPPED || $booking->is_free;

                if (! $isFreeBooking) {
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
                } else {
                    $booking->history()->create([
                        'user_id'     => $user->id,
                        'action'      => 'booking_created',
                        'description' => 'This booking is created with payment skipped by ' . auth()->user()->name . ' it marked as payment skipped',
                    ]);
                }

                unlockListingSlot($listing, $user, $booking);

                $bookings[] = $booking->load(['user', 'venue', 'listing', 'payments', 'addOns']);
            }

            DB::commit();

            return sendSuccess('Bookings created successfully', compact('bookings'));

        } catch (Exception $e) {
            DB::rollBack();
            Log::error('Booking creation failed: ' . $e->getMessage(), ["Exception" => $e]);
            return sendError('Failed to create bookings', ['error' => $e->getMessage()], 500);
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

        $dataStartCarbon = Carbon::createFromFormat('H:i', $data['start_time']);
        $dataEndCarbon   = Carbon::createFromFormat('H:i', $data['end_time']);

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

        $resourceConflicts = [];
        foreach ($listing->resourceRequirements ?? [] as $resource) {
            $currentUsage = DB::table('booking_resource')
                ->join('bookings', 'booking_resource.booking_id', '=', 'bookings.id')
                ->where('bookings.date', $data['date'])
                ->where('bookings.status', '!=', BookingStatus::CANCELLED)
                ->where('bookings.id', '!=', $editingId)
                ->where('booking_resource.resource_id', $resource->id)
                ->where(function ($q) use ($data) {
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

        $overlaps = $this->bookingModel::where('date', $data['date'])
            ->where('listing_id', $data['listing_id'])
            ->where('id', '!=', $editingId)
            ->where(function ($q) use ($data) {
                $q->where(function ($query) use ($data) {
                    $query->whereRaw('TIME(start_time) < ?', [$data['end_time']])
                        ->whereRaw('TIME(end_time) > ?', [$data['start_time']]);
                });
            })
            ->where('status', '!=', BookingStatus::CANCELLED)
            ->count();

        $bookingQuantity = $data['quantity'] ?? 1;
        $maxBookings     = $listing->max_bookings_per_slot ?? 1;

        $availableSlots = $maxBookings - $overlaps;
        if ($bookingQuantity > $availableSlots) {
            if ($bookingQuantity > 1) {
                $errors['capacity'] = "Requested {$bookingQuantity} booking(s) but only {$availableSlots} slot(s) available. Max {$maxBookings} bookings per slot ({$overlaps} already booked)";
            } else {
                $errors['capacity'] = "No available slots. Max {$maxBookings} bookings per slot ({$overlaps} already booked)";
            }
        }

        return $errors;
    }

    public function bookingsForCalendar($request)
    {
        $query = $this->bookingModel::with([
            'user.tags',
            'user:id,name',
            'venue:id,name',
            'listing:id,name,calendar_color',
        ]);

        if ($request->boolean('week_view')) {
            $referenceDate = $request->date ? Carbon::parse($request->date) : Carbon::now();

            $weekStart = $referenceDate->copy()->startOfWeek(Carbon::SUNDAY);

            $weekEnd = $referenceDate->copy()->endOfWeek(Carbon::SATURDAY);

            $query->whereBetween('date', [
                $weekStart->format('Y-m-d'),
                $weekEnd->format('Y-m-d'),
            ]);
        } else {
            $query->when($request->date, fn($q) =>
                $q->whereDate('date', $request->date)
            );
        }

        $query = applyVenueFilter($query, 'venue_id');

        if ($request->venue_id) {
            $user = auth()->user();
            if ($user && ! $user->hasAnyRole(['super_admin', 'admin'])) {
                $venueIds = DB::table('user_venue')
                    ->where('user_id', $user->id)
                    ->pluck('venue_id')
                    ->toArray();
                if (! in_array($request->venue_id, $venueIds)) {
                    $query->whereRaw('1 = 0');
                } else {
                    $query->where('venue_id', $request->venue_id);
                }
            } else {
                $query->when($request->venue_id, fn($q) =>
                    $q->where('venue_id', $request->venue_id)
                );
            }
        }

        $query->when($request->listing_id, fn($q) =>
            $q->where('listing_id', $request->listing_id)
        );

        $query->when($request->user_id, fn($q) =>
            $q->where('user_id', $request->user_id)
        );

        if ($request->has('statuses') && is_array($request->statuses)) {
            $query->whereIn('status', $request->statuses);
        } else {
            // Exclude cancelled bookings by default
            $query->where('status', '!=', BookingStatus::CANCELLED);
        }

        $bookings = $query->get();

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
            'phone'                           => 'nullable|string|min:5',
            'add_ons'                         => 'nullable|array',
            'add_ons.*'                       => 'exists:add_ons,id',
            'quantity'                        => 'required|numeric|min:1',
            'recurring_bookings'              => 'nullable|array',
            'recurring_bookings.*.date'       => 'required|date|after:today',
            'recurring_bookings.*.start_time' => 'required|date_format:H:i',
            'recurring_bookings.*.end_time'   => 'required|date_format:H:i',
            'recurring_bookings.*.price'      => 'required|numeric|min:0',
            'recurring_bookings.*.quantity'   => 'required|numeric|min:1',
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
                    'date'     => $recurring['date'],
                    'quantity' => $recurring['quantity'] ?? 1,
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
        logger("Booking creation request", ["Request" => $request->all()]);
        $user = $request->user_id ? User::find($request->user_id) : auth()->user();

        $formData = $request->validate([
            'venue_id'                        => 'required|exists:venues,id',
            'listing_id'                      => 'required|exists:listings,id',
            'promocode'                       => 'nullable|string',
            'date'                            => 'required|date|after_or_equal:today',
            'start_time'                      => 'required|date_format:H:i',
            'end_time'                        => 'required|date_format:H:i',
            'payment_method'                  => 'required|in:deposit_remaining_on_arrival,online_payment,cash_on_arrival,paid_cash,paid_cheque,paid_credit_card,paid_wire,paid_wire_transfer,payment_skipped',
            'notes'                           => 'nullable|string|max:500',
            'add_ons'                         => 'nullable|array',
            'add_ons.*'                       => 'exists:add_ons,id',
            'phone'                           => 'nullable|string|min:5',
            'payment_method_id'               => 'nullable|string',
            'payment_intent_id'               => 'nullable|required_if:payment_method,online_payment,deposit_remaining_on_arrival',
            'save_payment_details'            => 'nullable|boolean',
            'quantity'                        => 'required|numeric|min:1',
            'recurring_bookings'              => 'nullable|array',
            'recurring_bookings.*.date'       => 'required|date|after:today',
            'recurring_bookings.*.start_time' => 'required|date_format:H:i',
            'recurring_bookings.*.end_time'   => 'required|date_format:H:i',
            'recurring_bookings.*.price'      => 'required|numeric|min:0',
            'recurring_bookings.*.quantity'   => 'required|numeric|min:1',
            'recurring_bookings.*.add_ons'    => 'nullable|array',
            'recurring_bookings.*.add_ons.*'  => 'exists:add_ons,id',
            'use_gift_card_balance'           => 'nullable|boolean',
            'gift_card_balance_used'          => 'nullable|numeric|min:0',
        ]);

        if (isset($formData['notes'])) {
            $formData['notes'] = strip_tags($formData['notes']);
        } else {
            $formData['notes'] = null;
        }

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
                    try {
                        // Verify payment directly with Stripe API instead of relying on Cashier's findPayment
                        // This ensures we check the actual payment status even if it's not synced in the database yet
                        Stripe::setApiKey(config('services.stripe.secret'));
                        $paymentIntent = PaymentIntent::retrieve($formData['payment_intent_id']);

                        // Verify the payment intent belongs to the user's Stripe customer (if both exist)
                        if ($user->stripe_id && $paymentIntent->customer && $paymentIntent->customer !== $user->stripe_id) {
                            Log::error('Payment intent customer mismatch', [
                                'payment_intent_id' => $formData['payment_intent_id'],
                                'user_id' => $user->id,
                                'user_stripe_id' => $user->stripe_id,
                                'payment_intent_customer' => $paymentIntent->customer,
                            ]);
                            DB::rollBack();
                            return sendError('Payment verification failed: Payment does not belong to this user');
                        }

                        // Log warning if user has stripe_id but payment intent doesn't have customer
                        if ($user->stripe_id && !$paymentIntent->customer) {
                            Log::warning('Payment intent created without customer association', [
                                'payment_intent_id' => $formData['payment_intent_id'],
                                'user_id' => $user->id,
                                'user_stripe_id' => $user->stripe_id,
                            ]);
                        }

                        // Check if payment succeeded
                        if ($paymentIntent->status !== 'succeeded') {
                            Log::error('Payment intent not succeeded', [
                                'payment_intent_id' => $formData['payment_intent_id'],
                                'status' => $paymentIntent->status,
                                'user_id' => $user->id,
                            ]);
                            DB::rollBack();
                            return sendError('Payment not confirmed. Payment status: ' . $paymentIntent->status);
                        }

                        $paymentIntentId = $formData['payment_intent_id'];
                        $chargeId        = $paymentIntent->latest_charge;

                        if (empty($chargeId)) {
                            Log::error('No charge ID found for payment intent', [
                                'payment_intent_id' => $formData['payment_intent_id'],
                                'user_id' => $user->id,
                            ]);
                            DB::rollBack();
                            return sendError('Payment verification failed: No charge found');
                        }

                    } catch (\Stripe\Exception\InvalidRequestException $e) {
                        Log::error('Stripe payment verification error', [
                            'payment_intent_id' => $formData['payment_intent_id'],
                            'user_id' => $user->id,
                            'error' => $e->getMessage(),
                        ]);
                        DB::rollBack();
                        return sendError('Payment verification failed: ' . $e->getMessage());
                    } catch (Exception $e) {
                        Log::error('Payment verification error', [
                            'payment_intent_id' => $formData['payment_intent_id'],
                            'user_id' => $user->id,
                            'error' => $e->getMessage(),
                        ]);
                        DB::rollBack();
                        return sendError('Payment verification failed: ' . $e->getMessage());
                    }
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
            $listing         = Listing::with(['pricingGroups'])->findOrFail($formData['listing_id']);

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

            $recurringBookings      = $formData['recurring_bookings'] ?? [];
            $totalRecurringBookings = 0;
            foreach ($recurringBookings as $recurring) {
                $totalRecurringBookings += $recurring['quantity'] ?? 1;
            }
            $totalBookings = $formData['quantity'] + $totalRecurringBookings;

            $baseSubtotal      = round($singleSubtotal * $formData['quantity'], 2);
            $recurringSubtotal = 0;
            foreach ($recurringBookings as $recurring) {
                $recurringAddOnCost = ! empty($recurring['add_ons'])
                    ? AddOn::whereIn('id', $recurring['add_ons'])->sum('price')
                    : 0;

                $recurringSingleSubtotal  = round($recurring['price'] + $recurringAddOnCost, 2);
                $recurringQuantity        = $recurring['quantity'] ?? 1;
                $recurringSubtotal       += $recurringSingleSubtotal * $recurringQuantity;
            }
            $combinedSubtotal = round($baseSubtotal + $recurringSubtotal, 2);

            $giftCardBalanceUsed = 0;
            if (! empty($formData['use_gift_card_balance']) && $formData['use_gift_card_balance']) {
                $userGiftCardBalance = Transaction::balance($user->id) ?? 0;
                $requestedAmount     = $formData['gift_card_balance_used'] ?? 0;

                if ($requestedAmount > 0 && $userGiftCardBalance > 0) {
                    $giftCardBalanceUsed = min($requestedAmount, $userGiftCardBalance);
                }
            }

            $totalDiscount = 0;
            $promocode     = null;
            if (! empty($formData['promocode'])) {
                $promocode = Promocode::where('code', $formData['promocode'])->active()->first();

                if (! $promocode) {
                    return sendError('Invalid or inactive promocode');
                }

                $isValidPromocode = $promocode->isValidForOrder(
                    $combinedSubtotal,
                    $user->id,
                    $formData['payment_method'],
                    $formData['venue_id'],
                    $formData['listing_id']
                );

                if (! $isValidPromocode) {
                    return sendError('Promocode is not applicable');
                }

                $totalDiscount = $promocode->applyDiscount($combinedSubtotal);
                $promocode->incrementUsage($user->id);
            }

            if ($giftCardBalanceUsed > 0) {
                $totalAfterDiscount  = $combinedSubtotal - $totalDiscount;
                $giftCardBalanceUsed = min($giftCardBalanceUsed, $totalAfterDiscount);
            }

            $discounts       = [];
            $giftCardAmounts = [];

            if ($totalBookings > 0) {
                if ($totalDiscount > 0) {
                    $baseDiscountPerBooking = round($totalDiscount / $totalBookings, 2);

                    $calculatedTotal = $baseDiscountPerBooking * $totalBookings;

                    $roundingDifference = round($totalDiscount - $calculatedTotal, 2);

                    for ($i = 0; $i < $totalBookings; $i++) {
                        if ($i === 0) {
                            $discounts[] = round($baseDiscountPerBooking + $roundingDifference, 2);
                        } else {
                            $discounts[] = $baseDiscountPerBooking;
                        }
                    }

                    $verifyTotal = round(array_sum($discounts), 2);
                    if (abs($verifyTotal - $totalDiscount) > 0.01) {
                        $adjustment   = round($totalDiscount - $verifyTotal, 2);
                        $discounts[0] = round($discounts[0] + $adjustment, 2);
                    }
                } else {
                    for ($i = 0; $i < $totalBookings; $i++) {
                        $discounts[] = 0;
                    }
                }

                if ($giftCardBalanceUsed > 0) {
                    $baseGiftCardPerBooking = round($giftCardBalanceUsed / $totalBookings, 2);
                    $calculatedTotal        = $baseGiftCardPerBooking * $totalBookings;
                    $roundingDifference     = round($giftCardBalanceUsed - $calculatedTotal, 2);

                    for ($i = 0; $i < $totalBookings; $i++) {
                        if ($i === 0) {
                            $giftCardAmounts[] = round($baseGiftCardPerBooking + $roundingDifference, 2);
                        } else {
                            $giftCardAmounts[] = $baseGiftCardPerBooking;
                        }
                    }

                    $verifyTotal = round(array_sum($giftCardAmounts), 2);
                    if (abs($verifyTotal - $giftCardBalanceUsed) > 0.01) {
                        $adjustment         = round($giftCardBalanceUsed - $verifyTotal, 2);
                        $giftCardAmounts[0] = round($giftCardAmounts[0] + $adjustment, 2);
                    }
                } else {
                    for ($i = 0; $i < $totalBookings; $i++) {
                        $giftCardAmounts[] = 0;
                    }
                }
            }

            $discountIndex = 0;
            $giftCardIndex = 0;

            for ($i = 0; $i < $formData['quantity']; $i++) {
                $bookingDiscount       = $discounts[$discountIndex] ?? 0;
                $bookingGiftCardAmount = $giftCardAmounts[$giftCardIndex] ?? 0;
                $discountIndex++;
                $giftCardIndex++;

                $booking = $this->createSingleBooking(
                    $user,
                    $formData,
                    $formData['date'],
                    $formData['start_time'],
                    $formData['end_time'],
                    $singleSubtotal,
                    $bookingDiscount,
                    $bookingGiftCardAmount,
                    $promocode,
                    $paymentIntentId,
                    $chargeId,
                    $listing,
                    $request
                );

                if (! $booking) {
                    DB::rollBack();
                    Log::error("First: Failed to create booking for user {$user->id} on {$formData['date']}");
                    return sendError('Failed to create booking');
                }

                $createdBookings->push($booking);
            }

            foreach ($recurringBookings as $recurring) {
                if ($recurring['start_time'] !== $formData['start_time'] || $recurring['end_time'] !== $formData['end_time']) {
                    DB::rollBack();
                    Log::error("Recurring booking times do not match base booking times for user {$user->id} on {$recurring['date']}");
                    return sendError('Recurring booking times must match base booking times');
                }

                $recurringDate = Carbon::parse($recurring['date']);
                $baseDate      = Carbon::parse($formData['date']);
                if ($recurringDate->lte($baseDate)) {
                    DB::rollBack();
                    Log::error("Recurring booking dates must be after the base booking date for user {$user->id} on {$recurring['date']}");
                    return sendError('Recurring booking dates must be after the base booking date');
                }

                $recurringValidationData = array_merge($formData, ['date' => $recurring['date']]);

                $validationErrors = $this->validateBooking($recurringValidationData);
                if (! empty($validationErrors)) {
                    DB::rollBack();
                    return sendError('Validation failed for recurring booking on ' . $recurring['date'], $validationErrors, 422);
                }

                $recurringAddOnCost = ! empty($recurring['add_ons'])
                    ? AddOn::whereIn('id', $recurring['add_ons'])->sum('price')
                    : 0;

                $recurringSingleSubtotal = round($recurring['price'] + $recurringAddOnCost, 2);

                $recurringQuantity = $recurring['quantity'] ?? 1;

                $recurringFormData            = $formData;
                $recurringFormData['add_ons'] = $recurring['add_ons'] ?? [];

                for ($i = 0; $i < $recurringQuantity; $i++) {
                    $bookingDiscount       = $discounts[$discountIndex] ?? 0;
                    $bookingGiftCardAmount = $giftCardAmounts[$giftCardIndex] ?? 0;
                    $discountIndex++;
                    $giftCardIndex++;

                    $booking = $this->createSingleBooking(
                        $user,
                        $recurringFormData,
                        $recurring['date'],
                        $recurring['start_time'],
                        $recurring['end_time'],
                        $recurringSingleSubtotal,
                        $bookingDiscount,
                        $bookingGiftCardAmount,
                        $promocode,
                        $paymentIntentId,
                        $chargeId,
                        $listing,
                        $request
                    );

                    if (! $booking) {
                        DB::rollBack();
                        Log::error("Second: Failed to create recurring booking for user {$user->id} on {$recurring['date']}");
                        return sendError('Failed to create recurring booking');
                    }

                    $createdBookings->push($booking);
                }
            }

            DB::commit();
            $bookingIds      = $createdBookings->pluck('id');
            $createdBookings = Booking::with(['user', 'venue', 'listing', 'payments', 'addOns'])
                ->whereIn('id', $bookingIds)
                ->get();

            foreach ($createdBookings as $booking) {
                $this->sendBookingNotification($booking, 'created');
            }

            return sendSuccess('Booking confirmed successfully', ['bookings' => $createdBookings]);

        } catch (Exception $e) {

            DB::rollBack();
            Log::error("Bookings Failed: " . $e->getMessage(), ["Exception" => $e]);
            return sendError("Something went wrong please try again later: " . $e->getMessage());
        }
    }

    public function update($request, $bookingId)
    {
        $booking = $this->bookingModel::with(['user', 'venue', 'listing', 'payments', 'addOns', 'resourcesAllocated', 'promocode'])
            ->findOrFail($bookingId);

        if ($booking->status != BookingStatus::PENDING) {
            return sendError('Cannot update ' . $booking->status . ' bookings.');
        }

        $formData = $request->validate([
            'user_id'            => 'sometimes|exists:users,id',
            'venue_id'           => 'sometimes|exists:venues,id',
            'listing_id'         => 'sometimes|exists:listings,id',
            'date'               => 'sometimes|date',
            'start_time'         => 'sometimes|date_format:H:i',
            'end_time'           => 'sometimes|date_format:H:i',
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

            $updateData = [];

            if (isset($formData['user_id']) && $formData['user_id'] != $booking->user_id) {
                $newUser               = User::findOrFail($formData['user_id']);
                $updateData['user_id'] = $newUser->id;
            }
            if (isset($formData['venue_id'])) {
                $updateData['venue_id'] = $formData['venue_id'];
            }
            if (isset($formData['listing_id'])) {
                $updateData['listing_id'] = $formData['listing_id'];
            }

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
            }

            if (isset($formData['notes'])) {
                $updateData['notes'] = $formData['notes'];
            }

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

            if (! empty($updateData)) {
                $booking->update($updateData);
            }

            if (isset($formData['selected_add_ons'])) {
                $booking->addOns()->sync($formData['selected_add_ons']);
            }

            if (isset($formData['listing_id'])) {
                $listing   = Listing::findOrFail($formData['listing_id']);
                $resources = $listing->resourceRequirements()->select('resources.id', 'listing_resource.quantity')->get();

                $booking->resourcesAllocated()->detach();
                foreach ($resources as $resource) {
                    $booking->resourcesAllocated()->attach($resource->id, ['quantity' => $resource->pivot->quantity]);
                }
            }

            if ($priceChanged) {
                $totalPaid       = $booking->payments()->where('status', PaymentStatus::PAID)->sum('amount');
                $priceDifference = $newPrice - $oldPrice;

                if ($priceDifference < 0) {
                    $refundAmount = abs($priceDifference);

                    if ($totalPaid > 0 && $totalPaid > $newPrice) {
                        $excessPaid = $totalPaid - $newPrice;

                        if ($excessPaid > 0) {
                            $booking->user->transactions()->create([
                                'type'       => 'credit',
                                'amount'     => $excessPaid,
                                'booking_id' => $booking->id,
                                'reason'     => "Refund for booking #{$booking->booking_id} price adjustment (QAR {$oldPrice} -> QAR {$newPrice})",
                            ]);

                            if ($booking->payment_status == BookingPaymentStatus::FULLY_PAID && $totalPaid > $newPrice) {
                                $excessAmount = $totalPaid - $newPrice;

                                Log::info('Excess payment detected for booking', [
                                    'booking_id'    => $booking->id,
                                    'total_paid'    => $totalPaid,
                                    'new_price'     => $newPrice,
                                    'excess_amount' => $excessAmount,
                                ]);

                            }

                            $booking->history()->create([
                                'user_id'     => auth()->id(),
                                'action'      => 'price_adjusted',
                                'description' => "Price reduced by QAR {$refundAmount}. QAR {$excessPaid} refunded to wallet.",
                            ]);
                        }
                    }
                } else {
                    if ($booking->payment_status == BookingPaymentStatus::DEPOSIT_PAID) {
                        $paymentBreakdown = $booking->payment_breakdown ?? [];
                        $currentRemaining = $paymentBreakdown['remaining_amount'] ?? 0;
                        $newRemaining     = $currentRemaining + $priceDifference;

                        $paymentBreakdown['remaining_amount'] = max(0, $newRemaining);
                        $booking->payment_breakdown           = $paymentBreakdown;

                        $remainingPayment = $booking->payments()
                            ->where('type', PaymentType::REMAINING)
                            ->where('status', PaymentStatus::PENDING)
                            ->first();

                        if ($remainingPayment) {
                            $remainingPayment->update(['amount' => $newRemaining]);
                        } else {
                            if (! $booking->is_free) {
                                $booking->payments()->create([
                                    'date'    => null,
                                    'amount'  => $newRemaining,
                                    'user_id' => $booking->user_id,
                                    'method'  => PaymentMethod::CASH,
                                    'type'    => PaymentType::REMAINING,
                                    'status'  => PaymentStatus::PENDING,
                                    'notes'   => "Remaining payment for booking #{$booking->booking_id} (updated)",
                                    'payment_id' => generatePaymentId($booking->id, PaymentType::REMAINING),
                                ]);
                            }
                        }
                    } elseif ($booking->payment_status == BookingPaymentStatus::PENDING) {
                        $pendingPayment = $booking->payments()
                            ->where('status', PaymentStatus::PENDING)
                            ->first();

                        if ($pendingPayment) {
                            $pendingPayment->update(['amount' => $newPrice]);
                        } else {
                            if (! $booking->is_free) {
                                $booking->payments()->create([
                                    'date'    => null,
                                    'amount'  => $newPrice,
                                    'user_id' => $booking->user_id,
                                    'type'    => PaymentType::FULL,
                                    'method'  => PaymentMethod::CASH,
                                    'status'  => PaymentStatus::PENDING,
                                    'notes'   => "Payment for booking #{$booking->booking_id} (updated)",
                                    'payment_id' => generatePaymentId($booking->id, PaymentType::FULL),
                                ]);
                            }
                        }
                    } elseif ($booking->payment_status == BookingPaymentStatus::FULLY_PAID) {
                        if ($totalPaid < $newPrice) {
                            $additionalAmount = $newPrice - $totalPaid;

                            if (! $booking->is_free) {
                                $booking->payments()->create([
                                    'date'    => null,
                                    'user_id' => $booking->user_id,
                                    'amount'  => $additionalAmount,
                                    'type'    => PaymentType::FULL,
                                    'method'  => PaymentMethod::CASH,
                                    'status'  => PaymentStatus::PENDING,
                                    'notes'   => "Additional payment required for booking #{$booking->booking_id} (price increased)",
                                    'payment_id' => generatePaymentId($booking->id, PaymentType::FULL),
                                ]);

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

                if (isset($updateData['user_id']) && $updateData['user_id'] != $oldUserId) {
                    $booking->payments()->update(['user_id' => $updateData['user_id']]);

                    $booking->invoices()->update(['user_id' => $updateData['user_id']]);

                    $booking->history()->create([
                        'user_id'     => auth()->id(),
                        'action'      => 'user_changed',
                        'description' => "Booking transferred from user #{$oldUserId} to user #{$updateData['user_id']}",
                    ]);
                }

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
            }

            DB::commit();

            $booking->load([
                'user',
                'addOns',
                'history',
                'payments',
                'invoices',
                'promocode',
                'listing:id,name',
                'resourcesAllocated',
                'history.user:id,name',
                'venue:id,name,maps_url',
            ]);

            if (! empty($changes)) {
                try {
                    $bookingOwner = $booking->user;
                    if ($bookingOwner && $bookingOwner->email) {
                        $bookingOwner->notify(new BookingUpdatedUserNotification($booking, $changes));
                    }
                } catch (Exception $e) {
                    Log::error("Booking update notification failed: " . $e->getMessage(), [
                        'booking_id' => $booking->id,
                        'exception'  => $e,
                    ]);
                }
            }

            $this->sendBookingNotification($booking, 'updated');

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

    public function mobileUpdateBooking($request, $bookingId)
    {
        $user = auth()->user();

        if (! $user) {
            return sendError('User not authenticated', [], 401);
        }

        $booking = $this->bookingModel::with(['user', 'venue', 'listing', 'addOns', 'promocode'])->findOrFail($bookingId);

        if (! in_array($booking->status, [BookingStatus::PENDING, BookingStatus::CONFIRMED])) {
            return sendError('Cannot update ' . $booking->status . ' bookings. Only pending or confirmed bookings can be updated.');
        }

        if ($request->has('start_time')) {
            $time  = $request->input('start_time');
            $parts = explode(':', $time);
            if (count($parts) >= 2) {
                $hour   = str_pad($parts[0], 2, '0', STR_PAD_LEFT);
                $minute = str_pad($parts[1], 2, '0', STR_PAD_LEFT);
                $request->merge(['start_time' => $hour . ':' . $minute]);
            }
        }

        if ($request->has('end_time')) {
            $time  = $request->input('end_time');
            $parts = explode(':', $time);
            if (count($parts) >= 2) {
                $hour   = str_pad($parts[0], 2, '0', STR_PAD_LEFT);
                $minute = str_pad($parts[1], 2, '0', STR_PAD_LEFT);
                $request->merge(['end_time' => $hour . ':' . $minute]);
            }
        }

        $formData = $request->validate([
            'venue_id'       => 'sometimes|exists:venues,id',
            'start_time'     => 'sometimes|date_format:H:i',
            'end_time'       => 'sometimes|date_format:H:i',
            'listing_id'     => 'sometimes|exists:listings,id',
            'date'           => 'sometimes|date|after_or_equal:today',
            'payment_method' => 'sometimes|in:payment_skipped,cash_on_arrival,online_payment,paid_cash,paid_cheque,paid_credit_card,paid_wire_transfer,deposit_remaining_on_arrival',
            'add_ons'        => 'nullable|array',
            'add_ons.*'      => 'exists:add_ons,id',
            'user_id'        => 'sometimes|exists:users,id',
        ]);

        try {
            DB::beginTransaction();

            $oldListingId     = $booking->listing_id;
            $oldVenueId       = $booking->venue_id;
            $oldDate          = $booking->date;
            $oldStartTime     = $booking->start_time;
            $oldEndTime       = $booking->end_time;
            $oldPrice         = $booking->total_price;
            $oldPaymentMethod = $booking->payment_method;
            $oldUserId        = $booking->user_id;
            $oldAddOns        = $booking->addOns->pluck('id')->toArray();

            $updateData = [];

            $userChanged = false;
            if (isset($formData['user_id']) && $formData['user_id'] != $oldUserId) {
                $newUser               = User::findOrFail($formData['user_id']);
                $updateData['user_id'] = $newUser->id;
                $userChanged           = true;
            }

            if (isset($formData['venue_id'])) {
                $updateData['venue_id'] = $formData['venue_id'];
            }
            if (isset($formData['listing_id'])) {
                $updateData['listing_id'] = $formData['listing_id'];
            }

            if (isset($formData['date']) || isset($formData['start_time']) || isset($formData['end_time'])) {
                $validationData = [
                    'date'       => $formData['date'] ?? $booking->date,
                    'start_time' => $formData['start_time'] ?? $booking->start_time,
                    'end_time'   => $formData['end_time'] ?? $booking->end_time,
                    'venue_id'   => $formData['venue_id'] ?? $booking->venue_id,
                    'listing_id' => $formData['listing_id'] ?? $booking->listing_id,
                ];

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

            }

            $listingChanged = isset($formData['listing_id']) && $formData['listing_id'] != $oldListingId;
            $venueChanged   = isset($formData['venue_id']) && $formData['venue_id'] != $oldVenueId;
            $timeChanged    = isset($formData['date']) || isset($formData['start_time']) || isset($formData['end_time']);

            $listingId = $formData['listing_id'] ?? $booking->listing_id;
            $listing   = Listing::with('pricingGroups')->findOrFail($listingId);

            if ($listingChanged || $venueChanged || $timeChanged) {
                $venueId = $formData['venue_id'] ?? $booking->venue_id;

                if (! $venueChanged && $listing->venue_id != $venueId) {
                    $updateData['venue_id'] = $listing->venue_id;
                    $venueId                = $listing->venue_id;
                }

                $date      = $formData['date'] ?? $booking->date;
                $startTime = $formData['start_time'] ?? $booking->start_time;
                $endTime   = $formData['end_time'] ?? $booking->end_time;

                $startTimeCarbon = Carbon::parse($date . ' ' . $startTime);
                $endTimeCarbon   = Carbon::parse($date . ' ' . $endTime);
                if ($endTimeCarbon->lt($startTimeCarbon)) {
                    $endTimeCarbon->addDay();
                }
                $duration               = abs($endTimeCarbon->diffInHours($startTimeCarbon));
                $updateData['duration'] = $duration;

                $weekday = strtolower(Carbon::parse($date)->format('l'));

                $specialDay = SpecialDay::with('timeBlocks')
                    ->where('venue_id', $venueId)
                    ->where(function ($q) use ($listing) {
                        $q->whereNull('listing_id')->orWhere('listing_id', $listing->id);
                    })
                    ->whereDate('date', $date)->active()->first();

                if (! $listing->relationLoaded('pricingGroups')) {
                    $listing->load('pricingGroups');
                }

                $pricePerHour = determineSlotPrice(
                    $listing,
                    $weekday,
                    $startTime,
                    $endTime,
                    $specialDay
                );

                $basePrice = round($pricePerHour * $duration, 2);

                if (isset($formData['add_ons'])) {
                    $addOnCost = AddOn::whereIn('id', $formData['add_ons'])->sum('price');
                } else {
                    $addOnCost = $booking->addOns()->sum('price');
                }

                $newSubtotal = round($basePrice + $addOnCost, 2);

                $newDiscount = 0;
                if ($booking->promocode) {
                    $promocode = $booking->promocode;
                    $isValid   = $promocode->isValidForOrder(
                        $newSubtotal,
                        $user->id,
                        $booking->payment_method,
                        $venueId,
                        $listingId
                    );

                    if ($isValid) {
                        $newDiscount = $promocode->applyDiscount($newSubtotal);
                    } else {
                        $promocode->decrementUsage($user->id);
                        $updateData['promocode_id']    = null;
                        $updateData['discount_amount'] = 0;
                    }
                }

                $newTotalPrice = round($newSubtotal - $newDiscount, 2);

                $updateData['subtotal']        = $newSubtotal;
                $updateData['discount_amount'] = $newDiscount;
                $updateData['total_price']     = $newTotalPrice;

                if ($listingChanged) {
                    $resources = $listing->resourceRequirements()->select('resources.id', 'listing_resource.quantity')->get();
                    $booking->resourcesAllocated()->detach();
                    foreach ($resources as $resource) {
                        $booking->resourcesAllocated()->attach($resource->id, ['quantity' => $resource->pivot->quantity]);
                    }
                }
            }

            $addOnsChanged = false;
            if (isset($formData['add_ons'])) {
                $newAddOns = $formData['add_ons'];

                $currentListingId  = $formData['listing_id'] ?? $booking->listing_id;
                $listingForAddOns  = Listing::with('addOns')->findOrFail($currentListingId);
                $availableAddOnIds = $listingForAddOns->addOns->pluck('id')->toArray();

                $invalidAddOns = array_diff($newAddOns, $availableAddOnIds);
                if (! empty($invalidAddOns)) {
                    DB::rollBack();
                    return sendError('Some add-ons are not available for this listing', [
                        'invalid_add_ons'   => $invalidAddOns,
                        'available_add_ons' => $availableAddOnIds,
                    ], 422);
                }

                sort($newAddOns);
                sort($oldAddOns);
                if ($newAddOns != $oldAddOns) {
                    $addOnsChanged = true;

                    $booking->addOns()->sync($newAddOns);

                    if (! $listingChanged && ! $venueChanged && ! $timeChanged) {
                        $oldAddOnCost = AddOn::whereIn('id', $oldAddOns)->sum('price');
                        $basePrice    = $booking->subtotal - $oldAddOnCost;

                        $newAddOnCost = AddOn::whereIn('id', $newAddOns)->sum('price');
                        $newSubtotal  = round($basePrice + $newAddOnCost, 2);

                        $newDiscount = $booking->discount_amount;
                        if ($booking->promocode) {
                            $promocode = $booking->promocode;
                            $isValid   = $promocode->isValidForOrder(
                                $newSubtotal,
                                $user->id,
                                $booking->payment_method,
                                $booking->venue_id,
                                $booking->listing_id
                            );

                            if ($isValid) {
                                $newDiscount = $promocode->applyDiscount($newSubtotal);
                            } else {
                                $promocode->decrementUsage($user->id);
                                $updateData['promocode_id'] = null;
                                $newDiscount                = 0;
                            }
                        }

                        $newTotalPrice = round($newSubtotal - $newDiscount, 2);

                        $updateData['subtotal']        = $newSubtotal;
                        $updateData['discount_amount'] = $newDiscount;
                        $updateData['total_price']     = $newTotalPrice;
                    }
                }
            }

            $paymentMethodChanged = false;
            $newPaymentStatus     = null;
            $shouldAutoConfirm    = false;
            $newPaymentMethod     = null;

            if (isset($formData['payment_method']) && $formData['payment_method'] != $oldPaymentMethod) {
                $paymentMethodChanged = true;

                if ($booking->payment_status === BookingPaymentStatus::FULLY_PAID) {
                    DB::rollBack();
                    return sendError('Cannot change payment method for a fully paid booking');
                }

                $newPaymentMethod             = $formData['payment_method'];
                $updateData['payment_method'] = $newPaymentMethod;

                $newPaymentStatus = match (trim($newPaymentMethod)) {
                    'cash_on_arrival'              => BookingPaymentStatus::PENDING,
                    'online_payment'               => BookingPaymentStatus::FULLY_PAID,
                    'paid_cash'                    => BookingPaymentStatus::FULLY_PAID,
                    'paid_cheque'                  => BookingPaymentStatus::FULLY_PAID,
                    'paid_credit_card'             => BookingPaymentStatus::FULLY_PAID,
                    'paid_wire_transfer'           => BookingPaymentStatus::FULLY_PAID,
                    'deposit_remaining_on_arrival' => BookingPaymentStatus::DEPOSIT_PAID,
                    'payment_skipped'              => BookingPaymentStatus::PAYMENT_SKIPPED,
                    default                        => BookingPaymentStatus::PENDING,
                };

                $updateData['payment_status'] = $newPaymentStatus;

                if ($newPaymentStatus === BookingPaymentStatus::PAYMENT_SKIPPED) {
                    $updateData['payment_breakdown']     = null;
                    $updateData['full_payment_received'] = true;
                    $updateData['is_free']               = true;

                    $pendingPayments = $booking->payments()
                        ->where('status', PaymentStatus::PENDING)
                        ->get();

                    foreach ($pendingPayments as $payment) {
                        $payment->update([
                            'status' => 'cancelled',
                            'notes'  => ($payment->notes ?? '') . ' - Cancelled: Booking changed to payment skipped',
                        ]);
                    }
                } elseif ($newPaymentStatus === BookingPaymentStatus::DEPOSIT_PAID) {
                    $totalPrice    = $updateData['total_price'] ?? $booking->total_price;
                    $depositAmount = 0;

                    if ($listing && ! empty($listing->payment_options) && isset($listing->payment_options['deposit'])) {
                        $deposit = $listing->payment_options['deposit'];
                        if (! empty($deposit['amount']) && $deposit['amount'] > 0) {
                            $depositAmount = $deposit['amount'];
                        } elseif (! empty($deposit['percentage']) && $deposit['percentage'] > 0) {
                            $depositAmount = floor($totalPrice * ($deposit['percentage'] / 100));
                        }
                    }

                    $updateData['payment_breakdown'] = [
                        'deposit_amount'   => $depositAmount,
                        'deposit_method'   => PaymentMethod::ONLINE,
                        'remaining_amount' => $totalPrice - $depositAmount,
                        'remaining_method' => PaymentMethod::CASH,
                    ];
                } elseif ($newPaymentStatus === BookingPaymentStatus::FULLY_PAID) {
                    $updateData['payment_breakdown']     = null;
                    $updateData['full_payment_received'] = true;
                    $updateData['is_free']               = false;

                    $paymentMethod = match (trim($newPaymentMethod)) {
                        'paid_cash'          => PaymentMethod::CASH,
                        'paid_cheque'        => PaymentMethod::CHEQUE,
                        'online_payment'     => PaymentMethod::ONLINE,
                        'paid_credit_card'   => PaymentMethod::CREDIT_CARD,
                        'paid_wire_transfer' => PaymentMethod::WIRE_TRANSFER,
                        default              => PaymentMethod::CASH,
                    };

                    $totalPrice = $updateData['total_price'] ?? $booking->total_price;

                    $pendingPayments = $booking->payments()
                        ->where('status', PaymentStatus::PENDING)
                        ->get();

                    if ($pendingPayments->isNotEmpty()) {
                        foreach ($pendingPayments as $payment) {
                            $payment->update([
                                'method' => $paymentMethod,
                                'status' => PaymentStatus::PAID,
                                'date'   => now()->format('Y-m-d'),
                                'notes'  => str_replace('pending', 'paid via ' . str_replace('paid_', '', $newPaymentMethod), $payment->notes ?? "Payment for booking #{$booking->booking_id}"),
                            ]);
                        }
                    } else {
                        if (! $booking->is_free) {
                            $booking->payments()->create([
                                'user_id'    => $booking->user_id,
                                'booking_id' => $booking->id,
                                'amount'     => $totalPrice,
                                'type'       => PaymentType::FULL,
                                'method'     => $paymentMethod,
                                'status'     => PaymentStatus::PAID,
                                'date'       => now()->format('Y-m-d'),
                                'notes'      => "Full payment for booking #{$booking->booking_id} via " . str_replace('paid_', '', $newPaymentMethod),
                                'payment_id' => generatePaymentId($booking->id, PaymentType::FULL),
                            ]);
                        }
                    }

                    $shouldAutoConfirm = ($booking->status === BookingStatus::PENDING);
                } else {
                    $updateData['payment_breakdown']     = null;
                    $updateData['full_payment_received'] = false;
                    $updateData['is_free']               = false;
                }
            }

            if (! empty($updateData)) {
                $booking->update($updateData);
            }

            if ($userChanged) {
                $booking->payments()->update(['user_id' => $updateData['user_id']]);

                $booking->invoices()->update(['user_id' => $updateData['user_id']]);

                $booking->history()->create([
                    'user_id'     => $user->id,
                    'action'      => 'user_changed',
                    'description' => "Booking transferred from user #{$oldUserId} to user #{$updateData['user_id']}",
                ]);
            }

            if ($shouldAutoConfirm && $newPaymentStatus === BookingPaymentStatus::FULLY_PAID) {
                $booking->refresh();
                if ($booking->status === BookingStatus::PENDING) {
                    $booking->update(['status' => BookingStatus::CONFIRMED]);
                    $booking->history()->create([
                        'user_id'     => $user->id,
                        'action'      => 'booking_confirmed',
                        'description' => 'Booking automatically confirmed after payment method update to fully paid',
                    ]);
                }
            }

            $changes = [];
            if ($timeChanged) {
                $newDate      = $updateData['date'] ?? $booking->date;
                $newStartTime = $updateData['start_time'] ?? $booking->start_time;
                $newEndTime   = $updateData['end_time'] ?? $booking->end_time;
                $changes[]    = "Time slot changed from {$oldDate} {$oldStartTime}-{$oldEndTime} to {$newDate} {$newStartTime}-{$newEndTime}";
            }
            if ($venueChanged) {
                $changes[] = "Venue changed";
            }
            if ($listingChanged) {
                $changes[] = "Listing changed";
            }
            if ($paymentMethodChanged) {
                $newPaymentMethod = $formData['payment_method'];
                $changes[]        = "Payment method changed from {$oldPaymentMethod} to {$newPaymentMethod}";
            }
            if ($addOnsChanged) {
                $oldAddOnsNames = AddOn::whereIn('id', $oldAddOns)->pluck('name')->implode(', ');
                $newAddOnsNames = AddOn::whereIn('id', $formData['add_ons'] ?? [])->pluck('name')->implode(', ');
                $changes[]      = "Add-ons changed from [{$oldAddOnsNames}] to [{$newAddOnsNames}]";
            }
            if ($userChanged) {
                $oldUserName = User::find($oldUserId)->name ?? "User #{$oldUserId}";
                $newUserName = User::find($updateData['user_id'])->name ?? "User #{$updateData['user_id']}";
                $changes[]   = "Customer changed from {$oldUserName} to {$newUserName}";
            }
            if ($listingChanged || $venueChanged || $timeChanged || $addOnsChanged) {
                $newPrice = $updateData['total_price'] ?? $booking->total_price;
                if ($oldPrice != $newPrice) {
                    $changes[] = "Price updated from QAR {$oldPrice} to QAR {$newPrice}";
                }
            }

            if (! empty($changes)) {
                $booking->history()->create([
                    'user_id'     => $user->id,
                    'action'      => 'booking_updated',
                    'description' => 'Booking updated via mobile app: ' . implode(', ', $changes),
                ]);
            }

            DB::commit();

            $booking->load([
                'user',
                'addOns',
                'history',
                'payments',
                'promocode',
                'venue:id,name,maps_url',
                'listing:id,name',
                'resourcesAllocated',
            ]);

            if (! empty($changes)) {
                try {
                    $bookingOwner = $booking->user;
                    if ($bookingOwner && $bookingOwner->email) {
                        $bookingOwner->notify(new BookingUpdatedUserNotification($booking, $changes));
                    }
                } catch (Exception $e) {
                    Log::error("Booking update notification failed: " . $e->getMessage(), [
                        'booking_id' => $booking->id,
                        'exception'  => $e,
                    ]);
                }
            }

            return sendSuccess('Booking updated successfully', compact('booking'));

        } catch (Exception $e) {
            DB::rollBack();
            Log::error("Failed to update booking #{$bookingId} via mobile: " . $e->getMessage(), [
                'request'    => $request->all(),
                'booking_id' => $booking->id ?? $bookingId,
                'user_id'    => $user->id,
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

            if ($booking->loyalty_points_earned > 0) {
                $pointsAlreadyAdded = LoyaltyPoint::where('booking_id', $booking->id)
                    ->where('type', 'credit')
                    ->exists();

                if (! $pointsAlreadyAdded) {
                    $user = $booking->user;
                    $user->addLoyaltyPoints($booking->loyalty_points_earned, $booking->id, "Loyalty points earned for booking confirmation");
                    $booking->history()->create([
                        'user_id'     => auth()->id(),
                        'action'      => 'loyalty_points_earned',
                        'description' => "{$booking->loyalty_points_earned} loyalty points added to user #{$user->id} for booking auto-confirmation.",
                    ]);
                }
            }

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

    public function mobileBookingsFilterOptions($request)
    {
        $data = $request->validate([
            'listings'         => 'nullable|boolean',
            'venues'           => 'nullable|boolean',
            'users'            => 'nullable|boolean',
            'sportsCategories' => 'nullable|boolean',
            'records_per_page' => 'nullable',
        ]);

        $recordsPerPage = $request->input('records_per_page', 10);

        $isPaginated = true;
        if ($recordsPerPage === "all") {
            $isPaginated = false;
        } elseif (! ctype_digit((string) $recordsPerPage)) {
            $recordsPerPage = 10;
        }

        $formatResponse = function ($result, $isPaginated) {
            if ($isPaginated) {
                return [
                    'data'       => $result->items(),
                    'pagination' => [
                        'current_page'     => $result->currentPage(),
                        'last_page'        => $result->lastPage(),
                        'records_per_page' => $result->perPage(),
                        'total'            => $result->total(),
                        'from'             => $result->firstItem(),
                        'to'               => $result->lastItem(),
                    ],
                ];
            }

            return [
                'data'       => $result,
                'pagination' => null,
            ];
        };

        $listings = ['data' => [], 'pagination' => null];
        if (! empty($data['listings'])) {
            $query = Listing::select('id', 'name', 'venue_id')
                ->with(['venue:id,name'])
                ->active()
                ->orderBy('sort_order', 'ASC')
                ->orderBy('name');

            $query = applyVenueFilter($query, 'venue_id');

            $result = $isPaginated
                ? $query->paginate($recordsPerPage)->through(fn($listing) => [
                'id'    => $listing->id,
                'name'  => $listing->name,
                'venue' => $listing->venue?->name,
            ])
                : $query->get()->map(fn($listing) => [
                'id'    => $listing->id,
                'name'  => $listing->name,
                'venue' => $listing->venue?->name,
            ]);

            $listings = $formatResponse($result, $isPaginated);
        }

        $venues = ['data' => [], 'pagination' => null];
        if (! empty($data['venues'])) {
            $query = Venue::with('schedules')->select('id', 'name')
                ->active()
                ->orderBy('sort_order', 'ASC')
                ->orderBy('name');

            $query = applyVenueFilter($query, 'id');

            $result = $isPaginated
                ? $query->paginate($recordsPerPage)->through(fn($venue) => [
                'id'        => $venue->id,
                'name'      => $venue->name,
                'schedules' => $venue->schedules,
            ])
                : $query->get()->map(fn($venue) => [
                'id'        => $venue->id,
                'name'      => $venue->name,
                'schedules' => $venue->schedules,
            ]);

            $venues = $formatResponse($result, $isPaginated);
        }

        $sportsCategories = ['data' => [], 'pagination' => null];
        if (! empty($data['sportsCategories'])) {

            $query = SportsCategory::select('id', 'name', 'sports_type_id')
                ->with(['sportsType:id,title'])
                ->active()
                ->orderBy('name');

            $result = $isPaginated
                ? $query->paginate($recordsPerPage)->through(fn($category) => [
                'id'          => $category->id,
                'name'        => $category->name,
                'sports_type' => $category->sportsType?->title,
            ])
                : $query->get()->map(fn($category) => [
                'id'          => $category->id,
                'name'        => $category->name,
                'sports_type' => $category->sportsType?->title,
            ]);

            $sportsCategories = $formatResponse($result, $isPaginated);
        }

        $users = ['data' => [], 'pagination' => null];
        if (! empty($data['users'])) {

            $query = User::select('id', 'name', 'email', 'phone')
                ->whereHas('roles', fn($q) => $q->where('name', 'customer'))
                ->active()
                ->orderBy('name');

            $result = $isPaginated
                ? $query->paginate($recordsPerPage)->through(fn($user) => [
                'id'    => $user->id,
                'name'  => $user->name,
                'email' => $user->email,
                'phone' => $user->phone,
            ])
                : $query->get()->map(fn($user) => [
                'id'    => $user->id,
                'name'  => $user->name,
                'email' => $user->email,
                'phone' => $user->phone,
            ]);

            $users = $formatResponse($result, $isPaginated);
        }

        return sendSuccess('Filter options fetched successfully', [
            'listings'         => $listings,
            'venues'           => $venues,
            'users'            => $users,
            'sportsCategories' => $sportsCategories,
            'statuses'         => BookingStatus::list(),
            'pagination'       => [
                'enabled'  => $isPaginated,
                'per_page' => $recordsPerPage,
            ],
        ]);
    }

    public function mobileBookingsList($request)
    {
        $data = $request->validate([
            'type'             => 'nullable|in:upcoming,recent,all',
            'listing_ids'      => 'nullable|array',
            'listing_ids.*'    => 'exists:listings,id',
            'venue_ids'        => 'nullable|array',
            'venue_ids.*'      => 'exists:venues,id',
            'user_ids'         => 'nullable|array',
            'user_ids.*'       => 'exists:users,id',
            'statuses'         => 'nullable|array',
            'statuses.*'       => 'in:pending,confirmed,completed,cancelled',
            'search'           => 'nullable|string|max:255',
            'date_from'        => 'nullable|date',
            'date_to'          => 'nullable|date|after_or_equal:date_from',
            'page'             => 'nullable|integer|min:1',
            'records_per_page' => 'nullable|integer|min:1|max:100',
            'sort_by'          => 'nullable|in:date,created_at,start_time',
            'sort_order'       => 'nullable|in:asc,desc',
            'date'             => 'nullable|date',
        ]);

        $today          = Carbon::today();
        $sortBy         = $data['sort_by'] ?? 'date';
        $sortOrder      = $data['sort_order'] ?? 'desc';
        $recordsPerPage = $data['records_per_page'] ?? 10;

        $query = $this->bookingModel::with([
            'history',
            'payments',
            'promocode',
            'latestInvoice',
            'resourcesAllocated',
            'history.user:id,name',
            'addOns:id,name,price',
            'venue:id,name,maps_url',
            'user:id,name,email,phone,image',
            'listing:id,name,venue_id,calendar_color',
        ]);

        if ($request->filled('type')) {
            if ($data['type'] === 'upcoming') {
                $query->where('date', '>=', $today);
            } elseif ($data['type'] === 'recent') {
                $query->latest('id');
            }
        }

        if (! empty($data['listing_ids'])) {
            $query->whereIn('listing_id', $data['listing_ids']);
        }

        $query = applyVenueFilter($query, 'venue_id');

        if (! empty($data['venue_ids'])) {
            $user = auth()->user();
            if ($user && ! $user->hasAnyRole(['super_admin', 'admin'])) {
                $venueIds = DB::table('user_venue')
                    ->where('user_id', $user->id)
                    ->pluck('venue_id')
                    ->toArray();
                $intersected = array_intersect($data['venue_ids'], $venueIds);
                if (empty($intersected)) {
                    $query->whereRaw('1 = 0');
                } else {
                    $query->whereIn('venue_id', $intersected);
                }
            } else {
                $query->whereIn('venue_id', $data['venue_ids']);
            }
        }

        if (! empty($data['user_ids'])) {
            $query->whereIn('user_id', $data['user_ids']);
        }

        if (! empty($data['statuses'])) {
            $query->whereIn('status', $data['statuses']);
        }

        if ($request->filled('date_from')) {
            $query->whereDate('date', '>=', $data['date_from']);
        }

        if ($request->filled('date_to')) {
            $query->whereDate('date', '<=', $data['date_to']);
        }

        if ($request->filled('date')) {
            $query->where('date', $data['date']);
        }

        if ($request->filled('search')) {
            $search = $data['search'];

            $query->where(function ($q) use ($search) {
                $q->where('id', 'LIKE', "%{$search}%")
                    ->orWhere('notes', 'LIKE', "%{$search}%")
                    ->orWhereHas('listing', function ($l) use ($search) {
                        $l->where('name', 'LIKE', "%{$search}%");
                    })
                    ->orWhereHas('venue', function ($v) use ($search) {
                        $v->where('name', 'LIKE', "%{$search}%");
                    })
                    ->orWhereHas('user', function ($u) use ($search) {
                        $u->where('name', 'LIKE', "%{$search}%")
                            ->orWhere('email', 'LIKE', "%{$search}%")
                            ->orWhere('phone', 'LIKE', "%{$search}%");
                    });
            });
        }

        if ($sortBy === 'date') {
            $query->orderBy('date', $sortOrder)
                ->orderBy('start_time', $sortOrder);
        } elseif ($sortBy === 'start_time') {
            $query->orderBy('date', $sortOrder)
                ->orderBy('start_time', $sortOrder);
        } else {
            $query->orderBy('created_at', $sortOrder);
        }

        $bookings = $query->paginate($recordsPerPage);

        return sendSuccess('Bookings fetched successfully', [
            'bookings'   => $bookings->items(),
            'pagination' => [
                'current_page'     => $bookings->currentPage(),
                'last_page'        => $bookings->lastPage(),
                'records_per_page' => $bookings->perPage(),
                'total'            => $bookings->total(),
                'from'             => $bookings->firstItem(),
                'to'               => $bookings->lastItem(),
            ],
        ]);
    }

    public function createSingleBooking(
        $user,
        array $formData,
        string $date,
        string $startTime,
        string $endTime,
        float $subtotal,
        float $discount,
        float $giftCardAmount,
        $promocode,
        $paymentIntentId,
        $chargeId,
        $listing,
        $request
    ) {
        try {
            $startTimeCarbon = Carbon::parse($startTime);
            $endTimeCarbon   = Carbon::parse($endTime);

            $totalPrice = round($subtotal - $discount - $giftCardAmount, 2);

            if ($totalPrice < 0) {
                $totalPrice = 0;
            }

            $paymentStatus = match (trim($formData['payment_method'])) {
                'cash_on_arrival'              => BookingPaymentStatus::PENDING,
                'online_payment'               => BookingPaymentStatus::FULLY_PAID,
                'paid_cash'                    => BookingPaymentStatus::FULLY_PAID,
                'paid_cheque'                  => BookingPaymentStatus::FULLY_PAID,
                'paid_credit_card'             => BookingPaymentStatus::FULLY_PAID,
                'paid_wire'                    => BookingPaymentStatus::FULLY_PAID,
                'paid_wire_transfer'           => BookingPaymentStatus::FULLY_PAID,
                'deposit_remaining_on_arrival' => BookingPaymentStatus::DEPOSIT_PAID,
                'payment_skipped'              => BookingPaymentStatus::PAYMENT_SKIPPED,
                default                        => BookingPaymentStatus::PENDING,
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

            $isPaymentSkipped = $paymentStatus == BookingPaymentStatus::PAYMENT_SKIPPED;

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
                'gift_card_amount'      => $giftCardAmount,
                'full_payment_received' => $paymentStatus == BookingPaymentStatus::FULLY_PAID,
                'is_free'               => $isPaymentSkipped,
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

            if ($isPaymentSkipped) {
            } elseif ($paymentStatus == BookingPaymentStatus::DEPOSIT_PAID) {
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

                if ($depositAmount > $totalPrice) {
                    $depositAmount = $totalPrice;
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
                $paymentMethod = match (trim($formData['payment_method'])) {
                    'paid_cash'          => PaymentMethod::CASH,
                    'paid_cheque'        => PaymentMethod::CHEQUE,
                    'online_payment'     => PaymentMethod::ONLINE,
                    'paid_credit_card'   => PaymentMethod::CREDIT_CARD,
                    'paid_wire'          => PaymentMethod::WIRE_TRANSFER,
                    'paid_wire_transfer' => PaymentMethod::WIRE_TRANSFER,
                    'cash_on_arrival'    => PaymentMethod::CASH,
                    default              => PaymentMethod::CASH,
                };

                $booking->payments()->create([
                    'charge_id'         => $chargeId,
                    'user_id'           => $user->id,
                    'amount'            => $totalPrice,
                    'payment_intent_id' => $paymentIntentId,
                    'type'              => PaymentType::FULL,
                    'payment_id'        => generatePaymentId($booking->id, PaymentType::FULL),
                    'date'              => $paymentStatus == BookingPaymentStatus::FULLY_PAID ? now()->format('Y-m-d') : null,
                    'method'            => $paymentMethod,
                    'status'            => $paymentStatus == BookingPaymentStatus::FULLY_PAID ? PaymentStatus::PAID : PaymentStatus::PENDING,
                    'notes'             => $paymentStatus == BookingPaymentStatus::FULLY_PAID ? "Full payment for booking #{$booking->booking_id}" : "Payment pending for booking #{$booking->booking_id}",
                ]);
            }

            if ($giftCardAmount > 0) {
                $booking->user->transactions()->create([
                    'type'       => 'debit',
                    'booking_id' => $booking->id,
                    'amount'     => $giftCardAmount,
                    'reason'     => "Gift card balance used for booking #{$booking->booking_id}",
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
            } elseif ($paymentStatus == BookingPaymentStatus::FULLY_PAID && in_array($formData['payment_method'], ['paid_cash', 'paid_cheque', 'paid_credit_card', 'paid_wire', 'paid_wire_transfer'])) {
                $paymentMethodName = match (trim($formData['payment_method'])) {
                    'paid_cash'          => 'Cash',
                    'paid_cheque'        => 'Cheque',
                    'paid_credit_card'   => 'Credit Card',
                    'paid_wire'          => 'Wire Transfer',
                    'paid_wire_transfer' => 'Wire Transfer',
                    default              => 'Payment',
                };
                $booking->history()->create([
                    'user_id'     => $user->id,
                    'description' => "Full payment received via {$paymentMethodName}.",
                    'action' => 'payment_received',
                ]);
            }

            if (! $isPaymentSkipped) {
                createBookingInvoiceWithNotification($booking, $user);
            }

            if ($paymentStatus == BookingPaymentStatus::FULLY_PAID) {
                autoConfirmBookingIfPaid($booking);
            }

            $booking->load(['listing:id,name', 'venue:id,name', 'user:id,name', 'invoices']);
            unlockListingSlot($listing, $user, $booking);

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

    public function getBookingNotificationRecipients(int $venueId)
    {
        $adminUserIds = User::whereHas('roles', function ($query) {
            $query->whereIn('name', ['admin', 'super_admin']);
        })
            ->where('status', User::ACTIVE)
            ->pluck('id')
            ->toArray();

        $employeeUserIds = User::whereHas('roles', function ($query) {
            $query->whereIn('name', ['senior_employee', 'junior_employee']);
        })
            ->whereHas('venues', function ($query) use ($venueId) {
                $query->where('venues.id', $venueId);
            })
            ->where('status', User::ACTIVE)
            ->pluck('id')
            ->toArray();

        return array_unique(array_merge($adminUserIds, $employeeUserIds));
    }

    public function sendBookingNotification(Booking $booking, string $eventType)
    {
        try {
            if (! $booking->venue_id) {
                return;
            }

            $recipientIds = $this->getBookingNotificationRecipients($booking->venue_id);

            if (empty($recipientIds)) {
                Log::info('No recipients found for booking notification', [
                    'booking_id' => $booking->id,
                    'venue_id'   => $booking->venue_id,
                    'event_type' => $eventType,
                ]);
                return;
            }

            $venueName    = $booking->venue->name ?? 'Unknown Venue';
            $customerName = $booking->user->name ?? 'Unknown Customer';
            $bookingId    = $booking->booking_id ?? 'N/A';

            $title   = '';
            $message = '';

            switch ($eventType) {
                case 'created':
                    $title   = 'New Booking Created';
                    $message = "New booking #{$bookingId} by {$customerName} at {$venueName}";
                    break;
                case 'updated':
                    $title   = 'Booking Updated';
                    $message = "Booking #{$bookingId} by {$customerName} at {$venueName} has been updated";
                    break;
                case 'cancelled':
                    $title   = 'Booking Cancelled';
                    $message = "Booking #{$bookingId} by {$customerName} at {$venueName} has been cancelled";
                    break;
                default:
                    return;
            }

            $data = [
                'type'           => 'booking_' . $eventType,
                'booking_id'     => $booking->id,
                'booking_number' => $bookingId,
                'venue_id'       => $booking->venue_id,
            ];

            $this->oneSignalService->sendToUsers($recipientIds, $title, $message, $data);
        } catch (Exception $e) {
            Log::error('Failed to send booking notification', [
                'booking_id' => $booking->id ?? null,
                'event_type' => $eventType,
                'error'      => $e->getMessage(),
            ]);
        }
    }

    public function importBookingsFromExcel($filename = 'bookings.xlsx')
    {
        try {

            $filePath = public_path($filename);

            if (! file_exists($filePath)) {
                return sendError("Excel file not found: {$filename}. Please place the file in the public folder.");
            }

            $import = new \App\Imports\BookingsImport($this);

            \Maatwebsite\Excel\Facades\Excel::import($import, $filePath);

            $successCount = $import->getSuccessCount();
            $errorCount   = $import->getErrorCount();
            $errors       = $import->getErrors();

            $message = "Import completed. Successfully imported {$successCount} booking(s)";
            if ($errorCount > 0) {
                $message .= ", {$errorCount} error(s) occurred";
            }

            return sendSuccess($message, [
                'success_count' => $successCount,
                'error_count'   => $errorCount,
                'errors'        => $errors,
            ]);
        } catch (Exception $e) {
            Log::error('Booking import failed', [
                'filename' => $filename,
                'error'    => $e->getMessage(),
                'trace'    => $e->getTraceAsString(),
            ]);

            return sendError('Import failed: ' . $e->getMessage(), [], 500);
        }
    }

    public function getPaymentMethods()
    {
        try {

            $user = auth()->user();

            if (! $user) {
                return sendError('Unauthorized', [], 401);
            }

            $userRoles = $user->getRoleNames()->toArray();

            $fullAccessRoles = ['super_admin', 'admin', 'senior_employee'];
            $hasFullAccess   = ! empty(array_intersect($userRoles, $fullAccessRoles));

            if ($hasFullAccess) {
                $paymentMethods = BookingPaymentMethod::list();
                $paymentMethods = array_values(array_filter($paymentMethods, function ($method) {
                    return $method !== BookingPaymentMethod::DEPOSIT_REMAINING_ON_ARRIVAL;
                }));
            } else {
                $paymentMethods = [
                    BookingPaymentMethod::PAYMENT_SKIPPED,
                    BookingPaymentMethod::CASH_ON_ARRIVAL,
                ];
            }

            $formattedMethods = array_map(function ($method) {
                return [
                    'value' => $method,
                    'label' => ucwords(str_replace('_', ' ', $method)),
                ];
            }, $paymentMethods);

            return sendSuccess('Payment methods fetched successfully', [
                'payment_methods' => $formattedMethods,
            ]);

        } catch (Exception $e) {
            Log::error('Get Payment Methods Error: ' . $e->getMessage(), ['Exception' => $e]);
            return sendError('Something went wrong while fetching payment methods');
        }
    }
}
