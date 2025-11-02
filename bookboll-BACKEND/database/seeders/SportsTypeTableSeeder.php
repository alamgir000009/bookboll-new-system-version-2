<?php
namespace Database\Seeders;

use App\Models\SportsType;
use Illuminate\Database\Seeder;

class SportsTypeTableSeeder extends Seeder
{
    public function run(): void
    {
        $sportsTypes = [
            [
                'icon' => '⚽',
                'slug' => 'sports-facilities',
                'name' => 'Sports Facilities',
            ],
            [

                'icon' => '🏜️',
                'slug' => 'adventure-sports',
                'name' => 'Adventure Sports',
            ],
            [
                'icon' => '🎱',
                'slug' => 'entertainment-sports',
                'name' => 'Entertainment Sports',
            ],
        ];

        foreach ($sportsTypes as $sportsType) {
            SportsType::create($sportsType);
        }
    }
}
