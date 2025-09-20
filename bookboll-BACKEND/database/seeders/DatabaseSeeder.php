<?php
namespace Database\Seeders;

use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    public function run(): void
    {
        $this->call([
            UserTableSeeder::class,
            RolePermissionSeeder::class,
            SettingTableSeeder::class,
            SportsCategoryTableSeeder::class,
            GiftCardTableSeeder::class,
            ResourceTableSeeder::class,
            AddOnTableSeeder::class,
            VenueTableSeeder::class,
            ListingTableSeeder::class,
            PromocodeTableSeeder::class
        ]);
    }
}
