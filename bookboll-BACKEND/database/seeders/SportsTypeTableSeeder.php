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
                'icon'      => '⚽',
                'slug'      => 'sports-facilities',
                'title'     => 'Sports Facilities',
                'sub_title' => 'Sports Categories',
            ],
            [
                'icon'      => '🏜️',
                'slug'      => 'adventure-sports',
                'title'     => 'Adventure Sports',
                'sub_title' => 'Adventure Activities',
            ],
            [
                'icon'      => '🎱',
                'slug'      => 'entertainment-sports',
                'title'     => 'Entertainment Sports',
                'sub_title' => 'Entertainment Options',
            ],
        ];

        foreach ($sportsTypes as $sportsType) {
            SportsType::create($sportsType);
        }
    }
}
