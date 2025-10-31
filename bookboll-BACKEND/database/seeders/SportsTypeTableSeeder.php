<?php
namespace Database\Seeders;

use App\Models\SportsType;
use Illuminate\Database\Seeder;

class SportsTypeTableSeeder extends Seeder
{
    public function run(): void
    {
        $sportsTypes = ['Sports Facilities', 'Adventure Sports', 'Entertainment Sports'];

        foreach ($sportsTypes as $sportsType) {
            SportsType::create([
                "name"       => $sportsType,
                "slug"       => strtolower(str_replace(" ", "-", $sportsType)),
            ]);
        }
    }
}
