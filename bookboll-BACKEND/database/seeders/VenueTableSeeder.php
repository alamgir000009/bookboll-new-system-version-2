<?php
namespace Database\Seeders;

use App\Models\Venue;
use Illuminate\Database\Seeder;

class VenueTableSeeder extends Seeder
{
    public function run(): void
    {
        $venue = [
            "name"                  => "My Venue",
            "status"                => "active",
            "maps_url"              => "https://maps.google.com/...",
            "description"           => "Great place",
            "min_advance_booking"   => "2",
            "max_bookings_per_slot" => "1",
            "schedule"              => [
                [
                    "day"       => "monday",
                    "is_open"   => "1",
                    "from_time" => "06:00",
                    "to_time"   => "23:00",
                ],
                [
                    "day"       => "tuesday",
                    "is_open"   => "1",
                    "from_time" => "06:00",
                    "to_time"   => "23:00",
                ],
                [
                    "day"       => "wednesday",
                    "is_open"   => "1",
                    "from_time" => "06:00",
                    "to_time"   => "23:00",
                ],
                [
                    "day"       => "thursday",
                    "is_open"   => "1",
                    "from_time" => "06:00",
                    "to_time"   => "23:00",
                ],
                [
                    "day"       => "friday",
                    "is_open"   => "1",
                    "from_time" => "06:00",
                    "to_time"   => "23:00",
                ],
                [
                    "day"       => "saturday",
                    "is_open"   => "1",
                    "from_time" => "06:00",
                    "to_time"   => "23:00",
                ],
                [
                    "day"       => "sunday",
                    "is_open"   => "1",
                    "from_time" => "06:00",
                    "to_time"   => "23:00",
                ],
            ],
        ];

        $schedules = $venue['schedule'];
        unset($venue['schedule']);

        $venue = Venue::create($venue);

        foreach ($schedules as $schedule) {
            $venue->schedules()->create($schedule);
        }
        $venue->load('schedules');

    }
}
