<?php
namespace App\Services;

use App\Models\Booking;
use App\Models\Payment;
use Barryvdh\DomPDF\Facade\Pdf;
use CompnayInfo;
use Exception;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use SimpleSoftwareIO\QrCode\Facades\QrCode;
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

    public function createInitialPayments(Booking $booking)
    {
        $depositAmount   = 0;
        $remainingAmount = $booking->total_price;

        match ($booking->payment_method) {
            'online'          => [
                $this->createPayment($booking, 'full', $booking->total_price, 'online', 'paid'), // Full online
                $booking->update(['status' => \BookingStatus::CONFIRMED, 'full_payment_received' => true]),
            ],
            'deposit'         => [
                $depositAmount = $booking->total_price * 0.3, // Example: 30% deposit; make configurable
                $remainingAmount = $booking->total_price - $depositAmount,
                $this->createPayment($booking, 'deposit', $depositAmount, 'online', 'paid'),      // Assume deposit paid online
                $this->createPayment($booking, 'remaining', $remainingAmount, 'cash', 'pending'), // Default cash on arrival
                $booking->update(['status' => \BookingStatus::CONFIRMED]),                        // Confirmed after deposit
            ],
            'cash_on_arrival' => [
                $this->createPayment($booking, 'full', $booking->total_price, 'cash', 'pending'),
            ],
            default           => throw new \Exception('Invalid payment method'),
        };
    }

    private function createPayment(Booking $booking, string $type, float $amount, string $method, string $status)
    {
        $paymentId = $this->generatePaymentId($booking->id, $type);

        return $this->paymentModel::create([
            'payment_id' => $paymentId,
            'booking_id' => $booking->id,
            'type'       => $type,
            'amount'     => $amount,
            'method'     => $method,
            'status'     => $status,
            'date'       => now()->format('Y-m-d'),
            'notes'      => $type === 'deposit' ? 'Online deposit' : null,
        ]);
    }

    private function generatePaymentId(int $bookingId, string $type): string
    {
        $typeAbbr = match ($type) {
            'deposit'   => 'DEP',
            'remaining' => 'REM',
            'full'      => '',
            default     => 'FULL',
        };
        return 'PAY-' . $bookingId . '-' . $typeAbbr;
    }

    public function confirmRemainingPayment(Payment $payment, array $data): void
    {
        if ($payment->type !== 'remaining' || $payment->status === 'paid') {
            throw new \Exception('Invalid payment for confirmation');
        }

        $method = $data['method'] ?? 'cash';
        $notes  = $data['notes'] ?? null;

        if ($method === 'online') {
            $qrData = ['amount' => $payment->amount, 'booking_id' => $payment->booking_id, 'ref' => Str::uuid()];
            $qrPath = $this->generateQR($qrData);
            $notes .= ' QR Ref: ' . $qrData['ref'] . ' Path: ' . $qrPath;
        }

        $payment->update([
            'status' => 'paid',
            'notes'  => $notes,
            'method' => $method,
            'date'   => now()->format('Y-m-d'),
        ]);

        $this->checkAndUpdateBooking($payment->booking);
    }

    private function generateQR(array $data): string
    {
        $qrContent = json_encode($data);
        $path      = 'qr_codes/' . Str::uuid() . '.svg';
        QrCode::size(200)->generate($qrContent, storage_path('app/public/' . $path));
        return $path;
    }

    private function checkAndUpdateBooking(Booking $booking): void
    {
        if ($booking->isFullyPaid()) {
            $booking->update([
                'status'                => \BookingStatus::CONFIRMED,
                'full_payment_received' => true,
            ]);
            // Optional: Award loyalty points here
        }
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
}
