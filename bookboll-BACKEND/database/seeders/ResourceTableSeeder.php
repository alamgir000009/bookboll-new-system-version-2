<?php
namespace Database\Seeders;

use App\Models\Resource;
use Illuminate\Database\Seeder;

class ResourceTableSeeder extends Seeder
{
    public function run(): void
    {
        $resources = [
            [
                'name'           => 'Tennis Racket',
                'total_quantity' => 10,
                'unit_type'      => 'pieces',
                'status'         => 'active',
            ],
            [
                'name'           => 'Basketball',
                'total_quantity' => 25,
                'unit_type'      => 'pieces',
                'status'         => 'active',
            ],
            [
                'name'           => 'Soccer Ball',
                'total_quantity' => 15,
                'unit_type'      => 'pieces',
                'status'         => 'active',
            ],
            [
                'name'           => 'Volleyball',
                'total_quantity' => 8,
                'unit_type'      => 'pieces',
                'status'         => 'active',
            ],
            [
                'name'           => 'Coach',
                'total_quantity' => 3,
                'unit_type'      => 'person',
                'status'         => 'active',
            ],
            [
                'name'           => 'Referee',
                'total_quantity' => 2,
                'unit_type'      => 'person',
                'status'         => 'inactive',
            ],
        ];

        foreach ($resources as $resource) {
            Resource::create($resource);
        }
    }
}
