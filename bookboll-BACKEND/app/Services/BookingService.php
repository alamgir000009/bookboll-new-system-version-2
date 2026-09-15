<?php

namespace App\Services;

use App\Support\AccessScope;
use App\Support\HourPeriods;
use App\Models\AddOn;
use App\Models\AuditLog;
use App\Models\Booking;
use App\Models\Listing;
use App\Models\LoyaltyPoint;
use App\Models\Payment;
use App\Models\Promocode;
use App\Models\Resource;
use App\Models\Schedule;
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
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Nnjeim\World\Models\City;
use Nnjeim\World\Models\Country;
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
        protected Listing $listingModel,
        protected SpecialDay $specialDayModel,
        protected OneSignalService $oneSignalService
    ) {}

    public function all($request)
    {
        $search           = $request->search ?? null;
        $user_id          = $request->user_id ?? null;
        $records_per_page = $request->records_per_page ?? 10;

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
                        ->orWhere('id', 'LIKE', "%{$search}%")
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
        $user      = $request->user ?? null;
        $venue     = $request->venue ?? null;
        $search    = $request->search ?? null;
        $status    = $request->status ?? null;
        $user_id   = $request->user_id ?? null;
        $to_date   = $request->to_date ?? null;
        $from_date = $request->from_date ?? null;
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
        $currentUser = auth()->user();

        if (! $currentUser->hasAnyRole(['super_admin', 'admin'])) {
            return sendError('Only administrators can permanently delete bookings.');
        }

        $booking = $this->bookingModel->with('payments')->findOrFail($id);

        try {
            DB::beginTransaction();

            if ($booking->status !== BookingStatus::CANCELLED) {
                $previousStatus        = $booking->status;
                $previousPaymentStatus = $booking->payment_status;

                expireOpenStripeCheckoutSessionsForBooking($booking);

                $booking->update([
                    'cancelled_at'  => now(),
                    'cancelled_by'  => auth()->id(),
                    'status'        => BookingStatus::CANCELLED,
                    'cancel_reason' => 'Permanently deleted by administrator',
                ]);

                processBookingCancellation(
                    $booking,
                    auth()->id(),
                    'Permanently deleted by administrator',
                    false,
                    $previousStatus,
                    $previousPaymentStatus
                );
            }

            $booking->delete();

            DB::commit();

            return sendSuccess('Booking deleted successfully');
        } catch (Exception $e) {
            DB::rollBack();
            Log::error('Booking deletion error: ' . $e->getMessage(), ['exception' => $e]);

            return sendError('Something went wrong: ' . $e->getMessage());
        }
    }

    public function cancel($request)
    {
        $formData = $request->validate([
            'booking_id'    => 'required|exists:bookings,id',
            'cancel_reason' => 'nullable|string|max:255',
        ]);

        $cancelReason = $formData['cancel_reason'] ?? null;

        $booking = $this->bookingModel->with(['user', 'payments', 'promocode'])->findOrFail($formData['booking_id']);

        if ($booking->status === BookingStatus::CANCELLED) {
            return sendError('Booking is already cancelled.');
        }

        $currentUser         = auth()->user();
        $isBookingOwner      = (int) $currentUser->id === (int) $booking->user_id;
        $isAdmin             = $currentUser->hasAnyRole(['super_admin', 'admin', 'country_admin', 'platform_staff']);
        $isStaff             = $currentUser->hasAnyRole(['senior_employee', 'junior_employee', 'guard']);
        $hasCancelPermission = $isAdmin || $isStaff;

        if (! $isBookingOwner && ! $hasCancelPermission) {
            return sendError('You are not allowed to cancel this booking. Only the booking creator or staff can cancel.');
        }

        if ($booking->status === BookingStatus::COMPLETED && ! $isAdmin) {
            return sendError('Completed bookings can only be cancelled by an administrator.');
        }

        if (in_array($booking->status, [BookingStatus::CONFIRMED, BookingStatus::COMPLETED], true) && ! ($isAdmin || $isStaff)) {
            return sendError('Confirmed or completed bookings cannot be cancelled by customers. Please contact support.');
        }

        if ($booking->status !== BookingStatus::PENDING && $isBookingOwner && ! $hasCancelPermission) {
            return sendError('You can only cancel pending bookings.');
        }

        try {
            DB::beginTransaction();

            $previousStatus        = $booking->status;
            $previousPaymentStatus = $booking->payment_status;
            $user                  = $booking->user;

            expireOpenStripeCheckoutSessionsForBooking($booking);

            $booking->update([
                'cancelled_at'  => now(),
                'cancelled_by'  => auth()->id(),
                'status'        => BookingStatus::CANCELLED,
                'cancel_reason' => $cancelReason,
            ]);

            processBookingCancellation(
                $booking,
                auth()->id(),
                $cancelReason,
                false,
                $previousStatus,
                $previousPaymentStatus
            );

            $this->sendBookingNotification($booking, 'cancelled');

            DB::commit();

            $booking->refresh()->load(['venue:id,name,maps_url', 'payments', 'history.user:id,name']);

            if ($user) {
                try {
                    $user->notify(new BookingCancelledUserNotification($booking));
                } catch (Exception $e) {
                    Log::error('Booking cancellation email error: ' . $e->getMessage(), ['exception' => $e]);
                }
            }

            return sendSuccess('Booking cancelled successfully.', compact('booking'));
        } catch (Exception $e) {
            DB::rollBack();
            Log::error('Booking cancellation error: ' . $e->getMessage(), ['exception' => $e]);

            return sendError('Something went wrong: ' . $e->getMessage());
        }
    }

    public function dashboardData()
    {
        $recentBookingsQuery = $this->bookingModel->whereNotIn('status', [BookingStatus::COMPLETED])->latest('id');
        $recentBookingsQuery = applyVenueFilter($recentBookingsQuery, 'venue_id');
        $recentBookings      = $recentBookingsQuery->take(3)->get();

        $total_redeemed = LoyaltyPoint::where('type', 'debit')->sum('points');
        $total_earned   = LoyaltyPoint::where('type', 'credit')->sum('points');

        $totalCollectedQuery = $this->paymentModel->where('status', PaymentStatus::PAID);
        $totalCollectedQuery = applyVenueFilterToPayments($totalCollectedQuery);
        $totalCollected      = $totalCollectedQuery->sum('amount');

        $totalPendingQuery = $this->paymentModel->whereIn('status', [PaymentStatus::PENDING, PaymentStatus::UNPAID]);
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

        return sendSuccess(
            'Bookings fetched successfully',
            compact('recentBookings', 'stats', 'venues', 'listings')
        );
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
            // AccessScope is the one authority: ownership (venues.owner_user_id),
            // explicit assignment (user_venue) and platform jurisdiction. Reading
            // the pivot alone missed venues a provider OWNS.
            $venueIds = AccessScope::allowedVenueIds($user) ?? [];
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
        $venues      = Venue::with(['schedules', 'listings' => fn($q) => $q->visibleToAdmins()])->active()->get();
        $listings    = Listing::with(['schedules', 'addOns' => fn($q) => $q->active()])->visibleToAdmins()->get();
        $customers   = User::whereHas('roles', fn($q) => $q->where('name', 'customer'))->active()->get(['id', 'name', 'email']);
        $resources   = Resource::active()->get();
        $addOns      = AddOn::active()->get();
        $specialDays = $this->specialDayModel::with(['timeBlocks'])->get();

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
            ->whereRaw(bookingEffectiveEndDatetimeSql() . ' < ?', [$now])
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

                // validateBooking() above ran OUTSIDE this transaction, so the slot
                // could have been taken since. Re-check under the listing lock —
                // this is the decision that counts.
                $conflict = $this->capacityGuard(
                    $bookingData['listing_id'], $bookingData['date'],
                    $bookingData['start_time'], $bookingData['end_time'],
                    (int) ($bookingData['quantity'] ?? 1)
                );
                if ($conflict) {
                    DB::rollBack();

                    return sendError($conflict, ['capacity' => $conflict], 422);
                }

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
                    // Confirmed only when nothing is left to collect — a
                    // pre-paid or payment-skipped booking. Cash on arrival
                    // stays pending until the money is taken.
                    'status'                => initialBookingStatus($paymentStatus, (float) $totalPrice),
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
                    'user_id'     => auth()->id(),
                    'action'      => 'booking_created',
                    'description' => 'Booking created by ' . auth()->user()->name,
                ]);

                $isFreeBooking = $paymentMethod === BookingPaymentMethod::PAYMENT_SKIPPED || $booking->is_free;

                if (! $isFreeBooking) {
                    $this->paymentModel::create([
                        // The currency this money is in — without it the row reports as
                        // "Unknown currency" on the Payments page.
                        'currency'   => $this->bookingCurrency($booking),
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
                            'user_id'     => auth()->id(),
                            'action'      => $request->payment_method,
                            'description' => 'Payment ' . str_replace('_', ' ', $request->payment_method),
                        ]);
                    }
                }

                if (! $booking->is_free) {
                    createBookingInvoiceWithNotification($booking, $user);
                } else {
                    $booking->history()->create([
                        'user_id'     => auth()->id(),
                        'action'      => 'booking_created',
                        'description' => 'This booking is created with payment skipped by ' . auth()->user()->name . ' it marked as payment skipped',
                    ]);
                }

                unlockListingSlot($listing, $user, $booking);

                $bookings[] = $booking->load(['user', 'venue', 'listing', 'payments', 'addOns']);
            }

            DB::commit();

            foreach ($bookings as $createdBooking) {
                notifyVenueStaffRefresh($createdBooking->venue_id, [
                    'event'      => 'booking_created',
                    'booking_id' => $createdBooking->id,
                    'venue_id'   => $createdBooking->venue_id,
                    'listing_id' => $createdBooking->listing_id,
                    'bell'       => true,
                ]);
            }

            return sendSuccess('Bookings created successfully', compact('bookings'));
        } catch (Exception $e) {
            DB::rollBack();
            Log::error('Booking creation failed: ' . $e->getMessage(), ["Exception" => $e]);
            return sendError('Failed to create bookings', ['error' => $e->getMessage()], 500);
        }
    }

    private function adjustPaymentsForPriceChange(Booking $booking, float $oldPrice, float $newPrice): void
    {
        if ($newPrice == $oldPrice || $booking->is_free) {
            return;
        }

        $totalPaid       = (float) $booking->payments()->where('status', PaymentStatus::PAID)->sum('amount');
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
                        Log::info('Excess payment detected for booking', [
                            'booking_id'    => $booking->id,
                            'total_paid'    => $totalPaid,
                            'new_price'     => $newPrice,
                            'excess_amount' => $excessPaid,
                        ]);
                    }

                    $booking->history()->create([
                        'user_id'     => auth()->id(),
                        'action'      => 'price_adjusted',
                        'description' => "Price reduced by QAR {$refundAmount}. QAR {$excessPaid} refunded to wallet.",
                    ]);
                }
            }

            return;
        }

        $depositPaid = (float) $booking->payments()
            ->where('type', PaymentType::DEPOSIT)
            ->where('status', PaymentStatus::PAID)
            ->sum('amount');

        $hasDepositPaid = $depositPaid > 0;
        $amountDue      = max(0, round($newPrice - $totalPaid, 2));

        if ($hasDepositPaid || $booking->payment_status == BookingPaymentStatus::DEPOSIT_PAID) {
            $paymentBreakdown                     = $booking->payment_breakdown ?? [];
            $paymentBreakdown['remaining_amount'] = max(0, round($newPrice - $depositPaid, 2));
            $booking->payment_breakdown           = $paymentBreakdown;

            $pendingRemaining = $booking->payments()
                ->where('type', PaymentType::REMAINING)
                ->where('status', PaymentStatus::PENDING)
                ->first();

            if ($pendingRemaining) {
                $pendingRemaining->update(['amount' => $paymentBreakdown['remaining_amount']]);
            } elseif ($amountDue > 0) {
                $booking->payments()->create([
                    // The currency this money is in — without it the row reports as
                    // "Unknown currency" on the Payments page.
                    'currency'   => $this->bookingCurrency($booking),
                    'date'       => null,
                    'amount'     => $amountDue,
                    'user_id'    => $booking->user_id,
                    'method'     => PaymentMethod::CASH,
                    'type'       => PaymentType::REMAINING,
                    'status'     => PaymentStatus::PENDING,
                    'notes'      => "Remaining payment for booking #{$booking->booking_id} (price updated)",
                    'payment_id' => generatePaymentId($booking->id, PaymentType::REMAINING),
                ]);
            }

            if ($amountDue > 0 && $booking->payment_status === BookingPaymentStatus::FULLY_PAID) {
                $booking->payment_status        = BookingPaymentStatus::DEPOSIT_PAID;
                $booking->full_payment_received = false;
            }
        } elseif ($booking->payment_status == BookingPaymentStatus::PENDING) {
            $pendingPayment = $booking->payments()
                ->where('status', PaymentStatus::PENDING)
                ->first();

            if ($pendingPayment) {
                $pendingPayment->update(['amount' => $newPrice]);
            } else {
                $booking->payments()->create([
                    // The currency this money is in — without it the row reports as
                    // "Unknown currency" on the Payments page.
                    'currency'   => $this->bookingCurrency($booking),
                    'date'       => null,
                    'amount'     => $newPrice,
                    'user_id'    => $booking->user_id,
                    'type'       => PaymentType::FULL,
                    'method'     => PaymentMethod::CASH,
                    'status'     => PaymentStatus::PENDING,
                    'notes'      => "Payment for booking #{$booking->booking_id} (updated)",
                    'payment_id' => generatePaymentId($booking->id, PaymentType::FULL),
                ]);
            }
        } elseif ($booking->payment_status == BookingPaymentStatus::FULLY_PAID || $totalPaid > 0) {
            if ($amountDue > 0) {
                $booking->payments()->create([
                    // The currency this money is in — without it the row reports as
                    // "Unknown currency" on the Payments page.
                    'currency'   => $this->bookingCurrency($booking),
                    'date'       => null,
                    'user_id'    => $booking->user_id,
                    'amount'     => $amountDue,
                    'type'       => PaymentType::FULL,
                    'method'     => PaymentMethod::CASH,
                    'status'     => PaymentStatus::PENDING,
                    'notes'      => "Additional payment required for booking #{$booking->booking_id} (price increased)",
                    'payment_id' => generatePaymentId($booking->id, PaymentType::FULL),
                ]);

                if ($totalPaid > 0) {
                    $booking->payment_status        = BookingPaymentStatus::DEPOSIT_PAID;
                    $paymentBreakdown               = $booking->payment_breakdown ?? [];
                    $paymentBreakdown['remaining_amount'] = $amountDue;
                    $booking->payment_breakdown     = $paymentBreakdown;
                    $booking->full_payment_received = false;
                }
            }
        }

        $booking->history()->create([
            'user_id'     => auth()->id(),
            'action'      => 'price_adjusted',
            'description' => "Price increased by QAR {$priceDifference}. Payment records updated.",
        ]);

        $booking->save();
    }

    /**
     * Coerce a schedule/time-block time field to an 'H:i' string. Handles
     * strings ('09:00', '09:00:00'), Carbon/DateTime instances (from the
     * `datetime:H:i` cast), and null.
     */
    private function timeStringFromField($value): ?string
    {
        if ($value === null) {
            return null;
        }
        if ($value instanceof \DateTimeInterface) {
            return $value->format('H:i');
        }
        if (is_string($value)) {
            return substr($value, 0, 5);
        }
        return null;
    }

    /**
     * Does a booking window [$bookingStart, $bookingEnd] on $bookingDate fit
     * within ANY of the given schedule rows? Handles:
     *   - Cross-midnight bookings (end <= start).
     *   - Cross-midnight schedule blocks (end <= start).
     *   - Schedule blocks that end exactly at midnight ('00:00'). Per the read
     *     side's slot generator, these allow the last slot to spill past
     *     midnight, so we allow bookings whose start is within the block even
     *     if their end runs past midnight.
     *
     * $schedules can be any iterable of objects/arrays exposing start_time and
     * end_time (Schedule rows, SpecialDay time blocks, etc.).
     */
    private function bookingFitsInSchedules(iterable $schedules, string $bookingDate, string $bookingStart, string $bookingEnd): bool
    {
        try {
            $bStartAbs = Carbon::createFromFormat('Y-m-d H:i', $bookingDate . ' ' . $bookingStart);
            $bEndAbs   = Carbon::createFromFormat('Y-m-d H:i', $bookingDate . ' ' . $bookingEnd);
        } catch (\Throwable $e) {
            return false;
        }
        if ($bEndAbs->lte($bStartAbs)) {
            $bEndAbs->addDay();
        }

        // Turn every schedule row into an absolute [start, end) interval on
        // $bookingDate so we can then merge adjacent rows (venues often store
        // schedules as many contiguous small blocks).
        $blocks = [];
        foreach ($schedules as $schedule) {
            $rawStart = is_array($schedule) ? ($schedule['start_time'] ?? null) : ($schedule->start_time ?? null);
            $rawEnd   = is_array($schedule) ? ($schedule['end_time'] ?? null) : ($schedule->end_time ?? null);

            $sStart = $this->timeStringFromField($rawStart);
            $sEnd   = $this->timeStringFromField($rawEnd);
            if ($sStart === null || $sEnd === null) {
                continue;
            }

            try {
                $sStartAbs = Carbon::createFromFormat('Y-m-d H:i', $bookingDate . ' ' . $sStart);
                $sEndAbs   = Carbon::createFromFormat('Y-m-d H:i', $bookingDate . ' ' . $sEnd);
            } catch (\Throwable $e) {
                continue;
            }

            $endsAtMidnight = ($sEnd === '00:00');
            if ($sEndAbs->lte($sStartAbs)) {
                $sEndAbs->addDay();
            }

            $blocks[] = [
                'start'            => $sStartAbs,
                'end'              => $sEndAbs,
                'ends_at_midnight' => $endsAtMidnight,
            ];
        }

        if (empty($blocks)) {
            return false;
        }

        usort($blocks, fn($a, $b) => $a['start']->getTimestamp() <=> $b['start']->getTimestamp());

        $merged = [$blocks[0]];
        for ($i = 1; $i < count($blocks); $i++) {
            $last = &$merged[count($merged) - 1];
            $next = $blocks[$i];
            if ($next['start']->lte($last['end'])) {
                if ($next['end']->gt($last['end'])) {
                    $last['end']              = $next['end'];
                    $last['ends_at_midnight'] = $next['ends_at_midnight'];
                }
            } else {
                $merged[] = $next;
            }
            unset($last);
        }

        foreach ($merged as $block) {
            if ($this->windowFitsBlock($bStartAbs, $bEndAbs, $block['start'], $block['end'], $block['ends_at_midnight'])) {
                return true;
            }
        }

        // Cross-midnight schedules also produce post-midnight slots whose
        // start_time is numerically < the schedule's start_time. Try shifting
        // the booking by one day to resolve those wall-clock tails.
        $bStartShifted = $bStartAbs->copy()->addDay();
        $bEndShifted   = $bEndAbs->copy()->addDay();
        foreach ($merged as $block) {
            if ($block['start']->format('Y-m-d') === $block['end']->format('Y-m-d')) {
                continue;
            }
            if ($this->windowFitsBlock($bStartShifted, $bEndShifted, $block['start'], $block['end'], $block['ends_at_midnight'])) {
                return true;
            }
        }

        return false;
    }

    private function windowFitsBlock(Carbon $bStart, Carbon $bEnd, Carbon $sStart, Carbon $sEnd, bool $endsAtMidnight): bool
    {
        if ($bStart->lt($sStart) || $bStart->gte($sEnd)) {
            return false;
        }
        return $endsAtMidnight || $bEnd->lte($sEnd);
    }

    private function validateBooking(array $data, $editingId = null)
    {
        $errors = [];

        if (empty($data['venue_id'])) {
            $errors['venue_id'] = 'Venue required';
        }

        $venue     = Venue::find($data['venue_id']);
        $listing   = Listing::with('resourceRequirements')->find($data['listing_id']);
        $dayOfWeek = strtolower(Carbon::parse($data['date'])->format('l'));

        if (! $listing) {
            $errors['listing_id'] = 'Listing not found';
            return $errors;
        }
        if ($editingId === null && ! $listing->isVisibleToRequestSurface()) {
            $errors['listing_id'] = 'This listing is not available for booking';
            return $errors;
        }
        if (! $venue) {
            $errors['venue_id'] = 'Venue not found';
            return $errors;
        }

        $bookingDate  = $data['date'];
        $bookingStart = $data['start_time'];
        $bookingEnd   = $data['end_time'];

        $specialDay = $this->specialDayModel::where('date', $bookingDate)
            ->where('venue_id', $data['venue_id'])
            ->whereNull('listing_id')
            ->with('timeBlocks')
            ->first();

        if ($specialDay && $specialDay->type == 'holiday') {
            $errors['date'] = 'Holiday - closed';
        } elseif ($specialDay && $specialDay->timeBlocks && $specialDay->timeBlocks->isNotEmpty()) {
            // When a special day defines time blocks, they REPLACE the regular
            // venue + listing schedules for that date (matches the read side).
            if (! $this->bookingFitsInSchedules($specialDay->timeBlocks, $bookingDate, $bookingStart, $bookingEnd)) {
                $errors['start_time'] = 'Outside special day blocks';
            }
        } else {
            $venueSchedulesForDay = $venue->schedules()->where('day', $dayOfWeek)->where('is_open', true)->get();
            if ($venueSchedulesForDay->isEmpty()) {
                $errors['date'] = "Venue closed on {$dayOfWeek}s";
            } elseif (! $this->bookingFitsInSchedules($venueSchedulesForDay, $bookingDate, $bookingStart, $bookingEnd)) {
                $errors['start_time'] = 'Outside venue hours';
            }

            $listingSchedulesForDay = $listing->schedules()->where('day', $dayOfWeek)->where('is_open', true)->get();
            if ($listingSchedulesForDay->isEmpty()) {
                $errors['date'] = "Listing closed on {$dayOfWeek}s";
            } elseif (
                ! isset($errors['start_time'])
                && ! $this->bookingFitsInSchedules($listingSchedulesForDay, $bookingDate, $bookingStart, $bookingEnd)
            ) {
                $errors['start_time'] = 'Outside listing hours';
            }
        }

        return $errors + $this->physicalCapacityErrors($listing, $data, $editingId);
    }

    /**
     * Take the booking mutex for a listing.
     *
     * Capacity is decided by COUNTING existing bookings and then inserting. Without
     * a lock two requests can both count the last free spot and both take it — the
     * admin dashboard, the customer site and the mobile apps all race each other.
     *
     * The lock is the listing ROW, not a range of bookings: `bookings` has no
     * composite (listing_id, date) index, so a ranged `FOR UPDATE` would gap-lock
     * far more than the slot — every booking on that date, or every booking for
     * that listing — which is both slow and a deadlock source. A single primary-key
     * row lock serialises exactly what must be serialised: two people booking the
     * SAME court wait for each other, two people booking DIFFERENT courts never do.
     *
     * Each booking transaction takes exactly one such lock, so there is no ordering
     * between locks and therefore no deadlock cycle to create.
     *
     * MUST be called inside the transaction that performs the insert — the lock is
     * held until commit, which is what closes the window.
     */
    public function lockListingForBooking($listingId): void
    {
        if (! $listingId) {
            return;
        }

        Listing::whereKey($listingId)->lockForUpdate()->first();
    }

    /**
     * The authoritative capacity decision, taken under the listing lock.
     *
     * Callers still validate up-front for fast, detailed feedback; this is the
     * re-check immediately before the insert, when nothing else can slip in.
     *
     * @return string|null  the refusal message, or null when the booking fits
     */
    protected function capacityGuard(
        $listingId,
        $date,
        $startTime,
        $endTime,
        int $quantity = 1,
        $editingId = null
    ): ?string {
        $this->lockListingForBooking($listingId);

        $listing = Listing::with('resourceRequirements')->find($listingId);

        $errors = $this->physicalCapacityErrors($listing, [
            'listing_id' => $listingId,
            'date'       => $date,
            'start_time' => $this->fmtTime($startTime),
            'end_time'   => $this->fmtTime($endTime),
            'quantity'   => max(1, $quantity),
        ], $editingId);

        if (! $errors) {
            return null;
        }

        return $errors['capacity'] ?? reset($errors);
    }

    /**
     * The constraints that are physically impossible to break: the slot's own
     * capacity, and any shared resource the listing draws on.
     *
     * Split out so EVERY create path can enforce it. The admin dashboard used to
     * insert without any of this — two bookings on a one-court slot, no warning —
     * while the customer and staff paths refused correctly. Schedule and holiday
     * rules stay in validateBooking(): those are policy, and staff legitimately
     * override them.
     *
     * @return array<string, string> empty when the booking fits
     */
    protected function physicalCapacityErrors(?Listing $listing, array $data, $editingId = null): array
    {
        if (! $listing) {
            return [];
        }

        $errors = [];

        $resourceConflicts = [];
        foreach ($listing->resourceRequirements ?? [] as $resource) {
            $currentUsage = DB::table('booking_resource')
                ->join('bookings', 'booking_resource.booking_id', '=', 'bookings.id')
                ->where('bookings.status', '!=', BookingStatus::CANCELLED)
                ->where('bookings.id', '!=', $editingId)
                ->where('booking_resource.resource_id', $resource->id)
                ->where(function ($q) use ($data) {
                    applyBookingOverlapWhere(
                        $q,
                        $data['date'],
                        $data['start_time'],
                        $data['end_time'],
                        'start_time',
                        'end_time',
                        'date',
                        'bookings'
                    );
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

        $overlaps = $this->bookingModel::where('listing_id', $data['listing_id'])
            ->where('id', '!=', $editingId)
            ->where('status', '!=', BookingStatus::CANCELLED)
            ->where(function ($q) use ($data) {
                applyBookingOverlapWhere($q, $data['date'], $data['start_time'], $data['end_time']);
            })
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
            $query->when(
                $request->date,
                fn($q) =>
                $q->whereDate('date', $request->date)
            );
        }

        $query = applyVenueFilter($query, 'venue_id');

        if ($request->venue_id) {
            $user = auth()->user();
            if ($user && ! $user->hasAnyRole(['super_admin', 'admin'])) {
                // AccessScope: ownership + assignment + jurisdiction, one authority.
                $venueIds = AccessScope::allowedVenueIds($user) ?? [];
                if (! in_array($request->venue_id, $venueIds)) {
                    $query->whereRaw('1 = 0');
                } else {
                    $query->where('venue_id', $request->venue_id);
                }
            } else {
                $query->when(
                    $request->venue_id,
                    fn($q) =>
                    $q->where('venue_id', $request->venue_id)
                );
            }
        }

        $query->when(
            $request->listing_id,
            fn($q) =>
            $q->where('listing_id', $request->listing_id)
        );

        $query->when(
            $request->user_id,
            fn($q) =>
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

    /**
     * New admin dashboard calendar payload (apps/admin Calendar.vue).
     * Returns locations, listings, bookings for [from,to], opening hours, and
     * special days — same shape the page previously loaded from Supabase.
     */
    public function calendarDashboard(Request $request)
    {
        $from = $request->input('from') ?: ($request->input('date') ?: now()->format('Y-m-d'));
        $to   = $request->input('to') ?: $from;

        try {
            $from = Carbon::parse($from)->format('Y-m-d');
            $to   = Carbon::parse($to)->format('Y-m-d');
        } catch (\Throwable $e) {
            $from = now()->format('Y-m-d');
            $to   = $from;
        }

        if ($to < $from) {
            [$from, $to] = [$to, $from];
        }

        $user = auth()->user();
        $venueQuery = Venue::where('status', Venue::ACTIVE)->orderBy('sort_order')->orderBy('name');
        AccessScope::applyVenue($venueQuery, 'id', $user);
        if ($request->filled('venue_id') || $request->filled('location_id')) {
            $venueQuery->where('id', $request->input('venue_id') ?: $request->input('location_id'));
        }

        $venueCols = ['id', 'name', 'status', 'sort_order'];
        foreach (['city_id', 'country_id', 'owner_user_id', 'created_by', 'currency_override'] as $col) {
            if (Schema::hasColumn('venues', $col)) {
                $venueCols[] = $col;
            }
        }
        $venues = $venueQuery->with('users')->get($venueCols);
        $venueIds = $venues->pluck('id')->all();

        $locations = $venues->map(function (Venue $v) {
            $ownerId = null;
            if (Schema::hasColumn('venues', 'owner_user_id') && $v->owner_user_id) {
                $ownerId = $v->owner_user_id;
            } else {
                $ownerId = $v->relationLoaded('users')
                    ? ($v->users->first()?->id ?? $v->created_by)
                    : $v->created_by;
            }

            return [
                'id'                => (string) $v->id,
                'name'              => $v->name,
                'status'            => $v->status,
                'city_id'           => $v->city_id ? (string) $v->city_id : null,
                'country_id'        => $v->country_id ? (string) $v->country_id : null,
                'owner_user_id'     => $ownerId ? (string) $ownerId : null,
                'currency_override' => $v->currency_override ?: null,
                'sort_order'        => (int) ($v->sort_order ?? 0),
            ];
        })->values();

        $listings = $venueIds
            ? Listing::with('sportsCategory:id,name')
            ->whereIn('venue_id', $venueIds)
            ->where('status', 'active')
            ->orderBy('sort_order')
            ->orderBy('name')
            ->get()
            ->map(fn(Listing $l) => [
                'id'             => (string) $l->id,
                'location_id'    => $l->venue_id ? (string) $l->venue_id : null,
                'name'           => $l->name,
                'sport_category' => $l->sportsCategory?->name,
                'booking_color'  => $l->calendar_color ?: '#64748b',
                'status'         => $l->status,
            ])->values()
            : collect();

        $bookingQuery = $this->bookingModel::query()
            ->with(['listing:id,name,venue_id,calendar_color', 'venue:id,name', 'user:id,name'])
            ->whereBetween('date', [$from, $to]);

        if ($venueIds) {
            $bookingQuery->whereIn('venue_id', $venueIds);
        } else {
            $bookingQuery->whereRaw('1 = 0');
        }

        if ($request->filled('listing_id')) {
            $bookingQuery->where('listing_id', $request->listing_id);
        }

        // Exclude cancelled; exclude materialised class_program blocks (derived client-side).
        $bookingQuery->where('status', '!=', BookingStatus::CANCELLED);
        if (Schema::hasColumn('bookings', 'origin')) {
            $bookingQuery->where(function ($q) {
                $q->whereNull('origin')->orWhere('origin', '!=', 'class_program');
            });
        }

        $bookings = $bookingQuery->orderBy('date')->orderBy('start_time')
            ->get()
            ->map(fn(Booking $b) => $this->mapDashboardBooking($b))
            ->values();

        $locationHours = $this->calendarLocationHours($venueIds);

        $specialDays = [];
        if ($venueIds) {
            $sdReq = Request::create('/admin/bookings/special-days', 'GET', [
                'location_ids' => implode(',', $venueIds),
                'from'         => $from,
                'to'           => $to,
            ]);
            $sdReq->setUserResolver(fn() => $user);
            $sdRes = $this->dashboardSpecialDays($sdReq);
            $sdData = method_exists($sdRes, 'getData') ? $sdRes->getData(true) : [];
            $specialDays = $sdData['data']['special_days'] ?? [];
        }

        // Program modules are not in Laravel yet — empty until that migration.
        $programExceptions = [];
        if (Schema::hasColumn('bookings', 'origin') && Schema::hasColumn('bookings', 'program_id') && $venueIds) {
            $programExceptions = $this->bookingModel::query()
                ->where('origin', 'class_program')
                ->where('status', BookingStatus::CANCELLED)
                ->whereBetween('date', [$from, $to])
                ->whereIn('venue_id', $venueIds)
                ->get(['program_id', 'date'])
                ->map(fn(Booking $b) => [
                    'program_id'   => $b->program_id ? (string) $b->program_id : null,
                    'booking_date' => $b->date instanceof \Carbon\CarbonInterface
                        ? $b->date->format('Y-m-d')
                        : (string) $b->date,
                ])
                ->filter(fn($r) => $r['program_id'])
                ->values()
                ->all();
        }

        return sendSuccess('Calendar dashboard fetched', [
            'locations'          => $locations,
            'listings'           => $listings,
            'bookings'           => $bookings,
            'location_hours'     => $locationHours,
            'special_days'       => $specialDays,
            'programs'           => [],
            'subscriptions'      => [],
            'program_exceptions' => $programExceptions,
            'from'               => $from,
            'to'                 => $to,
        ]);
    }

    /**
     * Opening hours for calendar closed-slot shading.
     *
     * Read from `schedules` (rows with no listing_id) — the one place venue
     * hours live, and the same rows the booking engine intersects against.
     */
    protected function calendarLocationHours(array $venueIds): array
    {
        if (! $venueIds) {
            return [];
        }

        $rows = Schedule::query()
            ->whereIn('venue_id', $venueIds)
            ->forVenueHours()
            ->orderBy('venue_id')
            ->orderBy('day')
            ->get()
            ->map(fn(Schedule $row) => $row->toDashboardLocationHour())
            ->filter(fn($r) => $r['day_of_week'] !== null)
            ->values()
            ->all();

        return $this->mergeHourRowsPerVenue($rows);
    }

    /**
     * Hours are stored one row per period, and imported data often holds a row
     * per half hour — the calendar would shade opening time in slivers. Merge
     * per venue for display; nothing is rewritten.
     */
    protected function mergeHourRowsPerVenue(array $rows): array
    {
        return collect($rows)
            ->groupBy('location_id')
            ->flatMap(fn($perVenue) => HourPeriods::mergeDashboardRows($perVenue->all()))
            ->values()
            ->all();
    }

    public function customerHistory($customerId)
    {
        $bookings = $this->bookingModel
            ->with(['listing:id,name', 'venue:id,name', 'user:id,name'])
            ->where('user_id', $customerId)
            ->orderBy('date', 'desc')->get();

        return sendSuccess('Customer booking history fetched', compact('bookings'));
    }

    /**
     * Collect schedule/capacity/resource validation errors for a create-booking payload.
     * Shared by createBooking and the legacy validate endpoint.
     */
    private function collectCreateBookingValidationErrors(array $formData): array
    {
        $errors = [];

        $baseValidationErrors = $this->validateBooking($formData);
        if (! empty($baseValidationErrors)) {
            $errors['base_booking'] = $baseValidationErrors;
        }

        return $errors;
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
        ]);

        $errors = $this->collectCreateBookingValidationErrors($formData);

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
            'payment_intent_id'               => 'nullable',
            'save_payment_details'            => 'nullable|boolean',
            'quantity'                        => 'required|numeric|min:1',
            'use_gift_card_balance'           => 'nullable|boolean',
            'gift_card_balance_used'          => 'nullable|numeric|min:0',
        ]);

        $validationErrors = $this->collectCreateBookingValidationErrors($formData);
        if (! empty($validationErrors)) {
            Log::error("Booking validation errors", ["Errors" => $validationErrors]);
            return sendError('Validation failed', $validationErrors, 422);
        }

        if (isset($formData['notes'])) {
            $formData['notes'] = strip_tags($formData['notes']);
        } else {
            $formData['notes'] = null;
        }

        try {

            DB::beginTransaction();

            // The validation above ran OUTSIDE this transaction, so the slot may have
            // gone since. Re-check under the listing lock — this is the decision that
            // actually governs whether the booking is created.
            $conflict = $this->capacityGuard(
                $formData['listing_id'], $formData['date'],
                $formData['start_time'], $formData['end_time'],
                (int) ($formData['quantity'] ?? 1)
            );
            if ($conflict) {
                DB::rollBack();

                return sendError($conflict, ['capacity' => $conflict], 422);
            }

            if ($request->filled('phone') && $user->phone !== $request->phone) {
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

            $specialDay = $this->specialDayModel::with('timeBlocks')
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

            $totalBookings    = $formData['quantity'];
            $baseSubtotal     = round($singleSubtotal * $formData['quantity'], 2);
            $combinedSubtotal = $baseSubtotal;

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

            DB::commit();
            $bookingIds      = $createdBookings->pluck('id');
            $createdBookings = Booking::with(['user', 'venue', 'listing', 'payments', 'addOns', 'resourcesAllocated', 'promocode', 'history'])
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

    /**
     * Shared validation rules for customer multi-slot checkout (slots[] or blocks[]).
     *
     * @param  array<int, string>|null  $paymentMethods  Restrict payment_method; null = all customer methods.
     * @return array<string, string>
     */
    public function customerBookingValidationRules(?array $paymentMethods = null): array
    {
        $methods = $paymentMethods ?? [
            'deposit_remaining_on_arrival',
            'online_payment',
            'cash_on_arrival',
            'paid_cash',
            'paid_cheque',
            'paid_credit_card',
            'paid_wire',
            'paid_wire_transfer',
            'payment_skipped',
        ];

        return [
            'venue_id'               => 'required|exists:venues,id',
            'listing_id'             => 'required|exists:listings,id',
            'promocode'              => 'nullable|string',
            'slots'                  => 'required_without:blocks|array|min:1',
            'slots.*.date'           => 'required|date|after_or_equal:today',
            'slots.*.start_time'     => 'required|date_format:H:i',
            'slots.*.end_time'       => 'required|date_format:H:i',
            'slots.*.quantity'       => 'nullable|numeric|min:1',
            'blocks'                 => 'required_without:slots|array|min:1',
            'blocks.*.date'          => 'required|date|after_or_equal:today',
            'blocks.*.start_time'    => 'required|date_format:H:i',
            'blocks.*.end_time'      => 'required|date_format:H:i',
            'blocks.*.quantity'      => 'nullable|numeric|min:1',
            'payment_method'         => 'required|in:' . implode(',', $methods),
            'notes'                  => 'nullable|string|max:500',
            'phone'                  => 'nullable|string|min:5',
            'add_ons'                => 'nullable|array',
            'add_ons.*'              => 'exists:add_ons,id',
            'use_gift_card_balance'  => 'nullable|boolean',
            'gift_card_balance_used' => 'nullable|numeric|min:0',
        ];
    }

    /**
     * Normalize, validate, quote, and price a customer checkout payload.
     * Used by createCustomerBooking() and PaymentService::createCheckoutSession().
     *
     * @return array{
     *   errors?: array,
     *   listing: Listing,
     *   formData: array,
     *   quote: array,
     *   quotedItems: array,
     *   combinedSubtotal: float,
     *   totalBookings: int,
     *   totalDiscount: float,
     *   giftCardBalanceUsed: float,
     *   promocode: Promocode|null,
     *   discounts: array<int, float>,
     *   giftCardAmounts: array<int, float>,
     *   promocode_applied?: bool,
     * }
     */
    public function prepareCustomerCheckout(array $formData, User $user): array
    {
        $listing      = $this->listingModel::with(['pricingGroups'])->findOrFail($formData['listing_id']);
        $quoteService = app(CustomerBookingQuoteService::class);
        $normalizedSlots = $quoteService->normalizeCustomerSlots($listing, $formData);

        if (empty($normalizedSlots)) {
            return ['errors' => ['slots' => ['Please pick at least one time slot.']]];
        }

        $formData['slots'] = $normalizedSlots;

        $validationErrors = $this->collectCustomerSlotsValidationErrors($formData);
        if (! empty($validationErrors)) {
            return ['errors' => $validationErrors];
        }

        $quote = $quoteService->quoteSlots(
            $listing,
            $normalizedSlots,
            $formData['add_ons'] ?? []
        );

        $combinedSubtotal = $quote['combined_subtotal'];
        $totalBookings    = $quote['booking_count'];

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
        if (! empty($formData['promocode'] ?? null)) {
            $promocode = Promocode::where('code', $formData['promocode'])->active()->first();

            if (! $promocode) {
                return ['errors' => ['promocode' => ['Invalid or inactive promocode']]];
            }

            $isValidPromocode = $promocode->isValidForOrder(
                $combinedSubtotal,
                $user->id,
                $formData['payment_method'],
                $formData['venue_id'],
                $formData['listing_id']
            );

            if (! $isValidPromocode) {
                return ['errors' => ['promocode' => ['Promocode is not applicable']]];
            }

            $totalDiscount = $promocode->applyDiscount($combinedSubtotal);
        }

        if ($giftCardBalanceUsed > 0) {
            $totalAfterDiscount  = $combinedSubtotal - $totalDiscount;
            $giftCardBalanceUsed = min($giftCardBalanceUsed, $totalAfterDiscount);
        }

        return [
            'listing'             => $listing,
            'formData'            => $formData,
            'quote'               => $quote,
            'quotedItems'         => $quote['slots'],
            'combinedSubtotal'    => $combinedSubtotal,
            'totalBookings'       => $totalBookings,
            'totalDiscount'       => $totalDiscount,
            'giftCardBalanceUsed' => $giftCardBalanceUsed,
            'promocode'           => $promocode,
            'discounts'           => $this->splitAmountAcrossBookings($totalDiscount, $totalBookings),
            'giftCardAmounts'     => $this->splitAmountAcrossBookings($giftCardBalanceUsed, $totalBookings),
        ];
    }

    /**
     * Customer marketplace checkout: one booking per selected slot with extension pricing.
     * Single entry point for HTTP controllers and PaymentService.
     *
     * @param  array|null  $preparedCheckout  Output from prepareCustomerCheckout(); skips re-quote when provided.
     */
    public function createCustomerBooking($request, ?array $preparedCheckout = null)
    {
        logger('Customer booking creation request', ['Request' => $request->all()]);

        $user = $request->user_id ? User::find($request->user_id) : auth()->user();

        if ($preparedCheckout === null) {
            $hasSlots  = $request->filled('slots') && is_array($request->slots) && count($request->slots) > 0;
            $hasBlocks = $request->filled('blocks') && is_array($request->blocks) && count($request->blocks) > 0;

            if (! $hasSlots && ! $hasBlocks) {
                return sendError('Please pick at least one time slot.', [], 422);
            }

            $formData = $request->validate($this->customerBookingValidationRules());
        } else {
            $formData = $preparedCheckout['formData'];
        }

        if (isset($formData['notes'])) {
            $formData['notes'] = strip_tags($formData['notes']);
        } else {
            $formData['notes'] = null;
        }

        try {
            DB::beginTransaction();

            // prepareCustomerCheckout() counts existing bookings for every requested
            // slot. Take the listing's booking mutex FIRST so those counts cannot be
            // invalidated by a booking landing between the count and the insert.
            $this->lockListingForBooking($formData['listing_id'] ?? null);

            if ($request->filled('phone') && $user->phone !== $request->phone) {
                $user->phone = $request->phone;
                $user->save();
            }

            if ($preparedCheckout === null) {
                $preparedCheckout = $this->prepareCustomerCheckout($formData, $user);

                if (! empty($preparedCheckout['errors'])) {
                    DB::rollBack();
                    Log::error('Customer slot booking validation errors', ['Errors' => $preparedCheckout['errors']]);

                    return sendError('Validation failed', $preparedCheckout['errors'], 422);
                }
            }

            $formData        = $preparedCheckout['formData'];
            $formData['notes'] = isset($formData['notes']) ? strip_tags($formData['notes']) : null;
            $preparedCheckout['formData'] = $formData;
            $listing         = $preparedCheckout['listing'];
            $quotedItems     = $preparedCheckout['quotedItems'];
            $discounts       = $preparedCheckout['discounts'];
            $giftCardAmounts = $preparedCheckout['giftCardAmounts'];
            $promocode       = $preparedCheckout['promocode'];
            $totalDiscount   = $preparedCheckout['totalDiscount'];

            if ($promocode && $totalDiscount > 0 && empty($preparedCheckout['promocode_applied'])) {
                $promocode->incrementUsage($user->id);
            }

            $createdBookings = collect();
            $discountIndex   = 0;

            foreach ($quotedItems as $slotIndex => $slot) {
                $isFirst = $slotIndex === 0;

                $slotFormData            = $formData;
                $slotFormData['date']    = $slot['date'];
                $slotFormData['add_ons'] = $isFirst ? ($formData['add_ons'] ?? []) : [];

                $bookingDiscount       = $discounts[$discountIndex] ?? 0;
                $bookingGiftCardAmount = $giftCardAmounts[$discountIndex] ?? 0;
                $discountIndex++;

                $booking = $this->createSingleBooking(
                    $user,
                    $slotFormData,
                    $slot['date'],
                    $slot['start_time'],
                    $slot['end_time'],
                    $slot['subtotal'],
                    $bookingDiscount,
                    $bookingGiftCardAmount,
                    $promocode,
                    null,
                    null,
                    $listing,
                    $request
                );

                if (! $booking) {
                    DB::rollBack();
                    Log::error("Failed to create slot booking for user {$user->id} on {$slot['date']}");

                    return sendError('Failed to create booking');
                }

                if (! empty($slot['quantity']) && (int) $slot['quantity'] > 0) {
                    $booking->players = (int) $slot['quantity'];
                    $booking->save();
                }

                $createdBookings->push($booking);
            }

            DB::commit();

            $bookingIds      = $createdBookings->pluck('id');
            $createdBookings = Booking::with(['user', 'venue', 'listing', 'payments', 'addOns', 'resourcesAllocated', 'promocode', 'history'])
                ->whereIn('id', $bookingIds)
                ->get();

            foreach ($createdBookings as $booking) {
                $this->sendBookingNotification($booking, 'created');
            }

            return sendSuccess('Booking confirmed successfully', ['bookings' => $createdBookings]);
        } catch (Exception $e) {
            DB::rollBack();
            Log::error('Customer slot bookings failed: ' . $e->getMessage(), ['Exception' => $e]);

            return sendError('Something went wrong please try again later: ' . $e->getMessage());
        }
    }

    /**
     * Split a currency amount evenly across N bookings, fixing rounding on the first.
     *
     * @return array<int, float>
     */
    private function splitAmountAcrossBookings(float $amount, int $totalBookings): array
    {
        if ($totalBookings <= 0) {
            return [];
        }

        if ($amount <= 0) {
            return array_fill(0, $totalBookings, 0.0);
        }

        $perBooking         = round($amount / $totalBookings, 2);
        $calculatedTotal    = $perBooking * $totalBookings;
        $roundingDifference = round($amount - $calculatedTotal, 2);
        $discounts          = [];

        for ($i = 0; $i < $totalBookings; $i++) {
            $discounts[] = $i === 0
                ? round($perBooking + $roundingDifference, 2)
                : $perBooking;
        }

        return $discounts;
    }

    private function collectCustomerSlotsValidationErrors(array $formData): array
    {
        $errors = [];

        foreach ($formData['slots'] ?? [] as $index => $slot) {
            $slotData = array_merge($formData, [
                'date'       => $slot['date'],
                'start_time' => $slot['start_time'],
                'end_time'   => $slot['end_time'],
                'quantity'   => $slot['quantity'] ?? 1,
            ]);
            unset($slotData['slots'], $slotData['blocks']);

            $validationErrors = $this->validateBooking($slotData);
            if (! empty($validationErrors)) {
                $errors['slots'][$index] = $validationErrors;
            }
        }

        return $errors;
    }

    private function collectCustomerBlocksValidationErrors(array $formData): array
    {
        $errors = [];

        foreach ($formData['blocks'] ?? [] as $index => $block) {
            $blockData = array_merge($formData, [
                'date'     => $block['date'],
                'start_time' => $block['start_time'],
                'end_time'   => $block['end_time'],
                'quantity' => $block['quantity'] ?? 1,
            ]);
            unset($blockData['blocks']);

            $validationErrors = $this->validateBooking($blockData);
            if (! empty($validationErrors)) {
                $errors['blocks'][$index] = $validationErrors;
            }
        }

        return $errors;
    }

    public function update($request, $bookingId, bool $fromMobile = false)
    {
        if ($fromMobile && ! auth()->user()) {
            return sendError('User not authenticated', [], 401);
        }

        $booking = $this->bookingModel::with(['user', 'venue', 'listing', 'payments', 'addOns', 'resourcesAllocated', 'promocode'])
            ->findOrFail($bookingId);

        if ($fromMobile) {
            if (! in_array($booking->status, [BookingStatus::PENDING, BookingStatus::CONFIRMED], true)) {
                return sendError('Cannot update ' . $booking->status . ' bookings. Only pending or confirmed bookings can be updated.');
            }
            $this->normalizeBookingTimeFields($request);
        } elseif ($booking->status != BookingStatus::PENDING) {
            return sendError('Cannot update ' . $booking->status . ' bookings.');
        }

        $rules = [
            'user_id'            => 'sometimes|exists:users,id',
            'venue_id'           => 'sometimes|exists:venues,id',
            'listing_id'         => 'sometimes|exists:listings,id',
            'start_time'         => 'sometimes|date_format:H:i',
            'end_time'           => 'sometimes|date_format:H:i',
            'notes'              => 'nullable|string|max:1000',
            'selected_add_ons'   => 'nullable|array',
            'selected_add_ons.*' => 'exists:add_ons,id',
            'add_ons'            => 'nullable|array',
            'add_ons.*'          => 'exists:add_ons,id',
        ];

        if ($fromMobile) {
            $rules['date']           = 'sometimes|date|after_or_equal:today';
            $rules['payment_method'] = 'sometimes|in:payment_skipped,cash_on_arrival,online_payment,paid_cash,paid_cheque,paid_credit_card,paid_wire_transfer,deposit_remaining_on_arrival';
        } else {
            $rules['date']            = 'sometimes|date';
            $rules['total_price']     = 'sometimes|numeric|min:0';
            $rules['subtotal']        = 'sometimes|numeric|min:0';
            $rules['discount_amount'] = 'sometimes|numeric|min:0';
            $rules['remove_promocode'] = 'sometimes|boolean';
        }

        $formData = $request->validate($rules);

        if (isset($formData['add_ons']) && ! isset($formData['selected_add_ons'])) {
            $formData['selected_add_ons'] = $formData['add_ons'];
        }

        try {
            DB::beginTransaction();

            $actor            = auth()->user();
            $oldListingId     = $booking->listing_id;
            $oldVenueId       = $booking->venue_id;
            $oldDate          = $booking->date;
            $oldStartTime     = $booking->start_time;
            $oldEndTime       = $booking->end_time;
            $oldPrice         = (float) $booking->total_price;
            $oldPaymentMethod = $booking->payment_method;
            $oldUserId        = $booking->user_id;
            $oldAddOns        = $booking->addOns->pluck('id')->toArray();
            $oldNotes         = $booking->notes;

            $updateData    = [];
            $userChanged   = false;
            $timeChanged   = false;
            $listingChanged = false;
            $venueChanged  = false;
            $addOnsChanged = false;
            $priceChanged  = false;
            $paymentMethodChanged = false;
            $shouldAutoConfirm    = false;
            $newPaymentStatus     = null;

            if (isset($formData['user_id']) && (int) $formData['user_id'] !== (int) $booking->user_id) {
                $newUser               = User::findOrFail($formData['user_id']);
                $updateData['user_id'] = $newUser->id;
                $userChanged           = true;
            }

            if (isset($formData['venue_id'])) {
                $updateData['venue_id'] = $formData['venue_id'];
                $venueChanged           = (int) $formData['venue_id'] !== (int) $oldVenueId;
            }

            if (isset($formData['listing_id'])) {
                $updateData['listing_id'] = $formData['listing_id'];
                $listingChanged           = (int) $formData['listing_id'] !== (int) $oldListingId;

                if ($listingChanged) {
                    $newListing = Listing::find($formData['listing_id']);
                    if ($newListing && ! $newListing->isVisibleToRequestSurface()) {
                        DB::rollBack();
                        return sendError('The selected listing is not available for booking.');
                    }
                }
            }

            if (isset($formData['notes'])) {
                $updateData['notes'] = $formData['notes'];
            }

            if (isset($formData['date']) || isset($formData['start_time']) || isset($formData['end_time'])) {
                $timeChanged = true;

                $validationData = [
                    'date'       => $formData['date'] ?? $booking->date,
                    'start_time' => $formData['start_time'] ?? $booking->start_time,
                    'end_time'   => $formData['end_time'] ?? $booking->end_time,
                    'venue_id'   => $formData['venue_id'] ?? $booking->venue_id,
                    'listing_id' => $formData['listing_id'] ?? $booking->listing_id,
                ];

                // Moving a booking to a new time competes for capacity exactly like a
                // new one, so the same mutex applies before the count below.
                $this->lockListingForBooking($validationData['listing_id']);

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

            $listingId = $formData['listing_id'] ?? $booking->listing_id;
            $listing   = Listing::with('pricingGroups')->findOrFail($listingId);

            $shouldRecalculatePrice = $fromMobile
                || (($listingChanged || $venueChanged || $timeChanged) && ! isset($formData['total_price']));

            if ($shouldRecalculatePrice && ($listingChanged || $venueChanged || $timeChanged)) {
                $venueId = $formData['venue_id'] ?? $booking->venue_id;

                if (! $venueChanged && $listing->venue_id != $venueId) {
                    $updateData['venue_id'] = $listing->venue_id;
                    $venueId                = $listing->venue_id;
                    $venueChanged           = true;
                }

                $date      = $formData['date'] ?? $booking->date;
                $startTime = $formData['start_time'] ?? $booking->start_time;
                $endTime   = $formData['end_time'] ?? $booking->end_time;

                $startTimeCarbon = Carbon::parse($date . ' ' . $startTime);
                $endTimeCarbon   = Carbon::parse($date . ' ' . $endTime);
                if ($endTimeCarbon->lt($startTimeCarbon)) {
                    $endTimeCarbon->addDay();
                }

                $updateData['duration'] = abs($endTimeCarbon->diffInHours($startTimeCarbon));

                $weekday = strtolower(Carbon::parse($date)->format('l'));

                $specialDay = $this->specialDayModel::with('timeBlocks')
                    ->where('venue_id', $venueId)
                    ->where(function ($q) use ($listing) {
                        $q->whereNull('listing_id')->orWhere('listing_id', $listing->id);
                    })
                    ->whereDate('date', $date)->active()->first();

                $basePrice = round(determineSlotPrice($listing, $weekday, $startTime, $endTime, $specialDay), 2);

                $addOnIds  = $formData['selected_add_ons'] ?? $oldAddOns;
                $addOnCost = ! empty($addOnIds)
                    ? AddOn::whereIn('id', $addOnIds)->sum('price')
                    : 0;

                $newSubtotal = round($basePrice + $addOnCost, 2);
                $newDiscount = 0;

                if ($booking->promocode) {
                    $promocode = $booking->promocode;
                    $promoUser = $formData['user_id'] ?? $booking->user_id;
                    if ($promocode->isValidForOrder($newSubtotal, $promoUser, $booking->payment_method, $venueId, $listingId)) {
                        $newDiscount = $promocode->applyDiscount($newSubtotal);
                    } else {
                        $promocode->decrementUsage($promoUser);
                        $updateData['promocode_id']    = null;
                        $updateData['discount_amount'] = 0;
                    }
                }

                $newTotalPrice = round($newSubtotal - $newDiscount, 2);

                if ((float) $newTotalPrice !== $oldPrice) {
                    $priceChanged = true;
                }

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
            } elseif (! $fromMobile) {
                if (isset($formData['total_price'])) {
                    $newPrice     = (float) $formData['total_price'];
                    $priceChanged = $newPrice !== $oldPrice;
                    $updateData['total_price'] = $newPrice;
                }
                if (isset($formData['subtotal'])) {
                    $updateData['subtotal'] = $formData['subtotal'];
                }
                if (isset($formData['discount_amount'])) {
                    $updateData['discount_amount'] = $formData['discount_amount'];
                }
            }

            if (isset($formData['selected_add_ons'])) {
                $newAddOns = $formData['selected_add_ons'];

                $listingForAddOns  = Listing::with('addOns')->findOrFail($listingId);
                $availableAddOnIds = $listingForAddOns->addOns->pluck('id')->toArray();
                $invalidAddOns     = array_diff($newAddOns, $availableAddOnIds);

                if (! empty($invalidAddOns)) {
                    DB::rollBack();
                    return sendError('Some add-ons are not available for this listing', [
                        'invalid_add_ons'   => $invalidAddOns,
                        'available_add_ons' => $availableAddOnIds,
                    ], 422);
                }

                sort($newAddOns);
                $sortedOldAddOns = $oldAddOns;
                sort($sortedOldAddOns);

                if ($newAddOns != $sortedOldAddOns) {
                    $addOnsChanged = true;
                    $booking->addOns()->sync($newAddOns);

                    if (! $listingChanged && ! $venueChanged && ! $timeChanged) {
                        $oldAddOnCost = AddOn::whereIn('id', $oldAddOns)->sum('price');
                        $basePrice    = (float) $booking->subtotal - (float) $oldAddOnCost;
                        $newAddOnCost = AddOn::whereIn('id', $newAddOns)->sum('price');
                        $newSubtotal  = round($basePrice + $newAddOnCost, 2);
                        $newDiscount  = (float) $booking->discount_amount;

                        if ($booking->promocode) {
                            $promocode = $booking->promocode;
                            $promoUser = $formData['user_id'] ?? $booking->user_id;
                            if ($promocode->isValidForOrder($newSubtotal, $promoUser, $booking->payment_method, $booking->venue_id, $booking->listing_id)) {
                                $newDiscount = $promocode->applyDiscount($newSubtotal);
                            } else {
                                $promocode->decrementUsage($promoUser);
                                $updateData['promocode_id']    = null;
                                $newDiscount                     = 0;
                            }
                        }

                        $newTotalPrice = round($newSubtotal - $newDiscount, 2);
                        if ((float) $newTotalPrice !== $oldPrice) {
                            $priceChanged = true;
                        }

                        $updateData['subtotal']        = $newSubtotal;
                        $updateData['discount_amount'] = $newDiscount;
                        $updateData['total_price']     = $newTotalPrice;
                    }
                }
            } elseif (isset($formData['listing_id']) && $listingChanged) {
                $resources = $listing->resourceRequirements()->select('resources.id', 'listing_resource.quantity')->get();
                $booking->resourcesAllocated()->detach();
                foreach ($resources as $resource) {
                    $booking->resourcesAllocated()->attach($resource->id, ['quantity' => $resource->pivot->quantity]);
                }
            }

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

                    foreach ($booking->payments()->where('status', PaymentStatus::PENDING)->get() as $payment) {
                        $payment->update([
                            'status' => 'cancelled',
                            'notes'  => ($payment->notes ?? '') . ' - Cancelled: Booking changed to payment skipped',
                        ]);
                    }
                } elseif ($newPaymentStatus === BookingPaymentStatus::DEPOSIT_PAID) {
                    $totalPrice    = $updateData['total_price'] ?? $booking->total_price;
                    $depositAmount = 0;

                    if (! empty($listing->payment_options['deposit'])) {
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
                    $pendingPayments = $booking->payments()->where('status', PaymentStatus::PENDING)->get();

                    if ($pendingPayments->isNotEmpty()) {
                        foreach ($pendingPayments as $payment) {
                            $payment->update([
                                'method' => $paymentMethod,
                                'status' => PaymentStatus::PAID,
                                'date'   => now()->format('Y-m-d'),
                                'notes'  => str_replace('pending', 'paid via ' . str_replace('paid_', '', $newPaymentMethod), $payment->notes ?? "Payment for booking #{$booking->booking_id}"),
                            ]);
                        }
                    } elseif (! $booking->is_free) {
                        $booking->payments()->create([
                            // The currency this money is in — without it the row reports as
                            // "Unknown currency" on the Payments page.
                            'currency'   => $this->bookingCurrency($booking),
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

            $booking->refresh();

            if ((float) $booking->total_price !== $oldPrice) {
                $priceChanged = true;
                $this->adjustPaymentsForPriceChange($booking, $oldPrice, (float) $booking->total_price);
                $booking->refresh();
            }

            if ($userChanged) {
                $booking->payments()->update(['user_id' => $updateData['user_id']]);
                $booking->invoices()->update(['user_id' => $updateData['user_id']]);
                $booking->history()->create([
                    'user_id'     => $actor->id,
                    'action'      => 'user_changed',
                    'description' => "Booking transferred from user #{$oldUserId} to user #{$updateData['user_id']}",
                ]);
            }

            if ($shouldAutoConfirm && $newPaymentStatus === BookingPaymentStatus::FULLY_PAID) {
                autoConfirmBookingIfPaid($booking);
                $booking->refresh();
            }

            $changes = [];

            if ($timeChanged) {
                $changes[] = 'Time slot changed from ' . $oldDate . ' ' . $oldStartTime . '-' . $oldEndTime
                    . ' to ' . ($booking->date) . ' ' . ($booking->start_time) . '-' . ($booking->end_time);
            }
            if ($venueChanged) {
                $changes[] = 'Venue changed';
            }
            if ($listingChanged) {
                $changes[] = 'Listing changed';
            }
            if ($paymentMethodChanged) {
                $changes[] = 'Payment method changed from ' . $oldPaymentMethod . ' to ' . $formData['payment_method'];
            }
            if ($addOnsChanged) {
                $oldAddOnsNames = AddOn::whereIn('id', $oldAddOns)->pluck('name')->implode(', ');
                $newAddOnsNames = AddOn::whereIn('id', $formData['selected_add_ons'] ?? [])->pluck('name')->implode(', ');
                $changes[]      = 'Add-ons changed from [' . $oldAddOnsNames . '] to [' . $newAddOnsNames . ']';
            }
            if ($userChanged) {
                $oldUserName = User::find($oldUserId)->name ?? "User #{$oldUserId}";
                $newUserName = User::find($updateData['user_id'])->name ?? "User #{$updateData['user_id']}";
                $changes[]   = "Customer changed from {$oldUserName} to {$newUserName}";
            }
            if (isset($formData['notes']) && $formData['notes'] !== $oldNotes) {
                $changes[] = 'Notes updated';
            }
            if ($priceChanged) {
                $changes[] = 'Price updated from QAR ' . $oldPrice . ' to QAR ' . $booking->total_price;
            }

            if (! empty($changes)) {
                $description = $fromMobile
                    ? 'Booking updated via mobile app: ' . implode(', ', $changes) . ' by ' . $actor->name
                    : 'Booking updated: ' . implode(', ', $changes);

                $booking->history()->create([
                    'user_id'     => $actor->id,
                    'action'      => 'booking_updated',
                    'description' => $description,
                ]);
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
                    Log::error('Booking update notification failed: ' . $e->getMessage(), [
                        'booking_id' => $booking->id,
                        'exception'  => $e,
                    ]);
                }
            }

            $this->sendBookingNotification($booking, 'updated');

            return sendSuccess('Booking updated successfully', compact('booking'));
        } catch (Exception $e) {
            DB::rollBack();
            Log::error('Failed to update booking #' . $bookingId . ': ' . $e->getMessage(), [
                'request'     => $request->all(),
                'booking_id'  => $booking->id ?? $bookingId,
                'from_mobile' => $fromMobile,
                'exception'   => $e,
            ]);

            return sendError('Failed to update booking: ' . $e->getMessage());
        }
    }

    private function normalizeBookingTimeFields($request): void
    {
        foreach (['start_time', 'end_time'] as $field) {
            if (! $request->has($field)) {
                continue;
            }

            $parts = explode(':', $request->input($field));
            if (count($parts) >= 2) {
                $request->merge([
                    $field => str_pad($parts[0], 2, '0', STR_PAD_LEFT) . ':' . str_pad($parts[1], 2, '0', STR_PAD_LEFT),
                ]);
            }
        }
    }

    public function confirm($request)
    {
        $validated = $request->validate([
            'booking_id'              => 'required|exists:bookings,id',
            'send_confirmation_email' => 'sometimes|boolean',
        ]);

        $sendConfirmationEmail = $request->boolean('send_confirmation_email', true);

        $booking = $this->bookingModel::with('user')->findOrFail($validated['booking_id']);

        try {
            DB::beginTransaction();

            if ($booking->status !== BookingStatus::PENDING) {
                DB::rollBack();
                return sendError('Booking cannot be confirmed (not pending)');
            }

            $booking->update(['status' => BookingStatus::CONFIRMED]);

            if ($booking->loyalty_points_earned > 0) {
                $pointsAlreadyAdded = LoyaltyPoint::where('booking_id', $booking->id)
                    ->where('type', 'credit')
                    ->exists();

                if (! $pointsAlreadyAdded) {
                    $user = $booking->user;
                    $user->addLoyaltyPoints($booking->loyalty_points_earned, $booking->id, "Loyalty points earned for booking confirmation");
                    $booking->history()->create([
                        'user_id'     => auth()->id() ?? $booking->user_id,
                        'action'      => 'loyalty_points_earned',
                        'description' => "{$booking->loyalty_points_earned} loyalty points added to user #{$user->id} ({$user->name}) for booking confirmation.",
                    ]);
                }
            }

            $emailSent = false;
            if ($sendConfirmationEmail && $booking->user) {
                try {
                    $booking->load('venue:id,name,maps_url');
                    $booking->user->notify(new BookingConfirmedUserNotification($booking));
                    $emailSent = true;
                } catch (Exception $e) {
                    Log::error("Booking confirmation notification failed: " . $e->getMessage(), ['booking_id' => $booking->id]);
                }
            }

            $historyDescription = 'Booking confirmed by ' . auth()->user()->name;
            if ($sendConfirmationEmail) {
                $historyDescription .= $emailSent
                    ? '. Confirmation email sent to customer.'
                    : '. Confirmation email could not be sent.';
            } else {
                $historyDescription .= '. No confirmation email sent.';
            }

            $booking->history()->create([
                'user_id'     => auth()->id(),
                'action'      => 'booking_confirmed',
                'description' => $historyDescription,
            ]);

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

            $message = 'Booking confirmed successfully';
            if ($sendConfirmationEmail) {
                $message .= $emailSent
                    ? ' and confirmation email sent.'
                    : ' but confirmation email could not be sent.';
            }

            return sendSuccess($message, compact('booking', 'emailSent'));
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

    public function customerBookings($request, string $scope)
    {
        if (! in_array($scope, ['upcoming', 'recent'], true)) {
            return sendError('Invalid bookings scope. Use upcoming or recent.', [], 422);
        }

        $data = $request->validate([
            'pagination'       => 'nullable|boolean',
            'page'             => 'nullable|integer|min:1',
            'records_per_page' => 'nullable|integer|min:1|max:100',
            'date'             => 'nullable|date',
            'date_from'        => 'nullable|date',
            'date_to'          => 'nullable|date|after_or_equal:date_from',
            'venue_id'         => 'nullable|integer|exists:venues,id',
            'listing_id'       => 'nullable|integer|exists:listings,id',
        ]);

        $paginate       = $request->boolean('pagination');
        $recordsPerPage = (int) $request->input('records_per_page', 10);
        $today          = Carbon::today();

        $query = $this->bookingModel::with($this->customerBookingsRelations())
            ->where('user_id', auth()->id());

        if ($scope === 'upcoming') {
            $query->where('date', '>=', $today)
                ->orderBy('date', 'asc')
                ->orderBy('start_time', 'asc');
            $message = 'My bookings fetched successfully';
        } else {
            $query->where('date', '<', $today)
                ->orderBy('date', 'desc')
                ->orderBy('start_time', 'asc');
            $message = 'Recent bookings fetched successfully';
        }

        if ($request->filled('venue_id')) {
            $query->where('venue_id', $data['venue_id']);
        }

        if ($request->filled('listing_id')) {
            $query->where('listing_id', $data['listing_id']);
        }

        if ($request->filled('date')) {
            $query->whereDate('date', $data['date']);
        }

        if ($request->filled('date_from')) {
            $query->whereDate('date', '>=', $data['date_from']);
        }

        if ($request->filled('date_to')) {
            $query->whereDate('date', '<=', $data['date_to']);
        }

        if ($paginate) {
            $paginated = $query->paginate($recordsPerPage);
            $paginated->setCollection(
                $this->enrichCustomerBookingsWithSport($paginated->getCollection())
            );

            return sendSuccess($message, $paginated->toArray());
        }

        $bookings = $this->enrichCustomerBookingsWithSport($query->get());

        return sendSuccess($message, compact('bookings'));
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
                ->visibleToAdmins()
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
                $query->where('status', '!=', BookingStatus::CANCELLED)
                    ->where('date', '>=', $today);
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
                // AccessScope: ownership + assignment + jurisdiction, one authority.
                $venueIds = AccessScope::allowedVenueIds($user) ?? [];
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

            // For online_payment, if no payment_intent_id, set status to PENDING (will be paid via Stripe checkout)
            $isOnlinePaymentPending = ($formData['payment_method'] === 'online_payment' && !$paymentIntentId);
            $isDepositPending = ($formData['payment_method'] === 'deposit_remaining_on_arrival' && !$paymentIntentId);

            $paymentStatus = match (trim($formData['payment_method'])) {
                'cash_on_arrival'              => BookingPaymentStatus::PENDING,
                'online_payment'               => $isOnlinePaymentPending ? BookingPaymentStatus::PENDING : BookingPaymentStatus::FULLY_PAID,
                'paid_cash'                    => BookingPaymentStatus::FULLY_PAID,
                'paid_cheque'                  => BookingPaymentStatus::FULLY_PAID,
                'paid_credit_card'             => BookingPaymentStatus::FULLY_PAID,
                'paid_wire'                    => BookingPaymentStatus::FULLY_PAID,
                'paid_wire_transfer'           => BookingPaymentStatus::FULLY_PAID,
                'deposit_remaining_on_arrival' => $isDepositPending ? BookingPaymentStatus::PENDING : BookingPaymentStatus::DEPOSIT_PAID,
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
                $depositAmount = min($depositAmount, max(0, $totalPrice));
            }

            $remainingAmount = max(0, $totalPrice - $depositAmount);

            $isPaymentSkipped = $paymentStatus == BookingPaymentStatus::PAYMENT_SKIPPED;

            $booking = $this->bookingModel::create([
                'user_id'               => $user->id,
                'subtotal'              => $subtotal,
                'discount_amount'       => $discount,
                'total_price'           => $totalPrice,
                'payment_status'        => $paymentStatus,
                'promocode_id'          => $promocode?->id,
                'date'                  => $date,
                'notes'                 => $formData['notes'] ?? null,
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
                    'deposit_amount'   => ($paymentStatus == BookingPaymentStatus::DEPOSIT_PAID || ($paymentStatus == BookingPaymentStatus::PENDING && $formData['payment_method'] === 'deposit_remaining_on_arrival')) ? $depositAmount : 0,
                    'deposit_method'   => ($paymentStatus == BookingPaymentStatus::DEPOSIT_PAID || ($paymentStatus == BookingPaymentStatus::PENDING && $formData['payment_method'] === 'deposit_remaining_on_arrival')) ? PaymentMethod::ONLINE : null,
                    'remaining_amount' => ($paymentStatus == BookingPaymentStatus::DEPOSIT_PAID || ($paymentStatus == BookingPaymentStatus::PENDING && $formData['payment_method'] === 'deposit_remaining_on_arrival')) ? $remainingAmount : 0,
                    'remaining_method' => ($paymentStatus == BookingPaymentStatus::DEPOSIT_PAID || ($paymentStatus == BookingPaymentStatus::PENDING && $formData['payment_method'] === 'deposit_remaining_on_arrival')) ? PaymentMethod::CASH : null,
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
            } elseif ($paymentStatus == BookingPaymentStatus::DEPOSIT_PAID || ($paymentStatus == BookingPaymentStatus::PENDING && $formData['payment_method'] === 'deposit_remaining_on_arrival')) {
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

                // Create deposit payment - pending if no payment_intent_id (Stripe checkout), paid if payment_intent_id exists
                $depositPaymentStatus = $paymentIntentId ? PaymentStatus::PAID : PaymentStatus::PENDING;
                $depositPaymentDate = $paymentIntentId ? now()->format('Y-m-d') : null;

                $this->paymentModel::create([
                    // The currency this money is in — without it the row reports as
                    // "Unknown currency" on the Payments page.
                    'currency'   => $this->bookingCurrency($booking),
                    'charge_id'         => $chargeId,
                    'user_id'           => $user->id,
                    'booking_id'        => $booking->id,
                    'amount'            => $depositAmount,
                    'payment_intent_id' => $paymentIntentId,
                    'status'            => $depositPaymentStatus,
                    'type'              => PaymentType::DEPOSIT,
                    'method'            => PaymentMethod::ONLINE,
                    'date'              => $depositPaymentDate,
                    'notes'             => $depositPaymentStatus === PaymentStatus::PAID
                        ? 'Deposit payment for booking #' . $booking->booking_id
                        : 'Deposit payment pending for booking #' . $booking->booking_id,
                    'payment_id'        => generatePaymentId($booking->id, PaymentType::DEPOSIT),
                ]);

                $this->paymentModel::create([
                    // The currency this money is in — without it the row reports as
                    // "Unknown currency" on the Payments page.
                    'currency'   => $this->bookingCurrency($booking),
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

                // For online_payment, if no payment_intent_id, create pending payment (will be paid via Stripe checkout success)
                $isOnlinePaymentPending = ($formData['payment_method'] === 'online_payment' && !$paymentIntentId);
                $finalPaymentStatus = ($paymentStatus == BookingPaymentStatus::FULLY_PAID && !$isOnlinePaymentPending)
                    ? PaymentStatus::PAID
                    : PaymentStatus::PENDING;
                $finalPaymentDate = ($finalPaymentStatus == PaymentStatus::PAID)
                    ? now()->format('Y-m-d')
                    : null;

                $booking->payments()->create([
                    // The currency this money is in — without it the row reports as
                    // "Unknown currency" on the Payments page.
                    'currency'   => $this->bookingCurrency($booking),
                    'charge_id'         => $chargeId,
                    'user_id'           => $user->id,
                    'amount'            => $totalPrice,
                    'payment_intent_id' => $paymentIntentId,
                    'type'              => PaymentType::FULL,
                    'payment_id'        => generatePaymentId($booking->id, PaymentType::FULL),
                    'date'              => $finalPaymentDate,
                    'method'            => $paymentMethod,
                    'status'            => $finalPaymentStatus,
                    'notes'             => $finalPaymentStatus == PaymentStatus::PAID
                        ? "Full payment for booking #{$booking->booking_id}"
                        : "Payment pending for booking #{$booking->booking_id}",
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
                'description' => 'Booking created by ' . (auth()->user() ? auth()->user()->name : $user->name),
            ]);

            if ($paymentStatus == BookingPaymentStatus::DEPOSIT_PAID) {
                $booking->history()->create([
                    'user_id'     => $user->id,
                    'description' => 'Deposit payment processed.',
                    'action'      => BookingPaymentStatus::DEPOSIT_PAID,
                ]);
            } elseif ($paymentStatus == BookingPaymentStatus::PENDING && $formData['payment_method'] === 'deposit_remaining_on_arrival') {
                $booking->history()->create([
                    'user_id'     => $user->id,
                    'description' => 'Booking created with deposit payment pending.',
                    'action'      => 'booking_created',
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
                // Bookings still awaiting a Stripe Checkout payment get their
                // invoice generated, but no "booking created" email — nothing
                // has been paid yet.
                $awaitingStripePayment = $isOnlinePaymentPending || $isDepositPending;

                createBookingInvoiceWithNotification($booking, $user, ! $awaitingStripePayment);
            }

            if ($paymentStatus == BookingPaymentStatus::FULLY_PAID) {
                autoConfirmBookingIfPaid($booking);
            }

            $booking->load(['listing:id,name', 'venue:id,name', 'user:id,name', 'invoices']);
            unlockListingSlot($listing, $user, $booking);

            notifyVenueStaffRefresh($booking->venue_id, [
                'event'      => 'booking_created',
                'booking_id' => $booking->id,
                'venue_id'   => $booking->venue_id,
                'listing_id' => $booking->listing_id,
                'bell'       => true,
            ]);

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

    public function sendBookingNotification(Booking $booking, string $eventType)
    {
        try {
            if (! $booking->venue_id) {
                return;
            }

            $recipientIds = venueNotificationRecipientIds($booking->venue_id);

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
                case 'payment_received':
                    if ($booking->payment_status === BookingPaymentStatus::DEPOSIT_PAID) {
                        $title   = 'Deposit Received';
                        $message = "Online deposit received for booking #{$bookingId} by {$customerName} at {$venueName}";
                    } else {
                        $title   = 'Payment Received';
                        $message = "Online payment received for booking #{$bookingId} by {$customerName} at {$venueName}";
                    }
                    break;
                default:
                    return;
            }

            $data = [
                'type'           => $eventType === 'payment_received' ? 'payment_received' : 'booking_' . $eventType,
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

    private function customerBookingsRelations(): array
    {
        return [
            'user',
            'venue',
            'listing.sportsCategory:id,name,slug,icon,image',
            'promocode',
            'addOns',
            'latestInvoice',
            'payments',
        ];
    }

    private function enrichCustomerBookingsWithSport($bookings)
    {
        return $bookings->map(function (Booking $booking) {
            $category = $booking->listing?->sportsCategory;

            $booking->setAttribute('sport', $category?->name);
            $booking->setAttribute('sports_type', $category ? [
                'id'    => $category->id,
                'name'  => $category->name,
                'label' => $category->name,
                'slug'  => $category->slug,
                'icon'  => $category->icon,
                'image' => $category->image,
            ] : null);

            return $booking;
        });
    }

    /**
     * Full roster payload for the apps/admin Bookings page.
     */
    /**
     * The Bookings table — filtered, sorted and paginated ON THE SERVER.
     *
     * It used to return every booking plus every lookup list (including all 15k+
     * customers) and let the browser filter, sort and paginate. That is ~2 MB for a
     * table showing 25 rows, and it stops working entirely once bookings run to
     * thousands. Everything the table can do is now expressed as SQL.
     *
     * Rows are ENRICHED with the names, currency and geography the table displays and
     * filters on, so the page no longer needs the lookup arrays to render a row —
     * which is what made the customer list necessary in the first place.
     */
    public function dashboard(Request $request)
    {
        $filters = $this->dashboardFilters($request);

        // The drawer and the detail modal want the pickers, not the table.
        if ($request->boolean('lookups_only')) {
            return sendSuccess('Bookings lookups', $this->dashboardLookups($request->boolean('with_customers')));
        }

        $query = $this->dashboardBookingQuery($filters);

        // Export asks for every matching row; the table asks for one page.
        if ($request->boolean('export')) {
            $rows = $query->limit(self::EXPORT_MAX)->get();

            return sendSuccess('Bookings fetched', [
                'bookings'  => $this->mapDashboardBookings($rows),
                'truncated' => $rows->count() >= self::EXPORT_MAX,
            ]);
        }

        $perPage = min(max((int) $request->input('per_page', 25), 1), 200);
        $page    = $query->paginate($perPage, ['*'], 'page', max(1, (int) $request->input('page', 1)));

        return sendSuccess('Bookings fetched', array_merge(
            $this->dashboardLookups($request->boolean('with_customers')),
            [
                'bookings'   => $this->mapDashboardBookings(collect($page->items())),
                'pagination' => [
                    'current_page' => $page->currentPage(),
                    'last_page'    => $page->lastPage(),
                    'per_page'     => $page->perPage(),
                    'total'        => $page->total(),
                    'from'         => $page->firstItem(),
                    'to'           => $page->lastItem(),
                ],
                // Filter options must reflect the caller's WHOLE scope, not what
                // happens to be on this page — otherwise the dropdowns change as you
                // page through. A single-country provider still sees just their own.
                'currency_options' => $this->scopedCurrencyOptions(),
                'country_options'  => $this->scopedGeoIds('country'),
                'city_options'     => $this->scopedGeoIds('city'),
                // Everything in scope ignoring filters — the export dialog offers
                // "all bookings" alongside "matching bookings" and must count both.
                'scope_total'      => $this->dashboardBookingQuery($this->dashboardFilters(new Request()))->count(),
            ]
        ));
    }

    /** Normalised filter input — arrays stay arrays whether sent as a[]= or a=1,2. */
    protected function dashboardFilters(Request $request): array
    {
        $arr = function ($v) {
            if (is_array($v)) return array_values(array_filter($v, fn ($x) => $x !== '' && $x !== null));
            if (is_string($v) && $v !== '') return array_values(array_filter(explode(',', $v)));
            return [];
        };

        return [
            'search'         => trim((string) $request->input('search', '')),
            'status'         => $arr($request->input('status')),
            'payment_status' => $arr($request->input('payment_status')),
            'payment_method' => $arr($request->input('payment_method')),
            'currency'       => $arr($request->input('currency')),
            'kind'           => $arr($request->input('kind')),
            'channel'        => $arr($request->input('channel')),
            'listing_id'     => $arr($request->input('listing_id')),
            'venue_id'       => $arr($request->input('venue_id')),
            'country_id'     => $arr($request->input('country_id')),
            'city_id'        => $arr($request->input('city_id')),
            'date_from'      => (string) $request->input('date_from', ''),
            'date_to'        => (string) $request->input('date_to', ''),
            'created_from'   => (string) $request->input('created_from', ''),
            'created_to'     => (string) $request->input('created_to', ''),
            'sort'           => (string) $request->input('sort', 'created_at'),
            'sort_dir'       => strtolower((string) $request->input('sort_dir', 'desc')) === 'asc' ? 'asc' : 'desc',
        ];
    }

    /** How many rows one export may pull, so a runaway export cannot exhaust memory. */
    private const EXPORT_MAX = 20000;

    /** The scoped, filtered, sorted query behind the bookings table. */
    protected function dashboardBookingQuery(array $f)
    {
        $query = $this->bookingModel::query()
            ->with(['user:id,name,email,phone', 'listing:id,name,venue_id', 'venue:id,name,city_id,currency_override', 'payments', 'promocode']);

        // Membership calendar blocks are reservations, not customer bookings.
        if (Schema::hasColumn('bookings', 'origin')) {
            $query->where(fn ($q) => $q->whereNull('origin')->orWhere('origin', '!=', 'class_program'));
        }

        $this->applyDashboardScope($query);
        $this->applyDashboardFilters($query, $f);
        $this->applyDashboardSort($query, $f['sort'], $f['sort_dir']);

        return $query;
    }

    /**
     * Who may see which rows. Venue scope covers provider ownership, jurisdiction and
     * venue pins; listing pins narrow it further, mirroring the dashboard's own
     * `assignedListingIds` check so the page and the API agree on the row count.
     */
    protected function applyDashboardScope($query): void
    {
        $user = auth()->user();
        if (! $user) {
            $query->whereRaw('1 = 0');

            return;
        }

        AccessScope::applyVenue($query, 'venue_id', $user);

        $listingIds = AccessScope::pinnedListingIds($user);
        if ($listingIds) {
            $query->whereIn('listing_id', $listingIds);
        }
    }

    protected function applyDashboardFilters($query, array $f): void
    {
        if ($f['search'] !== '') {
            $term = '%'.$f['search'].'%';
            $query->where(function ($q) use ($term, $f) {
                $q->whereHas('user', fn ($u) => $u->where('name', 'LIKE', $term))
                    ->orWhereHas('listing', fn ($l) => $l->where('name', 'LIKE', $term))
                    ->orWhereHas('venue', fn ($v) => $v->where('name', 'LIKE', $term))
                    ->orWhere('booking_id', 'LIKE', $term);
                // The table shows the numeric id, so an all-digits search matches it.
                if (ctype_digit($f['search'])) {
                    $q->orWhere('id', (int) $f['search']);
                }
            });
        }

        if ($f['status'])         { $query->whereIn('status', $f['status']); }
        if ($f['payment_method']) { $query->whereIn('payment_method', $f['payment_method']); }
        if ($f['listing_id'])     { $query->whereIn('listing_id', $f['listing_id']); }
        if ($f['venue_id'])       { $query->whereIn('venue_id', $f['venue_id']); }

        // The table shows fully_paid as "paid", payment_skipped as "skipped", and a
        // refunded booking as "refunded" whatever its stored status — so the filter
        // has to speak the same language the column does.
        if ($f['payment_status']) {
            $query->where(function ($q) use ($f) {
                foreach ($f['payment_status'] as $s) {
                    match ($s) {
                        'paid'     => $q->orWhere(fn ($w) => $w->where('payment_status', BookingPaymentStatus::FULLY_PAID)->whereNull('refunded_at')),
                        'skipped'  => $q->orWhere(fn ($w) => $w->where('payment_status', BookingPaymentStatus::PAYMENT_SKIPPED)->whereNull('refunded_at')),
                        'refunded' => $q->orWhereNotNull('refunded_at'),
                        default    => $q->orWhere(fn ($w) => $w->where('payment_status', $s)->whereNull('refunded_at')),
                    };
                }
            });
        }

        // kind and channel are derived from `origin` (see the dashboard's
        // bookingMeta.js); both translate to a set of raw origin values.
        if ($f['kind'])    { $this->whereOrigin($query, $f['kind'], self::KIND_ORIGINS, ['customer_game', 'class_program']); }
        if ($f['channel']) { $this->whereOrigin($query, $f['channel'], self::CHANNEL_ORIGINS, ['provider_site', 'manual_admin', 'manual', 'class_program']); }

        // Geography and currency are properties of the VENUE, so they resolve to a
        // venue-id set once rather than joining on every row.
        foreach ([['country_id', 'country'], ['city_id', 'city'], ['currency', 'currency']] as [$key, $kind]) {
            if ($f[$key]) {
                $query->whereIn('venue_id', $this->venueIdsBy($kind, $f[$key]));
            }
        }

        if ($f['date_from'] !== '')    { $query->whereDate('date', '>=', $f['date_from']); }
        if ($f['date_to'] !== '')      { $query->whereDate('date', '<=', $f['date_to']); }
        if ($f['created_from'] !== '') { $query->whereDate('created_at', '>=', $f['created_from']); }
        if ($f['created_to'] !== '')   { $query->whereDate('created_at', '<=', $f['created_to']); }
    }

    /** venue/event/membership and marketplace/provider_site/manual/auto → raw origins. */
    private const KIND_ORIGINS = [
        'event'      => ['customer_game'],
        'membership' => ['class_program'],
    ];

    private const CHANNEL_ORIGINS = [
        'provider_site' => ['provider_site'],
        'manual'        => ['manual_admin', 'manual'],
        'auto'          => ['class_program'],
    ];

    /**
     * Some values are "everything except the named origins" — `venue` is anything that
     * is not an event or a membership block, and `marketplace` is anything not from a
     * provider site, staff or a schedule. NULL origin counts as those defaults, which
     * a plain NOT IN would silently drop.
     */
    protected function whereOrigin($query, array $selected, array $map, array $namedOrigins): void
    {
        $query->where(function ($q) use ($selected, $map, $namedOrigins) {
            foreach ($selected as $value) {
                if (isset($map[$value])) {
                    $q->orWhereIn('origin', $map[$value]);
                } else {
                    // the catch-all member of this dimension
                    $q->orWhere(fn ($w) => $w->whereNull('origin')->orWhereNotIn('origin', $namedOrigins));
                }
            }
        });
    }

    protected function applyDashboardSort($query, string $sort, string $dir): void
    {
        match ($sort) {
            'booking_date'   => $query->orderBy('date', $dir)->orderBy('start_time', $dir),
            'total'          => $query->orderBy('total_price', $dir),
            'booking_status' => $query->orderBy('status', $dir),
            'payment_status' => $query->orderBy('payment_status', $dir),
            // Sorting by a related name needs the join the table's column implies.
            'customer'       => $query->leftJoin('users', 'users.id', '=', 'bookings.user_id')
                                    ->orderBy('users.name', $dir)->select('bookings.*'),
            'listing'        => $query->leftJoin('listings', 'listings.id', '=', 'bookings.listing_id')
                                    ->orderBy('listings.name', $dir)->select('bookings.*'),
            default          => $query->orderBy('created_at', $dir),
        };
        // A stable tiebreak, so paging never repeats or skips a row.
        $query->orderBy('bookings.id', $dir);
    }

    /**
     * Venue ids matching a geography or currency filter.
     *
     * A booking's country, city and currency all come from its venue, so resolving
     * them to a venue-id set once keeps the bookings query a plain whereIn instead of
     * a three-table join per row. Venue counts are small; booking counts are not.
     *
     * @return array<int, int>
     */
    protected function venueIdsBy(string $kind, array $values): array
    {
        $venues = $this->scopedVenues();

        return $venues->filter(function ($v) use ($kind, $values) {
            $actual = match ($kind) {
                'country'  => $this->venueCountryId($v),
                'city'     => $v->city_id,
                'currency' => $this->venueCurrency($v),
                default    => null,
            };

            return $actual !== null && in_array((string) $actual, array_map('strval', $values), true);
        })->pluck('id')->map(fn ($i) => (int) $i)->values()->all()
            // No match must return "nothing", not "everything".
            ?: [0];
    }

    /** Venues in the caller's scope, loaded once per request. */
    protected function scopedVenues()
    {
        if ($this->scopedVenuesCache !== null) {
            return $this->scopedVenuesCache;
        }

        $cols = ['id', 'name', 'status'];
        foreach (['city_id', 'currency_override', 'owner_user_id'] as $c) {
            if (Schema::hasColumn('venues', $c)) {
                $cols[] = $c;
            }
        }

        $q = Venue::query();
        AccessScope::applyVenue($q, 'id', auth()->user());

        return $this->scopedVenuesCache = $q->get($cols);
    }

    protected ?\Illuminate\Support\Collection $scopedVenuesCache = null;
    protected ?array $cityCountryCache = null;
    protected ?array $countryCurrencyCache = null;

    protected function venueCountryId($venue): ?int
    {
        // The venue's own country first. The city map below is built from
        // scopedVenues(), which is empty at some points in a request — so a venue
        // that plainly has country_id 179 resolved to NULL, and its payments were
        // written with no currency at all.
        if (! empty($venue->country_id)) {
            return (int) $venue->country_id;
        }

        if ($this->cityCountryCache === null) {
            $ids = $this->scopedVenues()->pluck('city_id')->filter()->unique();
            $this->cityCountryCache = $ids->isEmpty() || ! class_exists(City::class)
                ? []
                : City::whereIn('id', $ids)->pluck('country_id', 'id')->map(fn ($c) => (int) $c)->all();
        }

        return $venue->city_id ? ($this->cityCountryCache[$venue->city_id] ?? null) : null;
    }

    /** venue override → the country's currency → AED, matching the dashboard exactly. */
    /**
     * The currency a booking's money is in.
     *
     * Payments used to be written with no currency at all: the column was added
     * later and back-filled to QAR for the rows that existed, but the create calls
     * were never updated. Every booking payment made since has been NULL, which is
     * why the Payments page reports money under "Unknown currency".
     */
    protected function bookingCurrency($booking): string
    {
        if (! $booking) {
            return '';
        }
        // Load the venue by id rather than reusing a loaded relation: venues that
        // arrive via scopedVenues() are hydrated from a narrow column list with no
        // country_id, so the relation can look complete while the field needed to
        // resolve a currency is silently absent.
        $venue = $booking->venue_id ? Venue::find($booking->venue_id) : null;

        return $venue ? $this->venueCurrency($venue) : '';
    }

    protected function venueCurrency($venue): string
    {
        if (! empty($venue->currency_override)) {
            return $venue->currency_override;
        }

        if ($this->countryCurrencyCache === null) {
            $this->countryCurrencyCache = class_exists(Country::class)
                ? Country::with('currency')->get()->mapWithKeys(fn ($c) => [(int) $c->id => ($c->currency?->code ?: '')])->all()
                : [];
        }

        $countryId = $this->venueCountryId($venue);

        // No last-resort code: a venue with no resolvable country shows its amounts
        // without one, instead of labelling them a currency nobody charged.
        return $countryId ? ($this->countryCurrencyCache[$countryId] ?? '') : '';
    }

    /**
     * Country / city ids present across the caller's scope, so the geo dropdowns list
     * exactly what they could match and nothing more.
     *
     * @return array<int, string>
     */
    protected function scopedGeoIds(string $kind): array
    {
        return $this->scopedVenues()
            ->map(fn ($v) => $kind === 'country' ? $this->venueCountryId($v) : $v->city_id)
            ->filter()->unique()->values()
            ->map(fn ($id) => (string) $id)
            ->all();
    }

    /** Currencies present across the caller's whole scope — stable while paging. */
    protected function scopedCurrencyOptions(): array
    {
        return $this->scopedVenues()
            ->map(fn ($v) => $this->venueCurrency($v))
            ->unique()->sort()->values()
            ->map(fn ($c) => ['value' => $c, 'label' => $c])
            ->all();
    }

    /**
     * Map a page of bookings, adding the names, currency and geography the table shows
     * and filters on. Without these the page would still need the full customer,
     * listing and venue lists just to render a row.
     *
     * @return array<int, array<string, mixed>>
     */
    protected function mapDashboardBookings($rows): array
    {
        $venues = $this->scopedVenues()->keyBy('id');

        return collect($rows)->map(function (Booking $b) use ($venues) {
            $venue = $b->relationLoaded('venue') ? $b->venue : ($venues[$b->venue_id] ?? null);

            return array_merge($this->mapDashboardBooking($b), [
                'customer_name'  => $b->user?->name,
                'customer_email' => $b->user?->email,
                'customer_phone' => $b->user?->phone,
                'listing_name'   => $b->listing?->name,
                'location_name'  => $venue?->name,
                'currency'       => $venue ? $this->venueCurrency($venue) : '',
                'city_id'        => $venue?->city_id ? (string) $venue->city_id : null,
                'country_id'     => $venue && $this->venueCountryId($venue) ? (string) $this->venueCountryId($venue) : null,
            ]);
        })->values()->all();
    }

    public function crm($id)
    {
        $booking = $this->bookingModel::with([
            'user:id,name,email,phone,gender,status,created_at',
            'listing',
            'venue.users',
            'payments',
            'promocode',
            'addOns',
            'history.user:id,name',
        ])->find($id);

        if (! $booking) {
            return sendError('Booking not found', [], 404);
        }

        $lookups = $this->dashboardLookups();
        $mapped  = $this->mapDashboardBooking($booking);

        $customer = $booking->user ? [
            'id'              => (string) $booking->user->id,
            'full_name'       => $booking->user->name,
            'email'           => $booking->user->email,
            'phone'           => $booking->user->phone,
            'gender'          => $booking->user->gender,
            'status'          => $booking->user->status,
            'created_at'      => $booking->user->created_at?->toIso8601String(),
            'loyalty_points'  => (int) LoyaltyPoint::balance($booking->user->id),
        ] : null;

        $listing  = $this->mapDashboardListing($booking->listing);
        $location = $this->mapDashboardLocation($booking->venue);

        $payCur   = $booking->venue ? $this->venueCurrency($booking->venue) : null;
        $payments = $booking->payments->map(fn(Payment $p) => $this->mapDashboardPayment($p, $payCur))->values();

        $auditLogs = AuditLog::query()
            ->where('resource', 'booking')
            ->where('resource_id', (string) $booking->id)
            ->orderBy('timestamp')
            ->get()
            ->map(fn(AuditLog $log) => [
                'id'         => (string) $log->id,
                'action'     => $log->action,
                'details'    => $log->details,
                'actor'      => $log->actor_email,
                'created_at' => $log->timestamp?->toIso8601String() ?: $log->created_at?->toIso8601String(),
            ])->values();

        $existingPromo = null;
        if ($booking->promocode && (float) $booking->discount_amount > 0) {
            $existingPromo = [
                'code'            => $booking->promocode->code,
                'discount_amount' => (float) $booking->discount_amount,
            ];
        }

        return sendSuccess('Booking fetched', array_merge($lookups, [
            'booking'        => $mapped,
            'customer'       => $customer,
            'listing'        => $listing,
            'location'       => $location,
            'payments'       => $payments,
            'audit_logs'     => $auditLogs,
            'existing_promo' => $existingPromo,
            'other_pending'  => $this->mapDashboardOtherPending($booking),
            'wallets'        => $this->mapDashboardWallets($booking->listing),
        ]));
    }

    public function occupancy(Request $request)
    {
        $data = $request->validate([
            'listing_id' => 'required',
            'date'       => 'required|date',
        ]);

        $listing = Listing::with(['schedules', 'addOns', 'pricingGroups'])->find($data['listing_id']);
        if (! $listing) {
            return sendError('Listing not found', [], 404);
        }

        $existing = $this->bookingModel::query()
            ->where('listing_id', $listing->id)
            ->whereDate('date', $data['date'])
            ->where('status', '!=', BookingStatus::CANCELLED)
            ->get(['id', 'start_time', 'end_time'])
            ->map(fn(Booking $b) => [
                'id'         => (string) $b->id,
                'start_time' => $this->fmtTime($b->start_time),
                'end_time'   => $this->fmtTime($b->end_time),
            ])->values();

        $dowMap = [
            'sunday' => 0,
            'monday' => 1,
            'tuesday' => 2,
            'wednesday' => 3,
            'thursday' => 4,
            'friday' => 5,
            'saturday' => 6,
        ];

        $listingHours = $listing->schedules->map(function (Schedule $s) use ($dowMap) {
            $day = strtolower((string) $s->day);

            return [
                'day_of_week' => $dowMap[$day] ?? 0,
                'is_open'     => (bool) $s->is_open,
                'open_time'   => $this->fmtTime($s->start_time),
                'close_time'  => $this->fmtTime($s->end_time),
            ];
        })->values();

        $addOns = $listing->addOns
            ->filter(fn($a) => ($a->status ?? 'active') === 'active')
            ->map(fn($a) => [
                'id'          => (string) $a->id,
                'name'        => $a->name,
                'description' => $a->description,
                'price'       => (float) $a->price,
                'stock'       => null,
                'status'      => $a->status ?? 'active',
            ])->values();

        [$pricingCells, $pricingGroups] = $this->mapDashboardPricing($listing);

        return sendSuccess('Occupancy fetched', [
            'existing_bookings' => $existing,
            'listing_hours'     => $listingHours,
            'pricing_cells'     => $pricingCells,
            'pricing_groups'    => $pricingGroups,
            'add_ons'           => $addOns,
            'listing'           => $this->mapDashboardListing($listing),
            'special_days'      => $this->mapDashboardSpecialDays($listing, $data['date']),
        ]);
    }

    public function dashboardStore(Request $request)
    {
        $data = $request->validate([
            'listing_id'     => 'required|exists:listings,id',
            'location_id'    => 'required|exists:venues,id',
            'customer_id'    => 'nullable|exists:users,id',
            'booking_date'   => 'required|date',
            'start_time'     => 'required',
            'end_time'       => 'required',
            'players'        => 'nullable|integer|min:1',
            'total'          => 'nullable|numeric|min:0',
            'discount'       => 'nullable|numeric|min:0',
            'payment_method' => 'required|string',
            'notes'          => 'nullable|string|max:1000',
            'promocode_id'   => 'nullable|exists:promocodes,id',
            'addon_ids'      => 'nullable|array',
            'addon_ids.*'    => 'exists:add_ons,id',
            'addons'         => 'nullable|array',
            'addons.*.id'    => 'required|exists:add_ons,id',
            'addons.*.quantity' => 'nullable|integer|min:1',
            'addons.*.unit_price' => 'nullable|numeric|min:0',
        ]);

        $listing = Listing::with('resourceRequirements')->findOrFail($data['listing_id']);
        $user    = $this->resolveDashboardCustomer($data['customer_id'] ?? null);
        $mapped  = $this->fromDashboardPaymentMethod($data['payment_method']);
        $total   = (float) ($data['total'] ?? 0);
        $discount = (float) ($data['discount'] ?? 0);
        $subtotal = max(0, $total + $discount);

        try {
            DB::beginTransaction();

            // A court cannot hold two bookings at once however it was created. This
            // path inserted without checking at all, so staff could overbook a slot
            // the customer site had already sold. Under the listing lock, so a
            // simultaneous booking elsewhere cannot slip in between check and insert.
            // Only PHYSICAL limits are enforced — schedule and holiday rules stay
            // overridable by staff, as before.
            $conflict = $this->capacityGuard(
                $listing->id, $data['booking_date'], $data['start_time'], $data['end_time']
            );
            if ($conflict) {
                DB::rollBack();

                return sendError($conflict, ['capacity' => $conflict], 422);
            }

            $startMins = $this->timeToMinutes($this->fmtTime($data['start_time']));
            $endMins   = $this->timeToMinutes($this->fmtTime($data['end_time']));
            $durationHours = $endMins > $startMins ? (int) round(($endMins - $startMins) / 60) : 1;

            $addonRows = $this->normalizeDashboardAddons($data);
            $addonTotal = collect($addonRows)->sum(fn($r) => $r['subtotal']);
            $basePrice  = max(0, $subtotal - $addonTotal);

            $booking = $this->bookingModel::create([
                'user_id'               => $user->id,
                'venue_id'              => $data['location_id'],
                'listing_id'            => $listing->id,
                'date'                  => $data['booking_date'],
                'start_time'            => $data['start_time'],
                'end_time'              => $data['end_time'],
                'duration'              => max(1, $durationHours),
                'subtotal'              => $subtotal,
                'discount_amount'       => $discount,
                'total_price'           => $total,
                'payment_method'        => $mapped['method'],
                'payment_status'        => $mapped['status'],
                // Was hardcoded CONFIRMED, which confirmed "Pay on Arrival"
                // bookings before a fil had been collected. The rule is the
                // same everywhere now: confirmed only when nothing is owed.
                'status'                => initialBookingStatus($mapped['status'], (float) $total),
                'notes'                 => $data['notes'] ?? '',
                'players'               => (int) ($data['players'] ?? 1),
                'origin'                => 'manual_admin',
                'promocode_id'          => $data['promocode_id'] ?? null,
                'loyalty_points_earned' => $listing->loyalty_points ?? 0,
                'full_payment_received' => $mapped['status'] === BookingPaymentStatus::FULLY_PAID,
                'is_free'               => $mapped['status'] === BookingPaymentStatus::PAYMENT_SKIPPED,
                'created_by'            => auth()->id(),
                'payment_breakdown'     => [
                    'base_price'         => $basePrice,
                    'addon_total'        => $addonTotal,
                    'commission_amount'  => 0,
                ],
            ]);

            if ($addonRows) {
                $sync = [];
                foreach ($addonRows as $row) {
                    $sync[$row['id']] = [
                        'quantity'   => $row['quantity'],
                        'unit_price' => $row['unit_price'],
                        'subtotal'   => $row['subtotal'],
                    ];
                }
                $booking->addOns()->sync($sync);
            }

            if (! empty($data['promocode_id'])) {
                Promocode::where('id', $data['promocode_id'])->increment('used_count');
            }

            if ($mapped['status'] !== BookingPaymentStatus::PAYMENT_SKIPPED && $total > 0) {
                $this->paymentModel::create([
                    // The currency this money is in — without it the row reports as
                    // "Unknown currency" on the Payments page.
                    'currency'   => $this->bookingCurrency($booking),
                    'user_id'    => $user->id,
                    'booking_id' => $booking->id,
                    'type'       => PaymentType::FULL,
                    'amount'     => $total,
                    'payment_id' => generatePaymentId($booking->id, PaymentType::FULL),
                    'date'       => $mapped['status'] === BookingPaymentStatus::FULLY_PAID ? now()->format('Y-m-d') : null,
                    'status'     => $mapped['status'] === BookingPaymentStatus::FULLY_PAID ? PaymentStatus::PAID : PaymentStatus::PENDING,
                    'method'     => $mapped['pay_method'],
                    'notes'      => $mapped['status'] === BookingPaymentStatus::FULLY_PAID
                        ? "Full payment for booking #{$booking->booking_id}"
                        : "Payment pending for booking #{$booking->booking_id}",
                ]);
            }

            if (($listing->loyalty_points ?? 0) > 0) {
                $user->addLoyaltyPoints(
                    (int) $listing->loyalty_points,
                    $booking->id,
                    "Loyalty points earned for booking #{$booking->booking_id}"
                );
            }

            $booking->history()->create([
                'user_id'     => auth()->id(),
                'action'      => 'booking_created',
                'description' => 'Booking created in admin dashboard by ' . (auth()->user()?->name ?? 'staff'),
            ]);

            DB::commit();

            $booking->load(['user', 'listing', 'venue', 'payments']);

            return sendSuccess('Booking created', [
                'booking' => $this->mapDashboardBooking($booking),
            ], 201);
        } catch (Exception $e) {
            DB::rollBack();
            Log::error('Dashboard booking create failed: ' . $e->getMessage(), ['Exception' => $e]);

            return sendError('Failed to create booking: ' . $e->getMessage());
        }
    }

    public function dashboardUpdate(Request $request, $id)
    {
        $booking = $this->bookingModel::findOrFail($id);
        $data = $request->validate([
            'location_id'    => 'sometimes|exists:venues,id',
            'listing_id'     => 'sometimes|exists:listings,id',
            'customer_id'    => 'sometimes|nullable|exists:users,id',
            'booking_date'   => 'sometimes|date',
            'start_time'     => 'sometimes',
            'end_time'       => 'sometimes',
            'total'          => 'sometimes|numeric|min:0',
            'booking_status' => 'sometimes|in:pending,confirmed,completed,cancelled,no_show',
            'payment_method' => 'sometimes|string',
            'notes'          => 'nullable|string|max:1000',
            'players'        => 'sometimes|integer|min:1',
        ]);

        $payload = [];
        if (array_key_exists('location_id', $data)) {
            $payload['venue_id'] = $data['location_id'];
        }
        if (array_key_exists('listing_id', $data)) {
            $payload['listing_id'] = $data['listing_id'];
        }
        if (array_key_exists('customer_id', $data)) {
            $payload['user_id'] = $data['customer_id'] ?: $booking->user_id;
        }
        if (array_key_exists('booking_date', $data)) {
            $payload['date'] = $data['booking_date'];
        }
        if (array_key_exists('start_time', $data)) {
            $payload['start_time'] = $data['start_time'];
        }
        if (array_key_exists('end_time', $data)) {
            $payload['end_time'] = $data['end_time'];
        }
        if (array_key_exists('total', $data)) {
            $payload['total_price'] = $data['total'];
            $payload['subtotal']    = $data['total'];
        }
        if (array_key_exists('booking_status', $data)) {
            $payload['status'] = $data['booking_status'] === 'no_show' ? BookingStatus::COMPLETED : $data['booking_status'];
        }
        if (array_key_exists('payment_method', $data)) {
            $mapped = $this->fromDashboardPaymentMethod($data['payment_method']);
            $payload['payment_method'] = $mapped['method'];
        }
        if (array_key_exists('notes', $data)) {
            $payload['notes'] = $data['notes'] ?? '';
        }
        if (array_key_exists('players', $data)) {
            $payload['players'] = $data['players'];
        }

        $booking->update($payload);
        $booking->load(['user', 'listing', 'venue', 'payments', 'promocode']);

        return sendSuccess('Booking updated', [
            'booking' => $this->mapDashboardBooking($booking),
        ]);
    }

    public function dashboardCancel(Request $request)
    {
        $data = $request->validate([
            'booking_id'    => 'required',
            'program_id'    => 'nullable',
            'booking_date'  => 'nullable|date',
            'cancel_all'    => 'sometimes|boolean',
            'cancel_reason' => 'nullable|string|max:255',
        ]);

        if (! empty($data['cancel_all']) && ! empty($data['program_id'])) {
            $query = $this->bookingModel::query()
                ->where('program_id', $data['program_id'])
                ->where('status', '!=', BookingStatus::CANCELLED);
            if (! empty($data['booking_date'])) {
                $query->whereDate('date', '>=', $data['booking_date']);
            }
            $ids = $query->pluck('id');
            foreach ($ids as $id) {
                $req = new Request([
                    'booking_id'    => $id,
                    'cancel_reason' => $data['cancel_reason'] ?? 'Cancelled in admin dashboard',
                ]);
                $this->cancel($req);
            }

            return sendSuccess('Bookings cancelled');
        }

        $req = new Request([
            'booking_id'    => $data['booking_id'],
            'cancel_reason' => $data['cancel_reason'] ?? 'Cancelled in admin dashboard',
        ]);

        return $this->cancel($req);
    }

    public function dashboardCollect(Request $request, $id, $paymentId)
    {
        $data = $request->validate([
            'method'       => 'required|string',
            'amount'       => 'nullable|numeric|min:0',
            'discount'     => 'nullable|numeric|min:0',
            'promocode_id' => 'nullable|exists:promocodes,id',
        ]);

        $booking = $this->bookingModel::with('payments')->find($id);
        if (! $booking) {
            return sendError('Booking not found', [], 404);
        }

        $payment = $booking->payments()->find($paymentId);
        if (! $payment) {
            return sendError('Payment not found', [], 404);
        }

        if ($payment->status === PaymentStatus::PAID) {
            return sendError('This payment is already marked as paid.');
        }

        try {
            DB::beginTransaction();

            $discount = (float) ($data['discount'] ?? 0);
            if ($discount > 0) {
                $payment->amount = max(0, (float) $payment->amount - $discount);
                $booking->discount_amount = (float) $booking->discount_amount + $discount;
                $booking->total_price = max(0, (float) $booking->total_price - $discount);
                if (! empty($data['promocode_id'])) {
                    $booking->promocode_id = $data['promocode_id'];
                    Promocode::where('id', $data['promocode_id'])->increment('used_count');
                }
            }

            $payMethod = match ($data['method']) {
                'card', 'credit_card', 'paid_card' => PaymentMethod::CREDIT_CARD,
                'cheque', 'paid_cheque'            => PaymentMethod::CHEQUE,
                'wire', 'wire_transfer', 'paid_wire' => PaymentMethod::WIRE_TRANSFER,
                'online'                           => PaymentMethod::ONLINE,
                default                            => PaymentMethod::CASH,
            };

            $payment->status = PaymentStatus::PAID;
            $payment->method = $payMethod;
            $payment->date   = now()->format('Y-m-d');
            $payment->paid_at = now();
            $payment->marked_paid_by = auth()->id();
            $payment->recipient = $payMethod === PaymentMethod::ONLINE ? '@bookboll' : 'provider';
            $payment->save();

            $remaining = $booking->payments()
                ->where('id', '!=', $payment->id)
                ->where('status', '!=', PaymentStatus::PAID)
                ->exists();

            $booking->payment_status        = $remaining ? BookingPaymentStatus::DEPOSIT_PAID : BookingPaymentStatus::FULLY_PAID;
            $booking->full_payment_received = ! $remaining;
            $booking->save();

            $booking->history()->create([
                'user_id'     => auth()->id(),
                'action'      => 'payment_collected',
                'description' => 'Payment collected in admin dashboard via ' . $data['method'],
            ]);

            // Bookings now start pending whenever money is owed, so collecting
            // the last outstanding payment is what confirms them. Without this
            // a cash-on-arrival booking would stay pending after being paid.
            autoConfirmBookingIfPaid($booking);
            $booking->refresh();

            DB::commit();

            $booking->load(['user', 'listing', 'venue', 'payments', 'promocode']);

            $auditLogs = AuditLog::query()
                ->where('resource', 'booking')
                ->where('resource_id', (string) $booking->id)
                ->orderBy('timestamp')
                ->get()
                ->map(fn(AuditLog $log) => [
                    'id'         => (string) $log->id,
                    'action'     => $log->action,
                    'details'    => $log->details,
                    'actor'      => $log->actor_email,
                    'created_at' => $log->timestamp?->toIso8601String() ?: $log->created_at?->toIso8601String(),
                ])->values();

            return sendSuccess('Payment collected', [
                'booking'    => $this->mapDashboardBooking($booking),
                'payments'   => $booking->payments->map(fn(Payment $p) => $this->mapDashboardPayment($p, $booking->venue ? $this->venueCurrency($booking->venue) : null))->values(),
                'audit_logs' => $auditLogs,
            ]);
        } catch (Exception $e) {
            DB::rollBack();
            Log::error('Dashboard payment collect failed: ' . $e->getMessage(), ['Exception' => $e]);

            return sendError('Failed to collect payment: ' . $e->getMessage());
        }
    }

    protected function resolveDashboardCustomer($id): User
    {
        if ($id) {
            return User::findOrFail($id);
        }

        $email = 'walkin@bookboll.local';
        $user  = User::where('email', $email)->first();
        if ($user) {
            if (! $user->hasRole('customer')) {
                $user->assignRole('customer');
            }

            return $user;
        }

        $user = User::create([
            'name'     => 'Walk-in customer',
            'email'    => $email,
            'password' => Hash::make(Str::random(32)),
            'status'   => User::ACTIVE,
        ]);
        $user->assignRole('customer');

        return $user;
    }

    protected function mapDashboardSpecialDays(Listing $listing, string $date)
    {
        return SpecialDay::with('timeBlocks')
            ->whereDate('date', $date)
            ->where(function ($q) use ($listing) {
                $q->where('venue_id', $listing->venue_id);
                $q->orWhere('listing_id', $listing->id);
            })
            ->get()
            ->flatMap(fn(SpecialDay $sd) => $this->expandSpecialDayRow($sd))
            ->values();
    }

    protected function mapDashboardOtherPending(Booking $current)
    {
        return Payment::query()
            ->where('status', PaymentStatus::PENDING)
            ->where('booking_id', '!=', $current->id)
            ->whereHas('booking', fn($q) => $q->where('status', '!=', BookingStatus::CANCELLED))
            ->with(['booking.venue.users', 'booking.listing'])
            ->latest('id')
            ->limit(80)
            ->get()
            ->map(function (Payment $p) {
                $b   = $p->booking;
                $loc = $b?->venue;
                $ownerId = $loc?->relationLoaded('users')
                    ? ($loc->users->first()?->id ?? ($loc->created_by ?? null))
                    : ($loc->created_by ?? null);
                $date = $b?->date;
                if ($date instanceof \Carbon\CarbonInterface) {
                    $date = $date->format('Y-m-d');
                }
                $type = (string) $p->type;
                if ($type === PaymentType::FULL) {
                    $type = 'booking';
                } elseif ($type === PaymentType::REMAINING) {
                    $type = 'remaining';
                }

                return [
                    'id'            => (string) $p->id,
                    'booking_id'    => $b ? (string) $b->id : null,
                    'customer_id'   => $b && $b->user_id ? (string) $b->user_id : null,
                    'amount'        => (float) $p->amount,
                    'currency'      => '',
                    'payment_type'  => $type,
                    'booking_date'  => $date,
                    'created_at'    => $b?->created_at?->toIso8601String() ?: $p->created_at?->toIso8601String(),
                    'location_id'   => $b && $b->venue_id ? (string) $b->venue_id : null,
                    'owner_user_id' => $ownerId ? (string) $ownerId : null,
                    'venue'         => $loc?->name ?: '—',
                    'listingName'   => $b?->listing?->name ?: '',
                ];
            })
            ->values();
    }

    /**
     * Picker data for the bookings screens.
     *
     * `$withCustomers` is OFF by default and deliberately so: the customer list is
     * 15k+ rows and made up 99% of this endpoint's payload, while the table only ever
     * needed it to print a name — which the booking row now carries itself. The
     * new-booking drawer and the detail modal still ask for it explicitly.
     */
    protected function dashboardLookups(bool $withCustomers = false): array
    {
        $venueCols = ['id', 'name', 'status'];
        foreach (['city_id', 'country_id', 'created_by', 'currency_override'] as $col) {
            if (Schema::hasColumn('venues', $col)) {
                $venueCols[] = $col;
            }
        }

        // Scoped like every other admin surface. These pickers were unscoped, so a
        // provider opening the new-booking drawer saw EVERY venue and listing on the
        // platform — other businesses' names, courts and prices — and could pick one.
        $venueQuery = Venue::with('users')->orderBy('name');
        AccessScope::applyVenue($venueQuery, 'id', auth()->user());
        $venues = $venueQuery->get($venueCols);

        $locations = $venues->map(fn(Venue $v) => $this->mapDashboardLocation($v))->values();

        $listingQuery = Listing::with(['sportsCategory:id,name', 'addOns'])->orderBy('name');
        AccessScope::applyVenue($listingQuery, 'venue_id', auth()->user());
        $listings = $listingQuery
            ->get()
            ->map(fn(Listing $l) => $this->mapDashboardListing($l))
            ->values();

        $customers = ! $withCustomers ? collect() : User::role('customer')
            ->orderBy('name')
            ->get(['id', 'name', 'email', 'phone', 'status'])
            ->map(fn(User $u) => [
                'id'        => (string) $u->id,
                'full_name' => $u->name,
                'email'     => $u->email,
                'phone'     => $u->phone,
                'status'    => $u->status,
            ])->values();

        $cityIds = $venues->pluck('city_id')->filter()->unique()->values();
        $cities = ($cityIds->isEmpty() || ! class_exists(City::class))
            ? collect()
            : City::whereIn('id', $cityIds)->orderBy('name')->get(['id', 'name', 'country_id'])
            ->map(fn(City $c) => [
                'id'         => (string) $c->id,
                'name'       => $c->name,
                'country_id' => $c->country_id ? (string) $c->country_id : null,
                'status'     => 'active',
            ]);

        $countries = class_exists(Country::class)
            ? Country::with('currency')->orderBy('name')->get()
            ->filter(fn(Country $c) => (int) ($c->status ?? 1) === 1)
            ->map(fn(Country $c) => [
                'id'            => (string) $c->id,
                'name'          => $c->name,
                'currency_code' => $c->currency?->code ?: '',
                'status'        => 'active',
            ])->values()
            : collect();

        return compact('locations', 'listings', 'customers', 'cities', 'countries');
    }

    protected function mapDashboardBooking(Booking $b): array
    {
        $b->setAppends([]);
        $date = $b->date;
        if ($date instanceof \Carbon\CarbonInterface) {
            $date = $date->format('Y-m-d');
        }

        $paymentStatus = (string) $b->payment_status;
        if ($paymentStatus === BookingPaymentStatus::FULLY_PAID) {
            $paymentStatus = 'paid';
        } elseif ($paymentStatus === BookingPaymentStatus::PAYMENT_SKIPPED) {
            $paymentStatus = 'skipped';
        }
        if ($b->refunded_at) {
            $paymentStatus = 'refunded';
        }

        $breakdown = is_array($b->payment_breakdown) ? $b->payment_breakdown : [];
        $addonTotal = (float) ($breakdown['addon_total'] ?? 0);
        if ($addonTotal <= 0 && $b->relationLoaded('addOns')) {
            $addonTotal = (float) $b->addOns->sum(function ($a) {
                return (float) ($a->pivot->subtotal ?? $a->price ?? 0);
            });
        }
        $basePrice = (float) ($breakdown['base_price'] ?? max(0, ((float) ($b->subtotal ?? 0)) - $addonTotal));
        $amountPaid = $b->relationLoaded('payments')
            ? (float) $b->payments->where('status', PaymentStatus::PAID)->sum('amount')
            : 0;
        if ($b->payment_status === BookingPaymentStatus::FULLY_PAID && $amountPaid <= 0) {
            $amountPaid = (float) ($b->total_price ?? 0);
        }

        return [
            'id'             => (string) $b->id,
            // The desk's "Pay by QR" link is public, so it carries the uuid rather
            // than the sequential id — see BookingPayLinkService.
            'uuid'           => $b->uuid,
            'booking_id'     => $b->booking_id,
            'customer_id'    => $b->user_id ? (string) $b->user_id : null,
            'listing_id'     => $b->listing_id ? (string) $b->listing_id : null,
            'location_id'    => $b->venue_id ? (string) $b->venue_id : null,
            'booking_date'   => $date,
            'start_time'     => $this->fmtTime($b->start_time),
            'end_time'       => $this->fmtTime($b->end_time),
            'players'        => (int) ($b->players ?? 1),
            'base_price'     => $basePrice,
            'addon_total'    => $addonTotal,
            'total'          => (float) ($b->total_price ?? 0),
            'discount'       => (float) ($b->discount_amount ?? 0),
            'amount_paid'    => $amountPaid,
            'notes'          => $b->notes ?: '',
            'booking_status' => $b->status,
            'payment_status' => $paymentStatus,
            'payment_method' => $this->toDashboardPaymentMethod($b->payment_method),
            'origin'             => $b->origin ?: 'bookboll_web',
            'program_id'         => $b->program_id ? (string) $b->program_id : null,
            'commission_amount'  => (float) ($breakdown['commission_amount'] ?? 0),
            'created_at'         => $b->created_at?->toIso8601String(),
            'updated_at'         => $b->updated_at?->toIso8601String(),
        ];
    }

    protected function mapDashboardListing(?Listing $l): ?array
    {
        if (! $l) {
            return null;
        }

        $mins = $l->slotDurationMinutes();
        if ($mins < 15) {
            $mins = 60;
        }

        return [
            'id'             => (string) $l->id,
            'name'           => $l->name,
            'status'         => $l->status,
            'location_id'    => $l->venue_id ? (string) $l->venue_id : null,
            'base_price'     => (float) ($l->base_price ?? 0),
            'capacity'       => (int) ($l->capacity ?? 99),
            'slot_duration'  => $mins,
            'buffer_time'    => 0,
            'booking_color'  => $l->calendar_color ?: '#64748b',
            'listing_type'   => 'court',
            'sport_category' => $l->sportsCategory?->name,
            'online_payment' => (bool) ($l->online_payment ?? false),
            'add_ons'        => $l->relationLoaded('addOns')
                ? $l->addOns->map(fn($a) => [
                    'id'     => (string) $a->id,
                    'name'   => $a->name,
                    'price'  => (float) $a->price,
                    'status' => $a->status ?? 'active',
                    'stock'  => null,
                ])->values()
                : [],
        ];
    }

    protected function mapDashboardLocation(?Venue $v): ?array
    {
        if (! $v) {
            return null;
        }

        $ownerId = $v->relationLoaded('users')
            ? ($v->users->first()?->id ?? ($v->created_by ?? null))
            : ($v->created_by ?? null);

        return [
            'id'                => (string) $v->id,
            'name'              => $v->name,
            'status'            => $v->status,
            'city_id'           => isset($v->city_id) && $v->city_id ? (string) $v->city_id : null,
            'country_id'        => isset($v->country_id) && $v->country_id ? (string) $v->country_id : null,
            'owner_user_id'     => $ownerId ? (string) $ownerId : null,
            'currency_override' => $v->currency_override ?: null,
        ];
    }

    protected function mapDashboardPayment(Payment $p, ?string $currencyFallback = null): array
    {
        $status = (string) $p->status;
        if ($status === PaymentStatus::PAID) {
            $status = 'paid';
        } elseif (in_array($status, [PaymentStatus::PENDING, PaymentStatus::UNPAID], true)) {
            $status = 'pending';
        }

        $type = (string) $p->type;
        if ($type === PaymentType::FULL) {
            $type = 'booking';
        } elseif ($type === PaymentType::REMAINING) {
            $type = 'remaining';
        }

        $paidAt = $p->date;
        if ($paidAt instanceof \Carbon\CarbonInterface) {
            $paidAt = $paidAt->toIso8601String();
        } elseif (is_string($paidAt) && $paidAt !== '') {
            $paidAt = $paidAt . 'T00:00:00';
        }

        return [
            'id'           => (string) $p->id,
            'booking_id'   => $p->booking_id ? (string) $p->booking_id : null,
            'payment_type' => $type,
            'method'       => $this->toDashboardPaymentMethod($p->method),
            'amount'       => (float) $p->amount,
            // Was hardcoded 'AED' — every payment on a booking read as AED no
            // matter what was actually charged. The payment row carries the real
            // currency; rows predating that column fall back to the booking's
            // venue, and to nothing rather than a code nobody was charged.
            'currency'     => $p->currency ?: ($currencyFallback ?: ''),
            'status'       => $status,
            'paid_at'      => $status === 'paid' ? $paidAt : null,
            'created_at'   => $p->created_at?->toIso8601String(),
        ];
    }

    protected function toDashboardPaymentMethod(?string $method): string
    {
        return match ($method) {
            BookingPaymentMethod::PAID_CASH,
            BookingPaymentMethod::CASH_ON_ARRIVAL,
            PaymentMethod::CASH, 'cash', 'paid_cash' => 'cash',
            BookingPaymentMethod::PAID_CREDIT_CARD,
            PaymentMethod::CREDIT_CARD, 'card', 'paid_card' => 'card',
            BookingPaymentMethod::PAID_CHEQUE,
            PaymentMethod::CHEQUE, 'cheque', 'paid_cheque' => 'cheque',
            BookingPaymentMethod::PAID_WIRE_TRANSFER,
            PaymentMethod::WIRE_TRANSFER, 'wire_transfer', 'paid_wire' => 'wire_transfer',
            BookingPaymentMethod::ONLINE_PAYMENT,
            PaymentMethod::ONLINE, 'online' => 'online',
            BookingPaymentMethod::PAYMENT_SKIPPED, 'payment_skipped' => 'payment_skipped',
            default => $method ?: '',
        };
    }

    protected function fromDashboardPaymentMethod(string $method): array
    {
        return match ($method) {
            'payment_skipped' => [
                'method'     => BookingPaymentMethod::PAYMENT_SKIPPED,
                'status'     => BookingPaymentStatus::PAYMENT_SKIPPED,
                'pay_method' => PaymentMethod::CASH,
            ],
            'paid_cash' => [
                'method'     => BookingPaymentMethod::PAID_CASH,
                'status'     => BookingPaymentStatus::FULLY_PAID,
                'pay_method' => PaymentMethod::CASH,
            ],
            'paid_cheque' => [
                'method'     => BookingPaymentMethod::PAID_CHEQUE,
                'status'     => BookingPaymentStatus::FULLY_PAID,
                'pay_method' => PaymentMethod::CHEQUE,
            ],
            'paid_card' => [
                'method'     => BookingPaymentMethod::PAID_CREDIT_CARD,
                'status'     => BookingPaymentStatus::FULLY_PAID,
                'pay_method' => PaymentMethod::CREDIT_CARD,
            ],
            'paid_wire' => [
                'method'     => BookingPaymentMethod::PAID_WIRE_TRANSFER,
                'status'     => BookingPaymentStatus::FULLY_PAID,
                'pay_method' => PaymentMethod::WIRE_TRANSFER,
            ],
            'cash' => [
                'method'     => BookingPaymentMethod::CASH_ON_ARRIVAL,
                'status'     => BookingPaymentStatus::PENDING,
                'pay_method' => PaymentMethod::CASH,
            ],
            default => [
                'method'     => BookingPaymentMethod::CASH_ON_ARRIVAL,
                'status'     => BookingPaymentStatus::PENDING,
                'pay_method' => PaymentMethod::CASH,
            ],
        };
    }

    protected function fmtTime($value): ?string
    {
        if (! $value) {
            return null;
        }
        if ($value instanceof \Carbon\CarbonInterface) {
            return $value->format('H:i');
        }
        $s = (string) $value;
        if (strlen($s) >= 5) {
            return substr($s, 0, 5);
        }

        return $s;
    }

    public function dashboardSpecialDays(Request $request)
    {
        $data = $request->validate([
            'location_id'  => 'nullable',
            'location_ids' => 'nullable|string',
            'listing_id'   => 'nullable',
            'date'         => 'nullable|date',
            'from'         => 'nullable|date',
            'to'           => 'nullable|date',
        ]);

        $venueIds = [];
        if (! empty($data['location_id'])) {
            $venueIds[] = $data['location_id'];
        }
        if (! empty($data['location_ids'])) {
            $venueIds = array_merge($venueIds, explode(',', $data['location_ids']));
        }
        $venueIds = array_values(array_filter($venueIds));

        $query = SpecialDay::with('timeBlocks');
        if ($venueIds) {
            $query->whereIn('venue_id', $venueIds);
        }
        if (! empty($data['listing_id'])) {
            $query->where(function ($q) use ($data) {
                $q->whereNull('listing_id')->orWhere('listing_id', $data['listing_id']);
            });
        }
        if (! empty($data['date'])) {
            $query->whereDate('date', $data['date']);
        } else {
            if (! empty($data['from'])) {
                $query->whereDate('date', '>=', $data['from']);
            }
            if (! empty($data['to'])) {
                $query->whereDate('date', '<=', $data['to']);
            }
        }

        $mapped = $query->get()->flatMap(fn(SpecialDay $sd) => $this->expandSpecialDayRow($sd))->values();

        return sendSuccess('Special days fetched', ['special_days' => $mapped]);
    }

    public function dashboardPaymentLink(Request $request)
    {
        return app(PaymentService::class)->generatePaymentLink($request);
    }

    protected function expandSpecialDayRow(SpecialDay $sd)
    {
        return app(SpecialDayService::class)->mapToDashboard($sd);
    }

    protected function mapDashboardPricing(Listing $listing): array
    {
        $dowMap = [
            'sunday' => 0,
            'monday' => 1,
            'tuesday' => 2,
            'wednesday' => 3,
            'thursday' => 4,
            'friday' => 5,
            'saturday' => 6,
        ];
        $slotMins = $listing->slotDurationMinutes();
        if ($slotMins < 15) {
            $slotMins = 60;
        }

        $groups = [];
        $cells  = [];
        foreach ($listing->pricingGroups as $g) {
            $groups[] = [
                'id'    => (string) $g->id,
                'name'  => $g->name,
                'price' => (float) $g->price_per_hour,
            ];
            $start = $this->timeToMinutes($this->fmtTime($g->start_time));
            $end   = $this->timeToMinutes($this->fmtTime($g->end_time));
            if ($end <= $start) {
                continue;
            }
            $days = is_array($g->days) ? $g->days : [];
            foreach ($days as $dayName) {
                $dow = $dowMap[strtolower((string) $dayName)] ?? null;
                if ($dow === null) {
                    continue;
                }
                for ($t = $start; $t + $slotMins <= $end; $t += $slotMins) {
                    $cells[] = [
                        'day_of_week'      => $dow,
                        'slot_time'        => sprintf('%02d:%02d', intdiv($t, 60), $t % 60),
                        'pricing_group_id' => (string) $g->id,
                        'direct_price'     => null,
                    ];
                }
            }
        }

        return [$cells, $groups];
    }

    protected function mapDashboardWallets(?Listing $listing): array
    {
        if (! $listing || ! $listing->online_payment) {
            return [];
        }

        return [[
            'id'            => 'listing-' . $listing->id,
            'purpose'       => 'bookings',
            'setup_status'  => 'active',
            'setup_method'  => 'stripe_connect',
            'payout_paused' => false,
            'is_current'    => true,
        ]];
    }

    protected function normalizeDashboardAddons(array $data): array
    {
        $rows = [];
        if (! empty($data['addons']) && is_array($data['addons'])) {
            foreach ($data['addons'] as $row) {
                $id  = $row['id'] ?? null;
                $qty = max(1, (int) ($row['quantity'] ?? 1));
                if (! $id) {
                    continue;
                }
                $addon = AddOn::find($id);
                $unit  = isset($row['unit_price']) ? (float) $row['unit_price'] : (float) ($addon?->price ?? 0);
                $rows[] = [
                    'id'         => $id,
                    'quantity'   => $qty,
                    'unit_price' => $unit,
                    'subtotal'   => $qty * $unit,
                ];
            }

            return $rows;
        }

        foreach ($data['addon_ids'] ?? [] as $id) {
            $addon = AddOn::find($id);
            $unit  = (float) ($addon?->price ?? 0);
            $rows[] = [
                'id'         => $id,
                'quantity'   => 1,
                'unit_price' => $unit,
                'subtotal'   => $unit,
            ];
        }

        return $rows;
    }

    protected function timeToMinutes(?string $time): int
    {
        if (! $time) {
            return 0;
        }
        if ($time === '24:00') {
            return 24 * 60;
        }
        [$h, $m] = array_pad(explode(':', $time), 2, 0);

        return ((int) $h) * 60 + (int) $m;
    }
}
