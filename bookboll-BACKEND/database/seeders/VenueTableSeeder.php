<?php
namespace Database\Seeders;

use App\Models\Venue;
use Illuminate\Database\Seeder;

class VenueTableSeeder extends Seeder
{
    public function run(): void
    {
        $days = ["monday", "tuesday", "wednesday", "thursday", "friday", "saturday", "sunday"];

        for ($i = 1; $i <= 5; $i++) {

            $venue = Venue::create([
                "status" => "active",
                "name"   => "My Venue {$i}",
                "max_bookings_per_slot" => $i,
                "min_advance_booking"   => rand(1, 10),
                "description"           => fake()->text(rand(100, 200)),
                "maps_url"              => "https://maps.app.goo.gl/j8bnWW1A5taQB9Rx6",
            ]);

            foreach ($days as $day) {
                for ($slot = 0; $slot < 5; $slot++) {
                    $startMinutes = $slot * 30;
                    $startHour    = floor($startMinutes / 60);
                    $startMin     = $startMinutes % 60;
                    $startTime    = sprintf('%02d:%02d', $startHour, $startMin);

                    $endMinutes = $startMinutes + 30;
                    $endHour    = floor($endMinutes / 60);
                    $endMin     = $endMinutes % 60;
                    $endTime    = sprintf('%02d:%02d', $endHour, $endMin);

                    $venue->schedules()->create([
                        "day"        => $day,
                        "is_open"    => true,
                        "start_time" => $startTime,
                        "end_time"   => $endTime,
                    ]);
                }
            }
        }
    }
}
