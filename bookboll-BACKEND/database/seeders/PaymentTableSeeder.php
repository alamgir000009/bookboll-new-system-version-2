<?php
namespace Database\Seeders;

use App\Models\Booking;
use App\Models\Payment;
use Carbon\Carbon;
use Illuminate\Database\Seeder;

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
        $isConfirmed     = $booking->status === 'confirmed';
        $paymentDateBase = $booking->start_time->copy();

        match ($booking->payment_method) {
            'online'  => $this->createSinglePayment(
                $booking,
                'full',
                $booking->total_price,
                'online',
                $isConfirmed ? 'paid' : 'pending',
                $paymentDateBase->copy()->subDays(rand(0, 2))
            ),
            'deposit' => [
                $depositAmount = round($booking->total_price * 0.3, 2),
                $remainingAmount = $booking->total_price - $depositAmount,

                $this->createSinglePayment(
                    $booking,
                    'deposit',
                    $depositAmount,
                    'online',
                    'paid',
                    $paymentDateBase->copy()->subDays(1)
                ),

                $remainingMethod = rand(0, 3) ? 'cash' : ['wire_transfer', 'cheque', 'online'][rand(0, 2)],
                $this->createSinglePayment(
                    $booking,
                    'remaining',
                    $remainingAmount,
                    $remainingMethod,
                    $isConfirmed ? 'paid' : 'pending',
                    $isConfirmed ? $paymentDateBase : null
                ),
            ],
            default   =>
            $this->createSinglePayment(
                $booking,
                'full',
                $booking->total_price,
                'cash',
                $isConfirmed ? 'paid' : 'pending',
                $isConfirmed ? $paymentDateBase->copy()->addDays(rand(0, 1)) : $paymentDateBase
            )
        };
    }

    private function createSinglePayment(Booking $booking, string $type, float $amount, string $method, string $status, ?Carbon $paymentDate = null)
    {
        Payment::create([
            'type'       => $type,
            'amount'     => $amount,
            'method'     => $method,
            'status'     => $status,
            'booking_id' => $booking->id,
            'user_id'    => $booking->user_id,
            'payment_id' => generatePaymentId($booking->id, $type),
            'date'       => $paymentDate ? $paymentDate->format('Y-m-d') : Carbon::today()->format('Y-m-d'),
            'notes'      => $status === 'paid' && $method === 'online' ? 'Online payment confirmed via QR' . rand(1000, 9999) : null,
        ]);
    }
}
