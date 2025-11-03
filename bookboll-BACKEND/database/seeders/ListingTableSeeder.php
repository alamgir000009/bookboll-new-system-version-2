<?php
namespace Database\Seeders;

use App\Models\Listing;
use App\Models\Schedule;
use App\Models\SportsCategory;
use Illuminate\Database\Seeder;

class ListingTableSeeder extends Seeder
{
    public function run(): void
    {
        for ($i = 0; $i < 5; $i++) {
            $this->createListingData($i);
        }
    }

    private function createListingData($occurance)
    {
        $paymentOptionsJson = str_replace("'", '"', "{'cash_on_arrival':true,'deposit':{'amount':50.00,'percentage':50}}");
        $paymentOptions     = json_decode($paymentOptionsJson, true) ?: [];

        $scheduleJson = str_replace("'", '"', "[{'day':'monday','is_open':true,'start_time':'06:00','end_time':'22:00'},{'day':'tuesday','is_open':true,'start_time':'06:00','end_time':'22:00'},{'day':'wednesday','is_open':true,'start_time':'06:00','end_time':'22:00'},{'day':'thursday','is_open':true,'start_time':'06:00','end_time':'22:00'},{'day':'friday','is_open':true,'start_time':'06:00','end_time':'22:00'},{'day':'saturday','is_open':true,'start_time':'06:00','end_time':'22:00'},{'day':'sunday','is_open':true,'start_time':'06:00','end_time':'22:00'}]");
        $schedule     = json_decode($scheduleJson, true) ?: [];

        $pricingGroupsJson = str_replace("'", '"', "[{'name':'Peak Time','start_time':'18:00','end_time':'22:00','price_per_hour':150.00,'days':['monday','tuesday','wednesday','thursday','friday','saturday']},{'name':'Off-Peak Time','start_time':'06:00','end_time':'18:00','price_per_hour':80.00,'days':['monday','tuesday','wednesday','thursday','friday','saturday']}]");
        $pricingGroups     = json_decode($pricingGroupsJson, true) ?: [];

        $imagesJson = str_replace("'", '"', "[]");
        $images     = json_decode($imagesJson, true) ?: ["listings\/PUOwZqD4YP02yHEABiiPMAQlnSSJNznhc4LsHgxC.jpg", "listings\/bJolKVSg7KcTqFP5w7uQEa1G2u2mvLn5kaUMvYwJ.jpg"];

        $listingData = [
            "name" => "Listing {$occurance}",
            "venue_id"              => 1,
            "slot_duration"         => 2,
            "base_price"            => 100.00,
            "status"                => "active",
            "schedule"              => $schedule,
            "capacity"              => $occurance,
            "loyalty_points"        => $occurance,
            "pricing_groups"        => $pricingGroups,
            "payment_options"       => $paymentOptions,
            "add_ons"               => [1, 2],
            "resources"             => [
                [
                    "id"       => 2,
                    "quantity" => rand(1,3),
                ],
            ],
            "images"                => $images,
            "calendar_color"        => "#0000FF",
            "max_bookings_per_slot" => $occurance,
            "amenities"             => ['Wi-Fi', 'Parking', 'AC'],
            "sports_category_id"    => SportsCategory::inRandomOrder()->value('id'),
            "description"           => "This is the testing description for the lesting number {$occurance}",
        ];

        $listing = Listing::create([
            'name'                  => $listingData['name'],
            'status'                => $listingData['status'],
            'images_urls'           => $listingData['images'],
            'venue_id'              => $listingData['venue_id'],
            'capacity'              => $listingData['capacity'],
            'amenities'             => $listingData['amenities'],
            'base_price'            => $listingData['base_price'],
            'slot_duration'         => $listingData['slot_duration'],
            'payment_options'       => $listingData['payment_options'],
            'sports_category_id'    => $listingData['sports_category_id'],
            'loyalty_points'        => $listingData['loyalty_points'] ?? 0,
            'description'           => $listingData['description'] ?? null,
            'max_bookings_per_slot' => $listingData['max_bookings_per_slot'] ?? 1,
            'calendar_color'        => $listingData['calendar_color'] ?? '#0000FF',
        ]);

        if (! empty($listingData['add_ons'])) {
            $addOnIds = array_map('intval', $listingData['add_ons']);
            $listing->addOns()->sync($addOnIds);
        }

        if (! empty($listingData['resources'])) {
            $syncData = [];
            foreach ($listingData['resources'] as $resource) {
                $syncData[intval($resource['id'])] = ['quantity' => intval($resource['quantity'])];
            }
            $listing->resources()->sync($syncData);
        }

        if (! empty($listingData['schedule']) && is_array($listingData['schedule'])) {
            foreach ($listingData['schedule'] as $schedule) {
                if (isset($schedule['is_open']) && $schedule['is_open']) {
                    Schedule::create([
                        'is_open'    => true,
                        'listing_id' => $listing->id,
                        'day'        => $schedule['day'],
                        'venue_id'   => $listing->venue_id,
                        'end_time'   => $schedule['end_time'],
                        'start_time' => $schedule['start_time'],
                    ]);
                }
            }
        }

        if (! empty($listingData['pricing_groups']) && is_array($listingData['pricing_groups'])) {
            foreach ($listingData['pricing_groups'] as $groupData) {
                $groupData['price_per_hour'] = (float) $groupData['price_per_hour'];
                $listing->pricingGroups()->create($groupData);
            }
        }
    }
}
