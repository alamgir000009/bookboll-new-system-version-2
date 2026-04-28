<?php
namespace App\Services;

use App\Models\Booking;
use App\Models\Payment;
use Barryvdh\DomPDF\Facade\Pdf;
use BookingPaymentMethod;
use BookingPaymentStatus;
use BookingStatus;
use CompnayInfo;
use Exception;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use PaymentMethod;
use PaymentStatus;
use PaymentType;
use Stripe\Stripe;

class PaymentService
{
    public function __construct(protected Payment $paymentModel, protected Booking $bookingModel)
    {}

    public function list($request)
    {
        $search           = $request->search ?? null;
        $method           = $request->method ?? null;
        $status           = $request->status ?? null;
        $to               = $request->to_date ?? null;
        $from             = $request->from_date ?? null;
        $records_per_page = $request->records_per_page ?? 10;

        $query = $this->paymentModel::with([
            'booking',
            'user:id,name,email',
            'booking.venue:id,name',
        ])
            ->latest()
            ->when($search, function ($query, $search) {
                $query->where(function ($q) use ($search) {
                    $q->where('type', 'LIKE', "%{$search}%")
                        ->orWhere('method', 'LIKE', "%{$search}%")
                        ->orWhere('amount', 'LIKE', "%{$search}%")
                        ->orWhere('booking_id', 'LIKE', "%{$search}%")
                        ->orWhere('payment_id', 'LIKE', "%{$search}%")
                        ->orWhereHas('booking.user', function ($sub) use ($search) {
                            $sub->where('name', 'LIKE', "%{$search}%")
                                ->orWhere('email', 'LIKE', "%{$search}%");
                        })
                        ->orWhereHas('booking.venue', function ($sub) use ($search) {
                            $sub->where('name', 'LIKE', "%{$search}%");
                        });
                });
            })
            ->when($method, function ($query, $method) {
                $query->where('method', $method);
            })
            ->when($status, function ($query, $status) {
                $query->where('status', $status);
            })
            ->when($from, fn($q, $from) => $q->whereDate('date', '>=', $from))
            ->when($to, fn($q, $to) => $q->whereDate('date', '<=', $to));

        $query = applyVenueFilterToPayments($query);

        $statsQuery = clone $query;

        $stats = [
            'total_amount'   => $statsQuery->sum('amount'),
            'paid_amount'    => (clone $statsQuery)->where('status', 'paid')->sum('amount'),
            'pending_amount' => (clone $statsQuery)->where('status', 'pending')->sum('amount'),
        ];

        $payments = $query->paginate($records_per_page);

        return sendSuccess('Payments fetched successfully', compact('payments', 'stats'));
    }

    public function getPaymentsForExport($request)
    {
        $search     = $request->search ?? null;
        $method     = $request->method ?? null;
        $status     = $request->status ?? null;
        $from_date  = $request->from_date ?? null;
        $to_date    = $request->to_date ?? null;
        $exportAll  = $request->export_all == 1 || $request->export_all === '1';

        $query = $this->paymentModel::with([
            'booking',
            'user:id,name,email',
            'booking.venue:id,name',
            'booking.listing:id,name',
        ])
            ->latest()
            ->when($search, function ($query, $search) {
                $query->where(function ($q) use ($search) {
                    $q->where('type', 'LIKE', "%{$search}%")
                        ->orWhere('method', 'LIKE', "%{$search}%")
                        ->orWhere('amount', 'LIKE', "%{$search}%")
                        ->orWhere('booking_id', 'LIKE', "%{$search}%")
                        ->orWhere('payment_id', 'LIKE', "%{$search}%")
                        ->orWhereHas('booking.user', function ($sub) use ($search) {
                            $sub->where('name', 'LIKE', "%{$search}%")
                                ->orWhere('email', 'LIKE', "%{$search}%");
                        })
                        ->orWhereHas('booking.venue', function ($sub) use ($search) {
                            $sub->where('name', 'LIKE', "%{$search}%");
                        });
                });
            })
            ->when($method, function ($query, $method) {
                $query->where('method', $method);
            })
            ->when($status, function ($query, $status) {
                $query->where('status', $status);
            })
            ->when($from_date && $to_date, function ($query) use ($from_date, $to_date) {
                $query->where(function ($q) use ($from_date, $to_date) {
                    $q->whereBetween('date', [$from_date, $to_date])
                        ->orWhere(function ($subQ) use ($from_date, $to_date) {
                            $subQ->whereNull('date')
                                ->whereBetween('created_at', [$from_date, $to_date]);
                        });
                });
            })
            ->when($from_date && !$to_date, function ($query) use ($from_date) {
                $query->where(function ($q) use ($from_date) {
                    $q->where('date', '>=', $from_date)
                        ->orWhere(function ($subQ) use ($from_date) {
                            $subQ->whereNull('date')
                                ->whereDate('created_at', '>=', $from_date);
                        });
                });
            })
            ->when($to_date && !$from_date, function ($query) use ($to_date) {
                $query->where(function ($q) use ($to_date) {
                    $q->where('date', '<=', $to_date)
                        ->orWhere(function ($subQ) use ($to_date) {
                            $subQ->whereNull('date')
                                ->whereDate('created_at', '<=', $to_date);
                        });
                });
            });

        $query = applyVenueFilterToPayments($query);

        if (!$exportAll) {
            $records_per_page = $request->records_per_page ?? 10;
            $page             = $request->page ?? 1;
            $query->skip(($page - 1) * $records_per_page)->take($records_per_page);
        }

        return $query->get();
    }

    public function getDashboardTotals(): array
    {
        $totalQuery = $this->paymentModel::newQuery();
        $paidQuery = $this->paymentModel::where('status', 'paid');

        $totalQuery = applyVenueFilterToPayments($totalQuery);
        $paidQuery = applyVenueFilterToPayments($paidQuery);

        $total   = $totalQuery->sum('amount');
        $paid    = $paidQuery->sum('amount');
        $pending = $total - $paid;

        return [
            'paid_amount'    => $paid,
            'total_amount'   => $total,
            'pending_amount' => $pending,
        ];
    }

    public function exportPaymentsCsv($filters = []): array
    {
        $query = $this->paymentModel::with(['booking.user', 'booking.venue'])
            ->search($filters['search'] ?? '')
            ->byMethod($filters['method'] ?? null)
            ->byStatus($filters['status'] ?? null)
            ->byDateRange($filters['date_from'] ?? null, $filters['date_to'] ?? null);

        $query = applyVenueFilterToPayments($query);

        $payments = $query->get()->map(fn($p) => [
            'Payment ID' => $p->payment_id,
            'Booking #'  => $p->booking->id,
            'Customer'   => $p->customer_name,
            'Venue'      => $p->venue_name,
            'Amount'     => $p->amount,
            'Method'     => ucfirst($p->method),
            'Status'     => ucfirst($p->status),
            'Date'       => $p->date->format('Y-m-d'),
        ]);

        return $payments->toArray();
    }

    public function downloadInvoice($id)
    {
        $payment = $this->paymentModel::with(['user', 'booking.venue'])->findOrFail($id);

        $data = [
            'payment' => $payment,
            'date'    => now()->format('Y-m-d'),
            'company' => [
                'name'    => CompnayInfo::NAME,
                'email'   => CompnayInfo::EMAIL,
                'phone'   => CompnayInfo::PHONE,
                'address' => CompnayInfo::ADDRESS,
            ],
        ];

        $pdf = Pdf::loadView('pdfs.payment-invoice', $data);

        return response()->streamDownload(function () use ($pdf) {
            echo $pdf->output();
        }, "invoice-{$payment->id}.pdf");
    }

    public function generatePaymentLink($request)
    {
        $validated = $request->validate([
            'booking_id'                     => 'required|exists:bookings,id',
            'amount'                         => 'sometimes|numeric|min:0',
            'selected_due_bookings'          => 'sometimes|array',
            'selected_due_bookings.*.id'     => 'sometimes|exists:bookings,id',
            'selected_due_bookings.*.amount' => 'sometimes|numeric|min:0',
        ]);

        try {

            $booking = $this->bookingModel::with('user')->findOrFail($validated['booking_id']);
            $user    = $booking->user;

            $totalAmount = $validated['amount'] ?? $this->calculateTotalAmount($booking, $validated['selected_due_bookings'] ?? []);

            $dueBookings = $this->validateDueBookings($booking, $validated['selected_due_bookings'] ?? []);

            $metadata = [
                'user_id'        => $user->id,
                'booking_id'     => $booking->id,
                'total_amount'   => $totalAmount,
                'payment_method' => 'online_payment',
                'due_bookings'   => json_encode($dueBookings->pluck('id')),
                'source'         => 'admin', // Admin-generated payment link (for QR codes, etc.)
            ];

            Stripe::setApiKey(config('services.stripe.secret'));

            // Use Checkout Session instead of PaymentLink for better control and consistency
            $user->createOrGetStripeCustomer();
            if (!$user->stripe_id) {
                throw new Exception('Failed to create Stripe customer');
            }

            $successUrl = url('/payment/success/{CHECKOUT_SESSION_ID}');
            $cancelUrl = url('/payment/cancel?session_id={CHECKOUT_SESSION_ID}');

            $session = \Stripe\Checkout\Session::create([
                'customer' => $user->stripe_id,
                'payment_method_types' => ['card'],
                'mode' => 'payment',
                'success_url' => $successUrl,
                'cancel_url' => $cancelUrl,
                'metadata' => $metadata,
                'line_items' => [[
                    'price_data' => [
                        'currency' => 'qar',
                        'unit_amount' => $totalAmount * 100,
                        'product_data' => [
                            'name' => "Payment for Booking #{$booking->id}",
                        ],
                    ],
                    'quantity' => 1,
                ]],
            ]);

            \App\Models\StripeSession::create([
                'session_id'  => $session->id,
                'booking_id'  => $booking->id,
                'booking_ids' => array_values(array_unique(array_filter(array_merge(
                    [$booking->id],
                    $dueBookings->pluck('id')->toArray()
                )))),
                'source'      => 'admin',
                'processed'   => false,
            ]);

            $paymentLink = (object) [
                'id' => $session->id,
                'url' => $session->url,
            ];

            return sendSuccess('Link generated successfully', [
                'link'               => $paymentLink->url,
                'total_amount'       => $totalAmount,
                'payment_link_id'    => $paymentLink->id,
                'due_bookings_count' => count($dueBookings),
            ]);

        } catch (Exception $e) {
            Log::error("Failed to generate payment link for booking #{$validated['booking_id']}: " . $e->getMessage(), [
                'request'   => $request->all(),
                'exception' => $e,
            ]);
            return sendError('Failed to generate payment link: ' . $e->getMessage());
        }
    }

    private function calculateTotalAmount($booking, $selectedDueBookings)
    {
        $pending = ($booking->payment_status === 'deposit_paid' ? $booking->payment_breakdown['remaining_amount'] : $booking->total_price) ?? 0;
        $dueSum  = 0;
        if ($selectedDueBookings) {
            foreach ($selectedDueBookings as $due) {
                $dueSum += $due['amount'] ?? 0;
            }
        }
        return $pending + $dueSum;
    }

    private function validateDueBookings($booking, $selectedDueBookings)
    {
        $dueIds = collect($selectedDueBookings ?? [])->pluck('id');

        if ($dueIds->isEmpty()) {
            return collect();
        }

        $allDueBookings = $this->bookingModel::whereIn('id', $dueIds)->get();

        $foundIds = $allDueBookings->pluck('id');
        $missingIds = $dueIds->diff($foundIds);

        if ($missingIds->isNotEmpty()) {
            throw new Exception("Bookings not found: " . $missingIds->implode(', '));
        }

        $errors = [];
        $validBookings = collect();

        foreach ($allDueBookings as $dueBooking) {
            $bookingErrors = [];

            if ($dueBooking->id == $booking->id) {
                $bookingErrors[] = "Booking #{$dueBooking->id} is the same as the main booking";
            }

            if ($dueBooking->user_id !== $booking->user_id) {
                $bookingErrors[] = "Booking #{$dueBooking->id} belongs to a different user (User ID: {$dueBooking->user_id}, Expected: {$booking->user_id})";
            }

            if ($dueBooking->venue_id !== $booking->venue_id) {
                $bookingErrors[] = "Booking #{$dueBooking->id} belongs to a different venue (Venue ID: {$dueBooking->venue_id}, Expected: {$booking->venue_id})";
            }

            if (!in_array($dueBooking->payment_status, ['pending', 'deposit_paid'])) {
                $currentStatus = $dueBooking->payment_status ?? 'null';
                $bookingErrors[] = "Booking #{$dueBooking->id} has invalid payment status: '{$currentStatus}' (Expected: 'pending' or 'deposit_paid')";
            }

            if ($dueBooking->status === BookingStatus::CANCELLED) {
                $bookingErrors[] = "Booking #{$dueBooking->id} is cancelled";
            }

            if (!empty($bookingErrors)) {
                $errors[] = "Booking #{$dueBooking->id}: " . implode('; ', $bookingErrors);
            } else {
                $validBookings->push($dueBooking);
            }
        }

        if (!empty($errors)) {
            throw new Exception('Invalid selected due bookings. ' . implode(' | ', $errors));
        }

        return $validBookings;
    }

    public function confirmPayment($request)
    {
        $user = auth()->user();

        $formData = $request->validate([
            'booking_id'                     => 'required|exists:bookings,id',
            'total_amount'                   => 'required|numeric|min:0',
            'payment_method'                 => 'required|in:cash,wire,cheque',
            'selected_due_bookings'          => 'nullable|array',
            'selected_due_bookings.*.id'     => 'exists:bookings,id',
            'selected_due_bookings.*.amount' => 'numeric|min:0',
        ]);

        try {
            DB::beginTransaction();

            $mainBookingId   = $formData['booking_id'];
            $dueBookingsData = collect($formData['selected_due_bookings'] ?? []);

            $mainBookingForValidation = $this->bookingModel::findOrFail($mainBookingId);

            $dueTotal = 0;

            foreach ($dueBookingsData as $due) {
                $dueBooking = $this->bookingModel::findOrFail($due['id']);
                if ($dueBooking->user_id !== $mainBookingForValidation->user_id) {
                    return sendError("Due booking ({$dueBooking->booking_id}) belongs to different user.");
                }
                $dueTotal += $due['amount'];
            }

            $mainAmount = $formData['total_amount'] - $dueTotal;
            if ($mainAmount < 0) {
                return sendError('Invalid amounts: main amount cannot be negative');
            }

            $paymentMethodMap = [
                'cash'   => PaymentMethod::CASH,
                'cheque' => PaymentMethod::CHEQUE,
                'wire'   => PaymentMethod::WIRE_TRANSFER,
            ];

            $method = $paymentMethodMap[$formData['payment_method']];

            $allBookingsData = collect([$mainBookingId => ['amount' => $mainAmount]]);
            foreach ($dueBookingsData as $due) {
                $allBookingsData[$due['id']] = ['amount' => $due['amount']];
            }

            $mainBooking = null;
            foreach ($allBookingsData as $bookingId => $data) {
                $booking = $this->bookingModel::lockForUpdate()->findOrFail($bookingId);

                $paymentAmount = $data['amount'];
                $isMain        = ($bookingId == $mainBookingId);
                if ($isMain) {
                    $mainBooking = $booking;
                }

                $hasDeposit = $booking->payments()
                    ->where('type', PaymentType::DEPOSIT)
                    ->where('status', PaymentStatus::PAID)
                    ->exists();

                $paymentType = $hasDeposit ? PaymentType::REMAINING : PaymentType::FULL;

                $pendingPayment = $booking->payments()
                    ->where('status', PaymentStatus::PENDING)
                    ->whereIn('type', [PaymentType::REMAINING, PaymentType::FULL])
                    ->first();

                $notes = ($paymentType == PaymentType::REMAINING ? 'Remaining' : 'Full') . " payment for booking #{$booking->id}";

                if ($pendingPayment) {

                    $notes = str_replace('pending', 'paid via ' . strtoupper($formData['payment_method']), $pendingPayment->notes);

                    $pendingPayment->update([
                        'notes'  => $notes,
                        'method' => $method,
                        'status' => PaymentStatus::PAID,
                        'date'   => now()->format('Y-m-d'),
                    ]);

                }

                $bookingUpdates = [
                    'full_payment_received' => true,
                    'payment_status'        => BookingPaymentStatus::FULLY_PAID,
                ];

                if ($isMain && $booking->payment_method === 'deposit_remaining_on_arrival') {
                    $paymentBreakdown                     = $booking->payment_breakdown ?? [];
                    $paymentBreakdown['remaining_method'] = $formData['payment_method'];
                    $bookingUpdates['payment_breakdown']  = $paymentBreakdown;
                }

                $booking->update($bookingUpdates);

                if ($mainBooking) {
                    $mainBooking->history()->create([
                        'user_id'     => $user->id,
                        'action'      => 'payment_completed',
                        'description' => 'Payment completed via ' . strtoupper($formData['payment_method']),
                    ]);
                }

                if ($booking->payment_status === BookingPaymentStatus::FULLY_PAID) {
                    autoConfirmBookingIfPaid($booking);
                }
            }

            DB::commit();

            $booking = $mainBooking->load([
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
            ]);

            return sendSuccess('Payment confirmed successfully.', compact('booking'));

        } catch (Exception $e) {
            DB::rollBack();
            Log::error('Confirm payment failed: ' . $e->getMessage(), ['trace' => $e]);
            return sendError('Payment confirmation failed. Please try again.');
        }
    }

    public function customerPaymentMethods()
    {
        try {
            $user           = auth()->user();
            $paymentMethods = $user->paymentMethods()->orderBy('created_at', 'desc')->get();

            return sendSuccess("Payment methods fetched successfully", compact('paymentMethods'));
        } catch (Exception $e) {
            Log::error('Get Payment Methods Error: ' . $e->getMessage(), ["Exception" => $e]);
            return sendError("Something went wrong");
        }
    }

    public function markBookingAsPaid($request)
    {
        $validated = $request->validate([
            'booking_id'                     => 'required|exists:bookings,id',
            'payment_status'                 => 'required|in:paid_cash,paid_wire,paid_wire_transfer,paid_cheque,paid_credit_card',
            'selected_due_bookings'          => 'nullable|array',
            'selected_due_bookings.*.id'     => 'exists:bookings,id',
            'selected_due_bookings.*.amount' => 'numeric|min:0',
        ]);

        try {
            DB::beginTransaction();

            $mainBookingId   = $validated['booking_id'];
            $dueBookingsData = collect($validated['selected_due_bookings'] ?? []);
            $user            = auth()->user();

            $mainBookingForValidation = $this->bookingModel::findOrFail($mainBookingId);

            foreach ($dueBookingsData as $due) {
                $dueBooking = $this->bookingModel::findOrFail($due['id']);
                if ($dueBooking->user_id !== $mainBookingForValidation->user_id) {
                    DB::rollBack();
                    return sendError("Due booking ({$dueBooking->booking_id}) belongs to different user.");
                }
            }

            $paymentMethodMap = [
                'paid_cash'          => PaymentMethod::CASH,
                'paid_cheque'        => PaymentMethod::CHEQUE,
                'paid_wire'          => PaymentMethod::WIRE_TRANSFER,
                'paid_wire_transfer' => PaymentMethod::WIRE_TRANSFER,
                'paid_credit_card'   => PaymentMethod::CREDIT_CARD,
            ];

            $paymentMethod = $paymentMethodMap[$validated['payment_status']];
            $paymentMethodName = str_replace('paid_', '', str_replace('_', ' ', $validated['payment_status']));

            $allBookingIds = collect([$mainBookingId])->merge($dueBookingsData->pluck('id'))->unique();

            $mainBooking = null;
            $hasAnyPendingPayments = false;

            foreach ($allBookingIds as $bookingId) {
                $booking = $this->bookingModel::lockForUpdate()->findOrFail($bookingId);
                $isMain  = ($bookingId == $mainBookingId);

                if ($isMain) {
                    $mainBooking = $booking;
                }

                // Get ALL pending payments (deposit, remaining, full)
                $pendingPayments = $booking->payments()
                    ->where('status', PaymentStatus::PENDING)
                    ->get();

                if ($pendingPayments->isEmpty()) {
                    continue;
                }

                $hasAnyPendingPayments = true;

                // Mark all pending payments as paid
                foreach ($pendingPayments as $payment) {
                    $notes = $payment->notes ?? "Payment for booking #{$booking->id}";
                    $notes = str_replace('pending', 'paid via ' . $paymentMethodName, $notes);
                    $notes = str_replace('Deposit payment pending', 'Deposit payment paid via ' . $paymentMethodName, $notes);
                    $notes = str_replace('Remaining payment pending', 'Remaining payment paid via ' . $paymentMethodName, $notes);

                    $payment->update([
                        'notes'  => $notes,
                        'method' => $paymentMethod,
                        'status' => PaymentStatus::PAID,
                        'date'   => now()->format('Y-m-d'),
                    ]);
                }

                // Determine final payment status based on payment types
                $hasDepositPayment = $booking->payments()
                    ->where('type', PaymentType::DEPOSIT)
                    ->where('status', PaymentStatus::PAID)
                    ->exists();

                $hasRemainingPayment = $booking->payments()
                    ->where('type', PaymentType::REMAINING)
                    ->exists();

                $hasFullPayment = $booking->payments()
                    ->where('type', PaymentType::FULL)
                    ->where('status', PaymentStatus::PAID)
                    ->exists();

                $finalPaymentStatus = BookingPaymentStatus::FULLY_PAID;
                if ($hasDepositPayment && $hasRemainingPayment) {
                    // Check if remaining is also paid
                    $remainingPaid = $booking->payments()
                        ->where('type', PaymentType::REMAINING)
                        ->where('status', PaymentStatus::PAID)
                        ->exists();

                    if (!$remainingPaid) {
                        $finalPaymentStatus = BookingPaymentStatus::DEPOSIT_PAID;
                    }
                }

                $bookingUpdates = [
                    'full_payment_received' => ($finalPaymentStatus === BookingPaymentStatus::FULLY_PAID),
                    'payment_method'        => $validated['payment_status'],
                    'payment_status'        => $finalPaymentStatus,
                ];

                if ($isMain && $booking->payment_method === 'deposit_remaining_on_arrival') {
                    $paymentBreakdown                     = $booking->payment_breakdown ?? [];
                    $paymentBreakdown['remaining_method'] = str_replace('paid_', '', $validated['payment_status']);
                    $bookingUpdates['payment_breakdown']  = $paymentBreakdown;
                }

                $booking->update($bookingUpdates);

                if ($booking->payment_status === BookingPaymentStatus::FULLY_PAID) {
                    autoConfirmBookingIfPaid($booking);
                    if ($isMain) {
                        regenerateBookingInvoice($booking);
                    }
                }
            }

            if (!$hasAnyPendingPayments) {
                DB::rollBack();
                return sendError('No pending payments found for the selected bookings.');
            }

            if ($mainBooking) {
                $mainBooking->history()->create([
                    'user_id'     => $user->id,
                    'action'      => 'payment_completed',
                    'description' => 'All pending payments marked as paid via ' . $paymentMethodName . ($dueBookingsData->isNotEmpty() ? ' (including due bookings)' : ''),
                ]);
            }

            DB::commit();

            $booking = $mainBooking->load([
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
            ]);

            return sendSuccess('Booking payments updated successfully.', compact('booking'));

        } catch (Exception $e) {
            DB::rollBack();
            Log::error('Mark booking as paid failed: ' . $e->getMessage(), [
                'booking_id'     => $validated['booking_id'] ?? null,
                'payment_status' => $validated['payment_status'] ?? null,
                'trace'          => $e,
            ]);
            return sendError('Failed to update booking payments. Please try again.');
        }
    }

    public function createCheckoutSession(array $validated, $user)
    {
        try {
            DB::beginTransaction();

            Stripe::setApiKey(config('services.stripe.secret'));

            $user->createOrGetStripeCustomer();
            if (!$user->stripe_id) {
                throw new Exception('Failed to create Stripe customer');
            }

            $listing = \App\Models\Listing::with('pricingGroups')->findOrFail($validated['listing_id']);
            $weekday = strtolower(\Carbon\Carbon::parse($validated['date'])->format('l'));

            $specialDay = \App\Models\SpecialDay::with('timeBlocks')
                ->where('venue_id', $listing->venue_id)
                ->where(function ($q) use ($listing) {
                    $q->whereNull('listing_id')->orWhere('listing_id', $listing->id);
                })
                ->whereDate('date', $validated['date'])->active()->first();

            $basePrice = determineSlotPrice(
                $listing,
                $weekday,
                $validated['start_time'],
                $validated['end_time'],
                $specialDay
            );

            $addOnCost = !empty($validated['add_ons'])
                ? \App\Models\AddOn::whereIn('id', $validated['add_ons'])->sum('price')
                : 0;

            $singleSubtotal = round($basePrice + $addOnCost, 2);
            $baseSubtotal = round($singleSubtotal * $validated['quantity'], 2);

            $recurringSubtotal = 0;
            foreach ($validated['recurring_bookings'] ?? [] as $recurring) {
                $recurringAddOnCost = !empty($recurring['add_ons'])
                    ? \App\Models\AddOn::whereIn('id', $recurring['add_ons'])->sum('price')
                    : 0;
                $recurringSingleSubtotal = round($recurring['price'] + $recurringAddOnCost, 2);
                $recurringQuantity = $recurring['quantity'] ?? 1;
                $recurringSubtotal += $recurringSingleSubtotal * $recurringQuantity;
            }

            $combinedSubtotal = round($baseSubtotal + $recurringSubtotal, 2);

            $totalDiscount = 0;
            if (!empty($validated['promocode'])) {
                $promocode = \App\Models\Promocode::where('code', $validated['promocode'])->active()->first();
                if ($promocode) {
                    $isValid = $promocode->isValidForOrder(
                        $combinedSubtotal,
                        $user->id,
                        $validated['payment_method'],
                        $validated['venue_id'],
                        $validated['listing_id']
                    );
                    if ($isValid) {
                        $totalDiscount = $promocode->applyDiscount($combinedSubtotal);
                    }
                }
            }

            $giftCardBalanceUsed = 0;
            if (!empty($validated['use_gift_card_balance']) && $validated['use_gift_card_balance']) {
                $userGiftCardBalance = \App\Models\Transaction::balance($user->id) ?? 0;
                $requestedAmount = $validated['gift_card_balance_used'] ?? 0;
                if ($requestedAmount > 0 && $userGiftCardBalance > 0) {
                    $giftCardBalanceUsed = min($requestedAmount, $userGiftCardBalance, $combinedSubtotal - $totalDiscount);
                }
            }

            $totalPrice = round($combinedSubtotal - $totalDiscount - $giftCardBalanceUsed, 2);
            if ($totalPrice < 0) {
                $totalPrice = 0;
            }

            $paymentAmount = $totalPrice;
            $paymentType = 'full';

            if ($validated['payment_method'] === 'deposit_remaining_on_arrival') {
                $depositAmount = 0;
                if (!empty($listing->payment_options) && isset($listing->payment_options['deposit'])) {
                    $deposit = $listing->payment_options['deposit'];

                    $baseQuantity = $validated['quantity'] ?? 1;
                    $totalRecurringQuantity = 0;
                    foreach ($validated['recurring_bookings'] ?? [] as $recurring) {
                        $totalRecurringQuantity += $recurring['quantity'] ?? 1;
                    }
                    $totalBookings = $baseQuantity + $totalRecurringQuantity;

                    if (!empty($deposit['amount']) && $deposit['amount'] > 0) {
                        $depositAmount = $deposit['amount'] * $totalBookings;
                    } elseif (!empty($deposit['percentage']) && $deposit['percentage'] > 0) {
                        $depositAmount = floor($totalPrice * ($deposit['percentage'] / 100));
                    }
                }
                $paymentAmount = $depositAmount;
                $paymentType = 'deposit';
            }

            if ($totalPrice <= 0) {
                $paymentAmount = 0;
            }

            $bookingService = app(\App\Services\BookingService::class);
            $bookingRequest = new \Illuminate\Http\Request();
            $bookingRequest->merge(array_merge($validated, [
                'payment_method' => $validated['payment_method'],
                'payment_intent_id' => null,
                'user_id' => $user->id,
            ]));
            $bookingRequest->setUserResolver(function () use ($user) {
                return $user;
            });

            $bookingResult = $bookingService->createBooking($bookingRequest);

            if (!$bookingResult->getData()->success) {
                throw new Exception($bookingResult->getData()->message ?? 'Failed to create booking');
            }

            $createdBookings = $bookingResult->getData()->data->bookings ?? [];
            if (empty($createdBookings)) {
                throw new Exception('No bookings were created');
            }

            $allBookingIds = collect($createdBookings)->pluck('id')->toArray();
            $mainBookingId = $allBookingIds[0] ?? null;
            if (!$mainBookingId) {
                throw new Exception('Failed to get main booking ID');
            }

            if ($paymentAmount <= 0) {
                $this->markBookingsAsFullyPaidFree($createdBookings, $totalPrice, $totalDiscount);
                DB::commit();
                return sendSuccess('Booking confirmed successfully', [
                    'free_booking' => true,
                    'bookings' => $createdBookings,
                    'url' => null,
                ]);
            }

            if ($validated['payment_method'] === 'online_payment') {
                Booking::whereIn('id', $allBookingIds)->update([
                    'payment_status' => BookingPaymentStatus::PENDING,
                    'payment_method' => BookingPaymentMethod::ONLINE_PAYMENT,
                ]);
            }

            $successUrl = url('/payment/success/{CHECKOUT_SESSION_ID}');
            $cancelUrl = url('/payment/cancel?session_id={CHECKOUT_SESSION_ID}');

            $mainBooking = Booking::with(['venue:id,name', 'listing:id,name'])->find($mainBookingId);
            $venueName = $mainBooking?->venue?->name ?? $listing->venue?->name ?? 'Venue';
            $listingName = $mainBooking?->listing?->name ?? $listing->name ?? 'Facility';
            $bookingRef = $mainBooking?->booking_id ?? "BKN#{$mainBookingId}";

            $baseQuantity = $validated['quantity'] ?? 1;
            $totalRecurringQty = collect($validated['recurring_bookings'] ?? [])->sum('quantity');
            $totalSlots = $baseQuantity + $totalRecurringQty;

            $allBookings = Booking::whereIn('id', $allBookingIds)->orderBy('id')->get(['id', 'booking_id', 'date', 'start_time', 'end_time']);
            $slotLines = [];
            foreach ($allBookings as $b) {
                $d = \Carbon\Carbon::parse($b->date)->format('M j, Y');
                $startAmPm = $b->start_time ? \Carbon\Carbon::parse($b->start_time)->format('g:i A') : '';
                $endAmPm = $b->end_time ? \Carbon\Carbon::parse($b->end_time)->format('g:i A') : '';
                $t = trim($startAmPm . ($startAmPm && $endAmPm ? ' - ' : '') . $endAmPm);
                $slotLines[] = $t ? "{$d} {$t}" : $d;
            }
            $allSlotsSummary = implode('; ', $slotLines);

            $productName = $paymentType === 'deposit'
                ? "Deposit: {$listingName} @ {$venueName} ({$totalSlots} slots)"
                : "Payment: {$listingName} @ {$venueName} ({$totalSlots} slots)";

            $productDescription = trim(implode(' | ', array_filter([
                "Slots ({$totalSlots}): " . $allSlotsSummary,
                $validated['promocode'] ? "Promo: {$validated['promocode']}" : null,
                "Subtotal: QAR " . number_format($combinedSubtotal, 2),
                $totalDiscount > 0 ? "Discount: -QAR " . number_format($totalDiscount, 2) : null,
                "Total: QAR " . number_format($totalPrice, 2),
                $paymentType === 'deposit' ? "Deposit due: QAR " . number_format($paymentAmount, 2) : null,
            ])));
            $productDescription = strlen($productDescription) > 500 ? substr($productDescription, 0, 497) . '...' : $productDescription;

            $sessionData = [
                'customer' => $user->stripe_id,
                'payment_method_types' => ['card'],
                'mode' => 'payment',
                'success_url' => $successUrl,
                'cancel_url' => $cancelUrl,
                'metadata' => [
                    'user_id' => (string) $user->id,
                    'user_email' => $user->email ?? '',
                    'user_name' => $user->name ?? '',
                    'booking_id' => (string) $mainBookingId,
                    'booking_ref' => $bookingRef,
                    'all_booking_ids' => json_encode($allBookingIds),
                    'all_booking_refs' => $allBookings->pluck('booking_id')->filter()->implode(','),
                    'all_slots' => $allSlotsSummary,
                    'venue_id' => (string) ($listing->venue_id ?? ''),
                    'venue_name' => $venueName,
                    'listing_id' => (string) ($listing->id ?? ''),
                    'listing_name' => $listingName,
                    'payment_type' => $paymentType,
                    'payment_method' => $validated['payment_method'],
                    'payment_amount_qar' => (string) $paymentAmount,
                    'subtotal_qar' => (string) $combinedSubtotal,
                    'discount_qar' => (string) $totalDiscount,
                    'total_qar' => (string) $totalPrice,
                    'quantity' => (string) $totalSlots,
                    'promocode' => $validated['promocode'] ?? '',
                    'source' => 'customer',
                ],
                'line_items' => [[
                    'price_data' => [
                        'currency' => 'qar',
                        'unit_amount' => (int) round($paymentAmount * 100),
                        'product_data' => [
                            'name' => $productName,
                            'description' => $productDescription,
                            'metadata' => [
                                'booking_ref' => $bookingRef,
                                'venue' => $venueName,
                                'listing' => $listingName,
                                'slot_count' => (string) $totalSlots,
                                'slots' => strlen($allSlotsSummary) <= 500 ? $allSlotsSummary : substr($allSlotsSummary, 0, 497) . '...',
                            ],
                        ],
                    ],
                    'quantity' => 1,
                ]],
            ];

            $session = \Stripe\Checkout\Session::create($sessionData);

            \App\Models\StripeSession::create([
                'session_id'  => $session->id,
                'booking_id'  => $mainBookingId,
                'booking_ids' => $allBookingIds,
                'source'      => 'customer',
                'processed'   => false,
            ]);

            DB::commit();

            return sendSuccess('Checkout session created successfully', [
                'url' => $session->url,
                'session_id' => $session->id,
            ]);

        } catch (Exception $e) {
            DB::rollBack();
            Log::error('Create checkout session error: ' . $e->getMessage(), [
                'user_id' => $user->id,
                'validated' => $validated,
                'trace' => $e->getTraceAsString(),
            ]);
            throw $e;
        }
    }

    protected function markBookingsAsFullyPaidFree(array $createdBookings, float $totalPrice, float $totalDiscount): void
    {
        foreach ($createdBookings as $bookingItem) {
            $bookingId = $bookingItem instanceof Booking
                ? $bookingItem->id
                : (is_array($bookingItem) ? ($bookingItem['id'] ?? null) : ($bookingItem->id ?? null));
            $booking = $bookingItem instanceof Booking ? $bookingItem : Booking::find($bookingId);
            if (!$booking) {
                continue;
            }

            $booking->update([
                'payment_status' => BookingPaymentStatus::FULLY_PAID,
                'full_payment_received' => true,
                'payment_breakdown' => [
                    'deposit_amount'   => 0,
                    'deposit_method'   => null,
                    'remaining_amount' => 0,
                    'remaining_method' => null,
                ],
            ]);

            $booking->payments()->where('status', PaymentStatus::PENDING)->update([
                'status' => PaymentStatus::PAID,
                'date' => now()->format('Y-m-d'),
                'notes' => 'Free booking - fully covered by promocode',
            ]);

            $booking->history()->create([
                'user_id' => $booking->user_id,
                'action' => 'payment_received',
                'description' => 'Booking fully covered by promocode – no payment required',
            ]);

            $user = $booking->user;
            if ($user) {
                createBookingInvoiceWithNotification($booking, $user);
            }
            autoConfirmBookingIfPaid($booking);
        }
    }
}
