<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Seed platform settings used by apps/admin Settings page
 * (Platform, Policies, Notifications) — mirrors Supabase platform_settings keys
 * where they overlap.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('settings')) {
            return;
        }

        $now = now();
        $defaults = [
            // Platform
            ['key' => 'platform_name', 'value' => 'Bookboll', 'type' => 'string', 'description' => 'Platform display name'],
            ['key' => 'platform_tagline', 'value' => 'Book your game', 'type' => 'string', 'description' => 'Platform tagline'],
            ['key' => 'platform_default_currency', 'value' => 'AED', 'type' => 'string', 'description' => 'Default display currency code'],

            // Policies (Supabase platform_settings parity)
            ['key' => 'cancellation_hours', 'value' => '48', 'type' => 'integer', 'description' => 'Hours before booking for refundable cancellation'],
            ['key' => 'booking_hold_minutes', 'value' => '10', 'type' => 'integer', 'description' => 'Checkout slot hold timer (minutes)'],
            ['key' => 'noshow_policy', 'value' => 'Customer forfeits deposit. Provider receives nothing. Provider may ban the customer from the dashboard.', 'type' => 'text', 'description' => 'No-show policy text shown to customers'],
            ['key' => 'trial_cancel_policy', 'value' => 'Customer must cancel before the trial period ends. First billing charge applies if not cancelled in time.', 'type' => 'text', 'description' => 'Membership trial cancellation policy text'],

            // Admin notification preferences
            ['key' => 'notify_new_booking', 'value' => '1', 'type' => 'boolean', 'description' => 'Alert on new bookings'],
            ['key' => 'notify_payment_failed', 'value' => '1', 'type' => 'boolean', 'description' => 'Alert on payment failures'],
            ['key' => 'notify_provider_signup', 'value' => '1', 'type' => 'boolean', 'description' => 'Alert on new provider signups'],
            ['key' => 'notify_daily_summary', 'value' => '0', 'type' => 'boolean', 'description' => 'Daily summary emails'],
        ];

        foreach ($defaults as $row) {
            $exists = DB::table('settings')->where('key', $row['key'])->exists();
            if ($exists) {
                continue;
            }
            DB::table('settings')->insert([
                'key'         => $row['key'],
                'value'       => $row['value'],
                'type'        => $row['type'],
                'description' => $row['description'],
                'encrypted'   => false,
                'created_at'  => $now,
                'updated_at'  => $now,
            ]);
        }
    }

    public function down(): void
    {
        if (! Schema::hasTable('settings')) {
            return;
        }

        DB::table('settings')->whereIn('key', [
            'platform_name',
            'platform_tagline',
            'platform_default_currency',
            'cancellation_hours',
            'booking_hold_minutes',
            'noshow_policy',
            'trial_cancel_policy',
            'notify_new_booking',
            'notify_payment_failed',
            'notify_provider_signup',
            'notify_daily_summary',
        ])->delete();
    }
};
