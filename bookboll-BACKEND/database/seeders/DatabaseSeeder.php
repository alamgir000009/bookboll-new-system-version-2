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
            // BlogTableSeeder::class,
            // ReviewTableSeeder::class,
            // LoyaltyPointTableSeeder::class,
            // TransactionTableSeeder::class,
            // PaymentTableSeeder::class,
            // SubscriberTableSeeder::class
        ]);
    }
}
