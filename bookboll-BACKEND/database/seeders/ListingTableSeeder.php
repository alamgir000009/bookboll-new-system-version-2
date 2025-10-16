<?php
namespace Database\Seeders;

use App\Models\Listing;
use App\Models\Schedule;
use Illuminate\Database\Seeder;

class ListingTableSeeder extends Seeder
{
    public function run(): void
    {
        for ($i = 0; $i < 1; $i++) {
            $this->createListingData();
        }
    }

    private function createListingData()
    {
        $paymentOptionsJson = str_replace("'", '"', "{'cash_on_arrival':true,'deposit':{'amount':50.00,'percentage':50}}");
        $paymentOptions     = json_decode($paymentOptionsJson, true) ?: [];

        $scheduleJson = str_replace("'", '"', "[{'day':'monday','is_open':true,'from_time':'06:00','to_time':'22:00'},{'day':'tuesday','is_open':true,'from_time':'06:00','to_time':'22:00'},{'day':'wednesday','is_open':true,'from_time':'06:00','to_time':'22:00'},{'day':'thursday','is_open':true,'from_time':'06:00','to_time':'22:00'},{'day':'friday','is_open':true,'from_time':'06:00','to_time':'22:00'},{'day':'saturday','is_open':true,'from_time':'06:00','to_time':'22:00'},{'day':'sunday','is_open':true,'from_time':'06:00','to_time':'22:00'}]");
        $schedule     = json_decode($scheduleJson, true) ?: [];

        $pricingGroupsJson = str_replace("'", '"', "[{'name':'Peak Time','start_time':'18:00','end_time':'22:00','price_per_hour':150.00,'days':['monday','tuesday','wednesday','thursday','friday','saturday']},{'name':'Off-Peak Time','start_time':'06:00','end_time':'18:00','price_per_hour':80.00,'days':['monday','tuesday','wednesday','thursday','friday','saturday']}]");
        $pricingGroups     = json_decode($pricingGroupsJson, true) ?: [];

        $imagesJson = str_replace("'", '"', "[]");
        $images     = json_decode($imagesJson, true) ?: [];

        $listingData = [
            "name"                  => "Listing 1",
            "venue_id"              => 1,
            "sports_type"           => "cricket",
            "status"                => "active",
            "base_price"            => 100.00,
            "capacity"              => 5,
            "slot_duration"         => 2,
            "loyalty_points"        => 5,
            "payment_options"       => $paymentOptions,
            "schedule"              => $schedule,
            "pricing_groups"        => $pricingGroups,
            "add_ons"               => [1, 2],
            "resources"             => [
                [
                    "id"       => 2,
                    "quantity" => 1,
                ],
            ],
            "description"           => "Premium cricket field",
            "max_bookings_per_slot" => 1,
            "calendar_color"        => "#0000FF",
            "images"                => $images,
        ];

        $listing = Listing::create([
            'name'                  => $listingData['name'],
            'venue_id'              => $listingData['venue_id'],
            'sports_type'           => $listingData['sports_type'],
            'status'                => $listingData['status'],
            'description'           => $listingData['description'] ?? null,
            'base_price'            => $listingData['base_price'],
            'max_bookings_per_slot' => $listingData['max_bookings_per_slot'] ?? 1,
            'capacity'              => $listingData['capacity'],
            'slot_duration'         => $listingData['slot_duration'],
            'loyalty_points'        => $listingData['loyalty_points'] ?? 0,
            'calendar_color'        => $listingData['calendar_color'] ?? '#0000FF',
            'payment_options'       => $listingData['payment_options'],
            'images_urls'            => $listingData['images'],
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
                        'venue_id'   => $listing->venue_id,
                        'listing_id' => $listing->id,
                        'day'        => $schedule['day'],
                        'is_open'    => true,
                        'from_time'  => $schedule['from_time'],
                        'to_time'    => $schedule['to_time'],
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
