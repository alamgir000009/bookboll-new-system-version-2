<?php
namespace Database\Seeders;

use App\Models\Booking;
use App\Models\Payment;
use BookingStatus;
use Carbon\Carbon;
use Illuminate\Database\Seeder;
use PaymentStatus;

class PaymentTableSeeder extends Seeder
{
    public function run()
    {
        $bookings = Booking::all();

        foreach ($bookings as $booking) {
            $this->createPaymentsForBooking($booking);
        }
    }

    private function createPaymentsForBooking(Booking $booking)
    {
        $isConfirmed = $booking->status == BookingStatus::CONFIRMED;
        $isCancelled = $booking->status == BookingStatus::CANCELLED;

        $paymentDateBase    = Carbon::parse($booking->date . ' ' . $booking->start_time);
        $bookingTotalAmount = $booking->total_price;

        // If booking is cancelled, mark it as unpaid
        if ($isCancelled) {
            $this->createSinglePayment(
                $booking,
                'full',
                $bookingTotalAmount,
                'cash',
                PaymentStatus::UNPAID ?? 'unpaid',
                null
            );
            return;
        }

        match ($booking->payment_method) {

            'online_payment'               => $this->createSinglePayment(
                $booking,
                'full',
                $bookingTotalAmount,
                'online',
                $isConfirmed ? PaymentStatus::PAID : PaymentStatus::PENDING,
                $paymentDateBase->copy()->subDays(rand(0, 2))
            ),

            'deposit_remaining_on_arrival' => $this->createDepositPayments(
                $booking,
                $bookingTotalAmount,
                $isConfirmed,
                $paymentDateBase
            ),

            'cash_on_arrival'              => $this->createSinglePayment(
                $booking,
                'full',
                $bookingTotalAmount,
                'cash',
                $isConfirmed ? PaymentStatus::PAID : PaymentStatus::PENDING,
                $isConfirmed ? $paymentDateBase->copy()->addDays(rand(0, 1)) : $paymentDateBase
            ),

            default                        => $this->createSinglePayment(
                $booking,
                'full',
                $bookingTotalAmount,
                'cash',
                PaymentStatus::UNPAID,
                null
            )
        };
    }

    private function createDepositPayments(Booking $booking, $total, $isConfirmed, $paymentDateBase)
    {
        $depositAmount   = round($total * 0.3, 2);
        $remainingAmount = $total - $depositAmount;

        $this->createSinglePayment(
            $booking,
            'deposit',
            $depositAmount,
            'online',
            PaymentStatus::PAID,
            $paymentDateBase->copy()->subDay()
        );

        $remainingStatus = $isConfirmed
            ? (rand(0, 1) ? PaymentStatus::PAID : PaymentStatus::PENDING)
            : PaymentStatus::UNPAID;

        $this->createSinglePayment(
            $booking,
            'remaining',
            $remainingAmount,
            'cash',
            $remainingStatus,
            $remainingStatus == PaymentStatus::PAID ? $paymentDateBase : null
        );
    }

    private function createSinglePayment(Booking $booking, string $type, $amount, string $method, string $status, ?Carbon $paymentDate = null)
    {
        Payment::create([
            'payment_id' => generatePaymentId($booking->id, $type),
            'user_id'    => $booking->user_id,
            'booking_id' => $booking->id,
            'type'       => $type,
            'amount'     => $amount,
            'method'     => $method,
            'status'     => $status,
            'date'       => $paymentDate?->format('Y-m-d'),
            'notes'      => $status == PaymentStatus::PAID && $method == 'online'
                ? 'Online payment confirmed via QR' . rand(1000, 9999)
                : ($status == PaymentStatus::UNPAID ? 'No payment received' : null),
        ]);
    }
}
