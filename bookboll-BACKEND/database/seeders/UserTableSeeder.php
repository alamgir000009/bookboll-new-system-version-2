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
                'name'     => 'Super Admin',
                // 'email'    => 'super_admin@bookboll.com',
                'email'    => 'alamgir000009@gmail.com',
                'password' => '12345678',
            ],
            [
                'name'     => 'Admin Admin',
                // 'email'    => 'admin@bookboll.com',
                'email'    => 'alamgirdevelopment@gmail.com',
                'password' => '12345678',
            ],
            [
                'name'     => 'Senior Employee',
                'email'    => 'senior_employee@bookboll.com',
                'password' => '12345678',
            ],
            [
                'name'     => 'Junior Employee',
                'email'    => 'junior_employee@bookboll.com',
                'password' => '12345678',
            ],
            [
                'name'     => 'Guard User',
                'email'    => 'guard@bookboll.com',
                'password' => '12345678',
            ],
            [
                'name'          => 'Customer',
                'email'         => 'customer@bookboll.com',
                'password'      => '12345678',
                'phone'         => '123456789',
                'gender'        => 'male',
                'nationality'   => 'Pakistani',
                'address'       => 'Testing address',
                'date_of_birth' => now()->subYears(rand(18, 50)),
            ],
            [
                'name'          => 'Customer 1',
                'email'         => 'customer1@bookboll.com',
                'password'      => '12345678',
                'phone'         => '123456789',
                'gender'        => 'male',
                'nationality'   => 'Pakistani',
                'address'       => 'Testing address',
                'date_of_birth' => now()->subYears(rand(18, 50)),
            ],
            [
                'name'          => 'Customer 2',
                'email'         => 'customer2@bookboll.com',
                'password'      => '12345678',
                'phone'         => '123456789',
                'gender'        => 'male',
                'nationality'   => 'Pakistani',
                'address'       => 'Testing address',
                'date_of_birth' => now()->subYears(rand(18, 50)),
            ],
            [
                'name'          => 'Customer 3',
                'email'         => 'customer3@bookboll.com',
                'password'      => '12345678',
                'phone'         => '123456789',
                'gender'        => 'male',
                'nationality'   => 'Pakistani',
                'address'       => 'Testing address',
                'date_of_birth' => now()->subYears(rand(18, 50)),
            ],
        ];

        foreach ($users as $user) {
            $user['phone'] = '+923481197071';
            User::create($user);
        }
    }
}
