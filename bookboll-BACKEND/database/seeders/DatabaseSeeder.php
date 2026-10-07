<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    public function run(): void
    {
        $this->call([
            LoyaltyTierTableSeeder::class,
            UserTableSeeder::class,
            RolePermissionTableSeeder::class,
            TeamDemoSeeder::class,
            SettingTableSeeder::class,
            SportsTypeTableSeeder::class,
            SportsCategoryTableSeeder::class,
            // GiftCardTableSeeder::class,
            // ResourceTableSeeder::class,
            // AddOnTableSeeder::class,
            // VenueTableSeeder::class,
            // ListingTableSeeder::class,
            // PromocodeTableSeeder::class,
            // SpecialDayTableSeeder::class,
            // BookingTableSeeder::class,
            TagTableSeeder::class,
            BlogTableSeeder::class,
            // ReviewTableSeeder::class,
            // LoyaltyPointTableSeeder::class,
            // TransactionTableSeeder::class,
            // PaymentTableSeeder::class,
            // SubscriberTableSeeder::class
            WorldSeeder::class,
            // Point existing venues (and therefore listings) at Doha, Qatar:
            // php artisan db:seed --class=VenuesDohaQatarSeeder
            // VenuesDohaQatarSeeder::class,
            // Membership programs/subscribers: pull from Supabase when SUPABASE_* is set.
            // Or run alone: php artisan db:seed --class=ClassMembershipFromSupabaseSeeder
            // ClassMembershipFromSupabaseSeeder::class,
        ]);
    }
}
