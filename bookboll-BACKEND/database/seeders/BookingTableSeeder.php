<?php
namespace Database\Seeders;

use App\Models\AddOn;
use App\Models\Booking;
use App\Models\Listing;
use App\Models\Promocode;
use App\Models\User;
use App\Models\Venue;
use BookingStatus;
use Carbon\Carbon;
use Illuminate\Database\Seeder;

class BookingTableSeeder extends Seeder
{
    public function run(): void
    {
        for ($i = 0; $i < 2; $i++) {
            $this->createBookingData();
        }
    }

    private function createBookingData()
    {
        $venues     = Venue::limit(2)->get();
        $addOns     = AddOn::limit(4)->get();
        $listings   = Listing::limit(3)->get();
        $promocodes = Promocode::active()->limit(2)->get();
        $users      = User::where('id', '>', 5)->get();

        for ($i = 0; $i < 10; $i++) {

            $bookingDate = Carbon::create(2025, 10, rand(10, 30));
            $user        = $users->random();
            $venue       = $venues->random();
            $listing     = $listings->random();

            $startTime = Carbon::createFromTime(rand(8, 18), 0, 0);
            $duration  = rand(1, 3);
            $endTime   = (clone $startTime)->addHours($duration);

            $basePrice = $listing->base_price ?? 150;
            $subtotal  = $basePrice * $duration;

            $selectedAddOns = $addOns->random(rand(0, 2))->pluck('id')->toArray();
            $addOnCost      = AddOn::whereIn('id', $selectedAddOns)->sum('price');
            $subtotal += $addOnCost;

            $promocode  = rand(0, 1) ? $promocodes->random() : null;
            $discount   = $promocode ? $promocode->applyDiscount($subtotal) : 0;
            $totalPrice = $subtotal - $discount;

            $statusOptions = BookingStatus::list();
            $status        = collect($statusOptions)->random();

            $paymentStatus = collect(['pending', 'deposit_paid', 'fully_paid'])->random();
            $paymentMethod = collect([
                'cash_on_arrival',
                'online_payment',
                'deposit_remaining_on_arrival',
            ])->random();

            $depositAmount   = $paymentStatus == 'deposit_paid' ? round($totalPrice * 0.3, 2) : 0;
            $remainingAmount = $paymentStatus == 'deposit_paid' ? $totalPrice - $depositAmount : 0;
            $depositMethod   = $paymentStatus == 'deposit_paid' ? 'online_payment' : null;
            $remainingMethod = $paymentStatus == 'deposit_paid' ? null : null;

            $paymentBreakdown = [
                'deposit_amount'   => $depositAmount,
                'deposit_method'   => $depositMethod,
                'remaining_amount' => $remainingAmount,
                'remaining_method' => $remainingMethod,
            ];

            $booking = Booking::create([
                'user_id'               => $user->id,
                'venue_id'              => $venue->id,
                'listing_id'            => $listing->id,
                'promocode_id'          => $promocode?->id,
                'date'                  => $bookingDate->format('Y-m-d'),
                'start_time'            => $startTime->format('H:i:s'),
                'end_time'              => $endTime->format('H:i:s'),
                'duration'              => $duration,
                'subtotal'              => $subtotal,
                'discount_amount'       => $discount,
                'total_price'           => $totalPrice,
                'full_payment_received' => $paymentStatus == 'fully_paid',
                'notes'                 => fake()->sentence(),
                'status'                => $status,
                'payment_status'        => $paymentStatus,
                'payment_method'        => $paymentMethod,
                'loyalty_points_earned' => rand(5, 15),
                'payment_breakdown'     => $paymentBreakdown,
            ]);

            if (! empty($selectedAddOns)) {
                $booking->addOns()->sync($selectedAddOns);
            }

            $booking->history()->create([
                'action'      => 'booking_created',
                'description' => 'Booking created via seeder.',
                'user_id'     => $booking->user_id,
            ]);

            if ($paymentStatus == 'deposit_paid') {
                $booking->history()->create([
                    'action'      => 'deposit_paid',
                    'description' => 'Deposit payment processed (auto-generated).',
                    'user_id'     => $booking->user_id,
                ]);
            }
        }
    }
}
