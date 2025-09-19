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
            ],
            [
                'title' => 'Premium Gift Card',
                'description' => 'Premium gift card with higher value.',
                'points_required' => 100,
                'value' => 100.00,
                'currency' => 'qar',
                'status' => 'active',
            ],
            [
                'title' => 'VIP Gift Card',
                'description' => 'VIP access gift card.',
                'points_required' => 200,
                'value' => 200.00,
                'currency' => 'qar',
                'status' => 'inactive',
            ],
        ];

        foreach ($giftCards as $giftCard) {
            GiftCard::create($giftCard);
        }
    }
}
