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
                'key'         => 'stripe.publishable_key',
                'value'       => '',
                'type'        => 'string',
                'description' =>
                'Stripe Publishable Key',
            ],
            [
                'key'         => 'stripe.secret_key',
                'value'       => null,
                'type'        => 'string',
                'encrypted'   => true,
                'description' => 'Stripe Secret Key',
            ],

            // Booking
            [
                'key'         => 'booking.min_advance_hours',
                'value'       => 2,
                'type'        => 'integer',
                'description' => 'Default minimum advance booking hours',
            ],
            [
                'key'         => 'booking.max_per_slot',
                'value'       => 1,
                'type'        => 'integer',
                'description' => 'Default maximum bookings per slot',
            ],
            [
                'key'         => 'booking.auto_confirm',
                'value'       => 1,
                'type'        => 'boolean',
                'description' => 'Auto-confirm new bookings',
            ],

            // Modules
            [
                'key'         => 'modules.loyalty',
                'value'       => 1,
                'type'        => 'boolean',
                'description' => 'Enable loyalty system',
            ],
            [
                'key'         => 'modules.special_days',
                'value'       => 1,
                'type'        => 'boolean',
                'description' => 'Enable special days',
            ],
            [
                'key'         => 'modules.addons',
                'value'       => 0,
                'type'        => 'boolean',
                'description' => 'Enable add-ons',
            ],
            [
                'key'         => 'modules.resource_management',
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
