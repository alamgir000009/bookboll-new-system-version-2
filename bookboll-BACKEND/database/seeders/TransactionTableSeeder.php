<?php
namespace Database\Seeders;

use App\Models\User;
use Illuminate\Database\Seeder;

class TransactionTableSeeder extends Seeder
{
    public function run(): void
    {
        $users = User::where("id", ">", 5)->get();

        foreach ($users as $user) {
            for ($i = 0; $i < 5; $i++) {
                $user->transactions()->create([
                    'type'   => "credit",
                    'amount' => rand(5, 200),
                    'reason' => "Initial balance top-up",
                ]);
            }
        }
    }
}
