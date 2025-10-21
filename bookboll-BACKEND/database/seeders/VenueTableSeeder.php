<?php
namespace Database\Seeders;

use App\Models\Venue;
use Illuminate\Database\Seeder;

class VenueTableSeeder extends Seeder
{
    public function run(): void
    {
        $schedules = [
            [
                "day"       => "monday",
                "is_open"   => "1",
                "start_time" => "06:00",
                "end_time"   => "23:00",
            ],
            [
                "day"       => "tuesday",
                "is_open"   => "1",
                "start_time" => "06:00",
                "end_time"   => "23:00",
            ],
            [
                "day"       => "wednesday",
                "is_open"   => "1",
                "start_time" => "06:00",
                "end_time"   => "23:00",
            ],
            [
                "day"       => "thursday",
                "is_open"   => "1",
                "start_time" => "06:00",
                "end_time"   => "23:00",
            ],
            [
                "day"       => "friday",
                "is_open"   => "1",
                "start_time" => "06:00",
                "end_time"   => "23:00",
            ],
            [
                "day"       => "saturday",
                "is_open"   => "1",
                "start_time" => "06:00",
                "end_time"   => "23:00",
            ],
            [
                "day"       => "sunday",
                "is_open"   => "1",
                "start_time" => "06:00",
                "end_time"   => "23:00",
            ],
        ];

        for ($i = 1; $i <= 5; $i++) {

            $venue = Venue::create([
                "status" => "active",
                "name"   => "My Venue {$i}",
                "max_bookings_per_slot" => $i,
                "min_advance_booking"   => rand(1, 10),
                "description" => fake()->text(rand(100, 200)),
                "maps_url"              => "https://maps.app.goo.gl/p9w3zANwkfhS8HQX{$i}",
            ]);

            foreach ($schedules as $schedule) {
                $venue->schedules()->create($schedule);
            }
        }

    }
}
