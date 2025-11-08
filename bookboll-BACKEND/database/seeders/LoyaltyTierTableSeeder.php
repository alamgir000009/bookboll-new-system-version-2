<?php
namespace Database\Seeders;

use App\Models\LoyaltyTier;
use Illuminate\Database\Seeder;

class LoyaltyTierTableSeeder extends Seeder
{
    public function run(): void
    {
        $tiers = [
            [
                'order'       => 1,
                'min_points'  => 0,
                'max_points'  => 999,
                'is_active'   => true,
                'color'       => 'gray',
                'name'        => 'Bronze',
                'slug'        => 'bronze',
                'description' => 'Enjoy basic member benefits',
            ],
            [
                'order'       => 2,
                'is_active'   => true,
                'min_points'  => 1000,
                'max_points'  => 2499,
                'name'        => 'Silver',
                'slug'        => 'silver',
                'color'       => 'silver',
                'description' => 'Get priority booking and 5% discounts',
            ],
            [
                'order'       => 3,
                'is_active'   => true,
                'min_points'  => 2500,
                'max_points'  => 4999,
                'name'        => 'Gold',
                'slug'        => 'gold',
                'color'       => 'gold',
                'description' => 'Enjoy 10% discounts and exclusive facilities',
            ],
            [
                'order'       => 4,
                'is_active'   => true,
                'min_points'  => 5000,
                'name'        => 'Platinum',
                'slug'        => 'platinum',
                'color'       => 'platinum',
                'max_points'  => 2147483647,
                'description' => 'Premium benefits with 15% discounts',
            ],
        ];

        foreach ($tiers as $tier) {
            LoyaltyTier::updateOrCreate(
                ['slug' => $tier['slug']],
                $tier
            );
        }
    }
}
