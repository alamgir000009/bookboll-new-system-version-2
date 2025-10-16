<?php
namespace App\Services;

use App\Models\Booking;
use App\Models\Payment;
use Illuminate\Support\Str;
use SimpleSoftwareIO\QrCode\Facades\QrCode;

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
            ->when($search, function ($query, $search) {
                $query->where(function ($q) use ($search) {
                    $q->where('payment_id', 'LIKE', "%{$search}%")
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

        return Payment::create([
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
        $total   = Payment::sum('amount');
        $paid    = Payment::where('status', 'paid')->sum('amount');
        $pending = $total - $paid;

        return [
            'total_amount'   => $total,
            'paid_amount'    => $paid,
            'pending_amount' => $pending,
        ];
    }

    // For CSV export
    public function exportPaymentsCsv($filters = []): array
    {
        $query = Payment::with(['booking.user', 'booking.venue'])
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
}
