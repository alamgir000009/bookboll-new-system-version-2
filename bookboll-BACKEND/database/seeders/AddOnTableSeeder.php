<?php
namespace Database\Seeders;

use App\Models\AddOn;
use Illuminate\Database\Seeder;

class AddOnTableSeeder extends Seeder
{
    public function run(): void
    {
        $addOns = [
            [
                'sort_order'      => 1,
                'price'           => 50,
                'hours_extension' => null,
                'name'            => 'Equipment Rental',
                'description'     => 'Professional sports equipment rental',

            ],
            [
                'sort_order'      => 2,
                'hours_extension' => 1,
                'price'           => 100,
                'name'            => 'Equipment Rental',
                'description'     => 'Professional sports equipment rental',

            ],
        ];

        foreach ($addOns as $addOn) {
            AddOn::create($addOn);
        }
    }
}
