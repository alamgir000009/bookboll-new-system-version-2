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
                'sort_order' => 1,
                'icon'       => '⚽',
                'color'      => 'red',
                'slug'       => 'sports-facilities',
                'title'      => 'Sports Facilities',
                'sub_title'  => 'Sports Categories',
            ],
            [
                'sort_order' => 2,
                'icon'       => '🏜️',
                'color'      => 'blue',
                'slug'       => 'adventure-sports',
                'title'      => 'Adventure Sports',
                'sub_title'  => 'Adventure Activities',
            ],
            [
                'sort_order' => 3,
                'icon'       => '🎱',
                'color'      => 'orange',
                'slug'       => 'entertainment-sports',
                'title'      => 'Entertainment Sports',
                'sub_title'  => 'Entertainment Options',
            ],
        ];

        foreach ($sportsTypes as $sportsType) {
            SportsType::create($sportsType);
        }
    }
}
