<?php
namespace Database\Seeders;

use App\Models\Listing;
use App\Models\Promocode;
use App\Models\Venue;
use Carbon\Carbon;
use Illuminate\Database\Seeder;

class PromocodeTableSeeder extends Seeder
{
    public function run(): void
    {
        $promocodes = [
            [
                'code'                => 'SAVE20',
                'status'              => 'active',
                'description'         => 'Get 20% off on all sports bookings this month! Perfect for football and basketball enthusiasts.',
                'discount_type'       => 'percentage',
                'discount_value'      => 20.00,
                'usage_limit'         => 200,
                'valid_from'          => Carbon::now()->startOfMonth(),
                'expires_at'          => Carbon::now()->endOfMonth(),
                'min_order_amount'    => 50.00,
                'max_discount_amount' => 100.00,
                'per_user_limit'      => 3,
                'payment_methods'     => 'all',
                'used_count'          => 45,
                'used_by_users'       => [
                    '1' => 2,
                    '2' => 1,
                ],
                'venues'              => [1, 2],
                'listings'            => [1, 3],
            ],

            [
                'code'                => 'FIXED25',
                'status'              => 'active',
                'description'         => 'QAR 25 off on any booking over QAR 100. No expiry date!',
                'discount_type'       => 'fixed',
                'discount_value'      => 25.00,
                'usage_limit'         => 150,
                'valid_from'          => null,
                'expires_at'          => null,
                'min_order_amount'    => 100.00,
                'max_discount_amount' => null,
                'per_user_limit'      => 5,
                'payment_methods'     => 'online_only',
                'used_count'          => 23,
                'used_by_users'       => [
                    '3' => 3,
                    '4' => 1,
                ],
                'venues'              => [],
                'listings'            => [],
            ],

            [
                'code'                => 'WELCOME15',
                'status'              => 'inactive',
                'description'         => '15% welcome discount for new users - Limited time offer',
                'discount_type'       => 'percentage',
                'discount_value'      => 15.00,
                'usage_limit'         => 500,
                'valid_from'          => Carbon::now()->subDays(30),
                'expires_at'          => Carbon::now()->subDays(5),
                'min_order_amount'    => 30.00,
                'max_discount_amount' => 50.00,
                'per_user_limit'      => 1,
                'payment_methods'     => 'all',
                'used_count'          => 387,
                'used_by_users'       => [
                    '5' => 1,
                    '6' => 1,
                ],
                'venues'              => [1],
                'listings'            => [1],
            ],
        ];

        foreach ($promocodes as $data) {
            $this->createPromocodeWithRelationships($data);
        }
    }

    private function createPromocodeWithRelationships(array $data): void
    {
        $promocode = Promocode::create([
            'code'                => $data['code'],
            'status'              => $data['status'],
            'description'         => $data['description'],
            'discount_type'       => $data['discount_type'],
            'discount_value'      => $data['discount_value'],
            'usage_limit'         => $data['usage_limit'],
            'valid_from'          => $data['valid_from'],
            'expires_at'          => $data['expires_at'],
            'min_order_amount'    => $data['min_order_amount'],
            'max_discount_amount' => $data['max_discount_amount'],
            'per_user_limit'      => $data['per_user_limit'],
            'payment_methods'     => $data['payment_methods'],
            'used_count'          => $data['used_count'],
            'used_by_users'       => $data['used_by_users'],
        ]);

        if (! empty($data['venues'])) {
            $venueIds = Venue::whereIn('id', $data['venues'])->pluck('id')->toArray();
            $promocode->venues()->sync($venueIds);
        }

        if (! empty($data['listings'])) {
            $listingIds = Listing::whereIn('id', $data['listings'])->pluck('id')->toArray();
            $promocode->listings()->sync($listingIds);
        }

    }
}
