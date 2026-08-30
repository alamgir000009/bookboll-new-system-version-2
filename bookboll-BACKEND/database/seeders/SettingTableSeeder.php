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
                'value'       => 'pk_test_51SPZ0zAnPS3KrhqJaCi4rPR81jlzikJsmha8jq5Xz5Hw0wEzaKorVzEgnS9jv6IaVZLR2sCXTg4J2yx1AAsZ82Y000CclzTQKZ',
                'type'        => 'string',
                'description' =>
                'Stripe Publishable Key',
            ],
            [
                'key'         => 'stripe_secret_key',
                'value'       => 'sk_test_51SPZ0zAnPS3KrhqJrWnvLXKrWLGKuE1kZQfrAbFtXJzpV9igUSWXSMu5Ufl8zUWMT5ON3JhUPPC392GAiz5Au4Vd00kXia5Pqz',
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
            [
                'key'         => 'days_until_next_month_bookings_open',
                'value'       => 7,
                'type'        => 'integer',
                'description' => 'Number of days before the end of current month when next month slots become available',
            ],
            [
                'key'         => 'deposit_payment_timeout_minutes',
                'value'       => 10,
                'type'        => 'integer',
                'description' => 'Auto-cancel unpaid deposit bookings after the following minutes',
            ],
            [
                'key'         => 'payment_timeout_grace_minutes',
                'value'       => 2,
                'type'        => 'integer',
                'description' => 'Extra minutes allowed past the displayed payment timeout before an unpaid booking is auto-cancelled',
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

            // Reminder emails
            [
                'key'         => 'reminder_emails_after_days',
                'value'       => 7,
                'type'        => 'integer',
                'description' => 'After the following days booking reminders emaill will be sent',
            ],
            // Reminder Promocode percentage
            [
                'key'         => 'reminder_promocode_discount_percentage',
                'value'       => 10,
                'type'        => 'integer',
                'description' => 'The following percentage will be applied to the reminder promocode',
            ],
            // Reminder Promocode validity days
            [
                'key'         => 'reminder_promocode_validity_days',
                'value'       => 30,
                'type'        => 'integer',
                'description' => 'The following number of days the reminder promocode will be valid',
            ],
        ];

        foreach ($defaults as $setting) {
            Setting::create($setting);
        }
    }
}
