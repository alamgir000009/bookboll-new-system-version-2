<?php
namespace Database\Seeders;

use App\Models\Setting;
use Illuminate\Database\Seeder;

class SettingTableSeeder extends Seeder
{
    public function run(): void
    {
        $defaults = [
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

            // Admin dashboard — Platform
            [
                'key'         => 'platform_name',
                'value'       => 'Bookboll',
                'type'        => 'string',
                'description' => 'Platform display name',
            ],
            [
                'key'         => 'platform_tagline',
                'value'       => 'Book your game',
                'type'        => 'string',
                'description' => 'Platform tagline',
            ],
            [
                'key'         => 'platform_default_currency',
                'value'       => 'AED',
                'type'        => 'string',
                'description' => 'Default display currency code',
            ],

            // Admin dashboard — Policies (Supabase platform_settings parity)
            [
                'key'         => 'cancellation_hours',
                'value'       => 48,
                'type'        => 'integer',
                'description' => 'Hours before booking for refundable cancellation',
            ],
            [
                'key'         => 'booking_hold_minutes',
                'value'       => 10,
                'type'        => 'integer',
                'description' => 'Checkout slot hold timer (minutes)',
            ],
            [
                'key'         => 'noshow_policy',
                'value'       => 'Customer forfeits deposit. Provider receives nothing. Provider may ban the customer from the dashboard.',
                'type'        => 'text',
                'description' => 'No-show policy text shown to customers',
            ],
            [
                'key'         => 'trial_cancel_policy',
                'value'       => 'Customer must cancel before the trial period ends. First billing charge applies if not cancelled in time.',
                'type'        => 'text',
                'description' => 'Membership trial cancellation policy text',
            ],

            // Admin dashboard — Notification preferences
            [
                'key'         => 'notify_new_booking',
                'value'       => 1,
                'type'        => 'boolean',
                'description' => 'Alert on new bookings',
            ],
            [
                'key'         => 'notify_payment_failed',
                'value'       => 1,
                'type'        => 'boolean',
                'description' => 'Alert on payment failures',
            ],
            [
                'key'         => 'notify_provider_signup',
                'value'       => 1,
                'type'        => 'boolean',
                'description' => 'Alert on new provider signups',
            ],
            [
                'key'         => 'notify_daily_summary',
                'value'       => 0,
                'type'        => 'boolean',
                'description' => 'Daily summary emails',
            ],

            // Marketplace + admin chrome brand (apps/admin /brand)
            [
                'key'         => 'brand_name',
                'value'       => 'Bookboll',
                'type'        => 'string',
                'description' => 'Marketplace display name',
            ],
            [
                'key'         => 'brand_tagline',
                'value'       => '',
                'type'        => 'string',
                'description' => 'Marketplace tagline',
            ],
            [
                'key'         => 'brand_logo_url',
                'value'       => '',
                'type'        => 'string',
                'description' => 'Marketplace logo URL',
            ],
            [
                'key'         => 'brand_primary',
                'value'       => '#0f766e',
                'type'        => 'string',
                'description' => 'Marketplace primary color',
            ],
            [
                'key'         => 'brand_accent',
                'value'       => '#14b8a6',
                'type'        => 'string',
                'description' => 'Marketplace accent color',
            ],
            [
                'key'         => 'brand_meta_title',
                'value'       => '',
                'type'        => 'string',
                'description' => 'Marketplace SEO title',
            ],
            [
                'key'         => 'brand_meta_description',
                'value'       => '',
                'type'        => 'text',
                'description' => 'Marketplace SEO description',
            ],
            [
                'key'         => 'admin_logo_url',
                'value'       => '',
                'type'        => 'string',
                'description' => 'Admin dashboard logo URL',
            ],
            [
                'key'         => 'admin_primary',
                'value'       => '',
                'type'        => 'string',
                'description' => 'Admin dashboard primary color',
            ],
        ];

        foreach ($defaults as $setting) {
            Setting::firstOrCreate(
                ['key' => $setting['key']],
                $setting
            );
        }
    }
}
