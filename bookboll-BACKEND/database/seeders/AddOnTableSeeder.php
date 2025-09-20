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
                'name'            => 'Equipment Rental',
                'description'     => 'Professional sports equipment rental',
                'price'           => 50,
                'hours_extension' => null,

            ],
            [
                'name'            => 'Equipment Rental',
                'description'     => 'Professional sports equipment rental',
                'price'           => 100,
                'hours_extension' => 1,

            ],
        ];

        foreach ($addOns as $addOn) {
            AddOn::create($addOn);
        }
    }
}
