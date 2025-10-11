<?php

namespace Database\Seeders;

use App\Models\GiftCard;
use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;

class GiftCardTableSeeder extends Seeder
{
    public function run(): void
    {
        $giftCards = [
            [
                'title' => 'Basic Gift Card',
                'description' => 'A basic gift card for entry-level redemptions.',
                'points_required' => 50,
                'value' => 50.00,
                'currency' => 'qar',
                'status' => 'active',
                'image' => 'https://images.pexels.com/photos/399187/pexels-photo-399187.jpeg?auto=compress&cs=tinysrgb&w=800'
            ],
            [
                'title' => 'Premium Gift Card',
                'description' => 'Premium gift card with higher value.',
                'points_required' => 100,
                'value' => 100.00,
                'currency' => 'qar',
                'status' => 'active',
                'image' => 'https://images.pexels.com/photos/399187/pexels-photo-399187.jpeg?auto=compress&cs=tinysrgb&w=800'
            ],
            [
                'title' => 'VIP Gift Card',
                'description' => 'VIP access gift card.',
                'points_required' => 200,
                'value' => 200.00,
                'currency' => 'qar',
                'status' => 'inactive',
                'image' => 'https://images.pexels.com/photos/399187/pexels-photo-399187.jpeg?auto=compress&cs=tinysrgb&w=800'
            ],
        ];

        foreach ($giftCards as $giftCard) {
            GiftCard::create($giftCard);
        }
    }
}
