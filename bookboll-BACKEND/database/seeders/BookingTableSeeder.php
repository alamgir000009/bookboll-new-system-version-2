<?php
namespace Database\Seeders;

use App\Models\AddOn;
use App\Models\Booking;
use App\Models\Listing;
use App\Models\Promocode;
use App\Models\User;
use App\Models\Venue;
use Carbon\Carbon;
use Illuminate\Database\Seeder;

class BookingTableSeeder extends Seeder
{
    public function run(): void
    {
        $currentDate = Carbon::parse('2025-09-21');

        $users      = User::limit(5)->get();
        $venues     = Venue::limit(2)->get();
        $listings   = Listing::limit(3)->get();
        $promocodes = Promocode::active()->limit(2)->get();
        $addOns     = AddOn::limit(4)->get();

        for ($i = 0; $i < 10; $i++) {
            $user      = $users->random();
            $venue     = $venues->random();
            $listing   = $listings->random();
            $startTime = $currentDate->copy()->addDays(rand(0, 30))->setHour(rand(8, 18))->setMinute(0)->setSecond(0);
            $duration  = rand(1, 3);
            $endTime   = $startTime->copy()->addHours($duration);

            $subtotal = $listing->base_price * $duration;

            $selectedAddOns = $addOns->random(rand(0, 2))->pluck('id')->toArray();
            $addOnCost      = 0;
            foreach ($selectedAddOns as $addOnId) {
                $addOn = AddOn::find($addOnId);
                $addOnCost += $addOn->price;
            }

            $subtotal += $addOnCost;

            $promocode = rand(0, 1) ? $promocodes->random() : null;
            $discount  = $promocode ? $promocode->applyDiscount($subtotal) : 0;

            $totalPrice = $subtotal - $discount;

            $booking = Booking::create([
                'user_id'         => $user->id,
                'venue_id'        => $venue->id,
                'listing_id'      => $listing->id,
                'promocode_id'    => $promocode?->id,
                'start_time'      => $startTime,
                'end_time'        => $endTime,
                // 'duration'        => $duration,
                'subtotal'        => $subtotal,
                'discount_amount' => $discount,
                'total_price'     => $totalPrice,
                'payment_method'  => ['cash_on_arrival', 'online', 'deposit'][rand(0, 2)],
                'notes'           => 'Sample note ' . rand(1, 100),
                'status'          => ['pending', 'confirmed', 'cancelled'][rand(0, 2)],
            ]);

            $booking->addOns()->sync($selectedAddOns);
        }
    }
}
