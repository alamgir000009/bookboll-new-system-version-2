<?php
namespace App\Services;

use App\Models\Booking;
use App\Models\Payment;
use Barryvdh\DomPDF\Facade\Pdf;
use BookingPaymentStatus;
use CompnayInfo;
use Exception;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use PaymentMethod;
use PaymentStatus;
use PaymentType;
use Stripe\Checkout\Session;
use Stripe\PaymentIntent;
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
            ->when($from && $to, function ($query) use ($from, $to) {
                $query->whereBetween('created_at', [$from, $to]);
            });

        $statsQuery = clone $query;

        $stats = [
            'total_amount'   => $statsQuery->sum('amount'),
            'paid_amount'    => (clone $statsQuery)->where('status', 'paid')->sum('amount'),
            'pending_amount' => (clone $statsQuery)->where('status', 'pending')->sum('amount'),
        ];

        $payments = $query->paginate($records_per_page);

        return sendSuccess('Payments fetched successfully', compact('payments', 'stats'));
    }

    public function getDashboardTotals(): array
    {
        $total   = $this->paymentModel::sum('amount');
        $paid    = $this->paymentModel::where('status', 'paid')->sum('amount');
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
            ];

            Stripe::setApiKey(config('services.stripe.secret'));

            $paymentIntent = PaymentIntent::create([
                'currency'             => 'qar',
                'payment_method_types' => ['card'],
                'metadata'             => $metadata,
                'amount'               => $totalAmount * 100,
                'description'          => "Payment for Booking #{$booking->id} + due amounts",
            ]);

            $session = Session::create([
                'payment_method_types' => ['card'],
                'line_items'           => [[
                    'quantity'   => 1,
                    'price_data' => [
                        'currency'     => 'qar',
                        'unit_amount'  => $totalAmount * 100,
                        'product_data' => ['name' => "Payment for Booking #{$booking->id}"],
                    ],
                ]],
                'mode'        => 'payment',
                'metadata'    => $metadata,
                'cancel_url'  => url('/payment/cancel'),
                'success_url' => url('/payment/success/{CHECKOUT_SESSION_ID}'),
            ]);

            return sendSuccess('Linkg generated successfully', [
                'link'               => $session->url,
                'total_amount'       => $totalAmount,
                'payment_intent_id'  => $paymentIntent->id,
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
        $dueIds      = collect($selectedDueBookings ?? [])->pluck('id');
        $dueBookings = $this->bookingModel::whereIn('id', $dueIds)
            ->where('user_id', $booking->user_id)
            ->where('venue_id', $booking->venue_id)
            ->whereIn('payment_status', ['pending', 'deposit_paid'])
            ->where('status', '!=', 'cancelled')
            ->where('id', '!=', $booking->id)
            ->get();

        if ($dueBookings->count() !== $dueIds->count()) {
            throw new Exception('Invalid selected due bookings');
        }

        return $dueBookings;
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
}
