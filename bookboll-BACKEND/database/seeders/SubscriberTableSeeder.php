<?php
namespace Database\Seeders;

use App\Models\Subscriber;
use Illuminate\Database\Seeder;
use SubscriberStatus;

class SubscriberTableSeeder extends Seeder
{
    public function run(): void
    {
        $subscribers = [
            [
                "name"   => "Alamgir Khan",
                "email"  => "alamgir000009@gmail.com",
                "status" => SubscriberStatus::SUBSCRIBED,
            ],
            [
                "name"   => "Will Smith",
                "status" => SubscriberStatus::SUBSCRIBED,
                "email"  => "alamgirdevelopment@gmail.com",
            ],
            [
                "name"   => "Isac Carry",
                "email"  => "alamgirarmaniyt@gmail.com",
                "status" => SubscriberStatus::UNSUBSCRIBED,
            ],
        ];

        foreach ($subscribers as $subscriber) {
            Subscriber::create($subscriber);
        }
    }
}
