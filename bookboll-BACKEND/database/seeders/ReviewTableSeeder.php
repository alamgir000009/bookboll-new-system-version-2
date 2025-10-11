<?php

namespace Database\Seeders;

use BookingStatus;
use Illuminate\Database\Seeder;
use App\Models\Review;
use App\Models\Booking;
use Faker\Factory as Faker;

class ReviewTableSeeder extends Seeder
{
    public function run(): void
    {
        $faker = Faker::create();

        $bookings = Booking::where('status', BookingStatus::COMPLETED)->with('user')->get();

        foreach ($bookings as $booking) {
            Review::updateOrCreate(
                [
                    'user_id' => $booking->user_id,
                    'booking_id' => $booking->id,
                ],
                [
                    'listing_id'         => $booking->listing_id,
                    'overall_rating'     => $faker->numberBetween(3, 5),
                    'cleanliness_rating' => $faker->numberBetween(1, 5),
                    'facilities_rating'  => $faker->numberBetween(1, 5),
                    'staff_rating'       => $faker->numberBetween(1, 5),
                    'value_rating'       => $faker->numberBetween(1, 5),
                    'review_text'        => $faker->sentence(12),
                    'recommended'        => $faker->boolean(80),
                    'helpful_count'      => $faker->numberBetween(0, 20),
                ]
            );
        }
    }
}
