<?php
namespace Database\Seeders;

use App\Models\User;
use Illuminate\Database\Seeder;

class LoyaltyPointTableSeeder extends Seeder
{
    public function run(): void
    {
        $users = User::where("id", ">", 5)->whereHas('bookings')->with('bookings')->get();

        foreach ($users as $user) {

            $booking = $user->bookings->random();

            $user->loyaltyPoints()->create([
                'booking_id' => $booking->id,
                'type'       => 'credit',
                'points'     => rand(1, 99),
                'reason'     => "Earned from booking #{$booking->id}",
            ]);
        }
    }
}
