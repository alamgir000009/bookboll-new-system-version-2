<?php
namespace Database\Seeders;

use App\Models\Setting;
use Illuminate\Database\Seeder;

class SettingTableSeeder extends Seeder
{
    public function run(): void
    {
        $defaults = [
            // Stripe
            [
                'key'         => 'stripe_publishable_key',
                'value'       => '',
                'type'        => 'string',
                'description' =>
                'Stripe Publishable Key',
            ],
            [
                'key'         => 'stripe_secret_key',
                'value'       => null,
                'type'        => 'string',
                'encrypted'   => true,
                'description' => 'Stripe Secret Key',
            ],
            [
                'key'         => 'stripe_webhook_secret',
                'value'       => null,
                'type'        => 'string',
                'encrypted'   => true,
                'description' => 'Stripe Secret Key',
            ],

            // Booking
            [
                'key'         => 'min_advance_hours',
                'value'       => 2,
                'type'        => 'integer',
                'description' => 'Default minimum advance booking hours',
            ],
            [
                'key'         => 'max_per_slot',
                'value'       => 1,
                'type'        => 'integer',
                'description' => 'Default maximum bookings per slot',
            ],
            [
                'key'         => 'auto_confirm',
                'value'       => 1,
                'type'        => 'boolean',
                'description' => 'Auto-confirm new bookings',
            ],
            [
                'key'         => 'default_bookings_slot_lock_time',
                'value'       => 5,
                'type'        => 'integer',
                'description' => 'Listing slots will be locked once viewed by a customer for the following time',
            ],

            // Modules
            [
                'key'         => 'loyalty',
                'value'       => 1,
                'type'        => 'boolean',
                'description' => 'Enable loyalty system',
            ],
            [
                'key'         => 'special_days',
                'value'       => 1,
                'type'        => 'boolean',
                'description' => 'Enable special days',
            ],
            [
                'key'         => 'addons',
                'value'       => 0,
                'type'        => 'boolean',
                'description' => 'Enable add-ons',
            ],
            [
                'key'         => 'resource_management',
                'value'       => 0,
                'type'        => 'boolean',
                'description' => 'Enable resource management',
            ],
        ];

        foreach ($defaults as $setting) {
            Setting::create($setting);
        }
    }
}
