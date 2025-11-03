<?php
namespace Database\Seeders;

use App\Models\SportsType;
use Illuminate\Database\Seeder;

class SportsCategoryTableSeeder extends Seeder
{
    public function run(): void
    {
        $categories = [
            [
                'name'        => 'Football',
                'description' => 'Association football, soccer',
                'icon'        => '⚽',
                'status'      => 'active',
                'type'        => 'Sports Facilities',
            ],
            [
                'name'        => 'Basketball',
                'description' => 'Indoor and outdoor basketball courts',
                'icon'        => '🏀',
                'status'      => 'active',
                'type'        => 'Sports Facilities',
            ],
            [
                'name'        => 'Cricket',
                'description' => 'Indoor and outdoor cricket courts',
                'icon'        => '🏏',
                'status'      => 'active',
                'type'        => 'Sports Facilities',
            ],
            [
                'name'        => 'Tennis',
                'description' => 'Tennis courts and facilities',
                'icon'        => '🎾',
                'status'      => 'active',
                'type'        => 'Adventure Sports',
            ],
            [
                'name'        => 'Swimming',
                'description' => 'Swimming pools and aquatic facilities',
                'icon'        => '🏊',
                'status'      => 'active',
                'type'        => 'Adventure Sports',
            ],
            [
                'name'        => 'Volleyball',
                'description' => 'Indoor and beach volleyball',
                'icon'        => '🏐',
                'status'      => 'active',
                'type'        => 'Entertainment Sports',
            ],
            [
                'name'        => 'Badminton',
                'description' => 'Badminton courts and equipment',
                'icon'        => '🏸',
                'status'      => 'active',
                'type'        => 'Entertainment Sports',
            ],
        ];

        foreach ($categories as $index => $category) {
            $type = SportsType::firstWhere("title", $category['type']);
            unset($category['type']);
            $category['sort_order'] = $index + 1;
            $type->sportsCategories()->create($category);
        }
    }
}
