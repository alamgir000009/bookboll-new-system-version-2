<?php
namespace Database\Seeders;

use App\Models\SpecialDay;
use Carbon\Carbon;
use Illuminate\Database\Seeder;

class SpecialDayTableSeeder extends Seeder
{
    public function run(): void
    {
        $venueId   = 1;
        $listingId = 1;

        $specialDays = [
            [
                'venue_id'    => $venueId,
                'listing_id'  => $listingId,
                'date'        => Carbon::parse('2025-10-20'),
                'type'        => 'holiday',
                'description' => 'New Year Holiday - No bookings',
                'status'      => 'active',
                'time_blocks' => [],
            ],
            [
                'venue_id'    => $venueId,
                'listing_id'  => null,
                'date'        => Carbon::parse('2025-09-20'),
                'type'        => 'special',
                'description' => 'Weekend Special Pricing',
                'status'      => 'active',
                'time_blocks' => [
                    [
                        'start_time'     => '18:00',
                        'end_time'       => '22:00',
                        'price_per_hour' => 300.00,
                    ],
                    [
                        'start_time'     => '12:00',
                        'end_time'       => '15:00',
                        'price_per_hour' => 250.00,
                    ],
                ],
            ],
            [
                'venue_id'    => $venueId,
                'listing_id'  => $listingId,
                'date'        => Carbon::parse('2025-10-25'),
                'type'        => 'special',
                'description' => 'End of Month Discount',
                'status'      => 'inactive',
                'time_blocks' => [
                    [
                        'start_time'     => '09:00',
                        'end_time'       => '12:00',
                        'price_per_hour' => 200.00,
                    ],
                ],
            ],
        ];

        foreach ($specialDays as $specialDayData) {

            $timeBlocks = $specialDayData['time_blocks'] ?? [];
            unset($specialDayData['time_blocks']);

            $specialDay = SpecialDay::create($specialDayData);

            foreach ($timeBlocks as $timeBlock) {
                $specialDay->timeBlocks()->create([
                    'start_time'     => $timeBlock['start_time'],
                    'end_time'       => $timeBlock['end_time'],
                    'price_per_hour' => $timeBlock['price_per_hour'],
                ]);
            }
        }
    }
}
