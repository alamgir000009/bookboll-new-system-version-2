<?php
namespace Database\Seeders;

use App\Models\SportsCategory;
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
            ],
            [
                'name'        => 'Basketball',
                'description' => 'Indoor and outdoor basketball courts',
                'icon'        => '🏀',
                'status'      => 'active',
            ],
            [
                'name'        => 'Tennis',
                'description' => 'Tennis courts and facilities',
                'icon'        => '🎾',
                'status'      => 'active',
            ],
            [
                'name'        => 'Swimming',
                'description' => 'Swimming pools and aquatic facilities',
                'icon'        => '🏊',
                'status'      => 'active',
            ],
            [
                'name'        => 'Volleyball',
                'description' => 'Indoor and beach volleyball',
                'icon'        => '🏐',
                'status'      => 'active',
            ],
            [
                'name'        => 'Badminton',
                'description' => 'Badminton courts and equipment',
                'icon'        => '🏸',
                'status'      => 'active',
            ],
        ];

        foreach ($categories as $category) {
            SportsCategory::create($category);
        }
    }
}
