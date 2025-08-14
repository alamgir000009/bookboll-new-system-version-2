<?php
namespace Database\Seeders;

use App\Models\User;
use Illuminate\Database\Seeder;

class UserTableSeeder extends Seeder
{
    public function run(): void
    {
        $users = [
            [
                'name'     => 'Admin User',
                'email'    => 'admin@gmail.com',
                'password' => '12345678',
            ],
        ];

        foreach ($users as $user) {
            User::create($user);
        }
    }
}
