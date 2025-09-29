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
                'email'    => 'super_admin@bookboll.com',
                'password' => '12345678',
            ],
            [
                'name'     => 'Admin Admin',
                'email'    => 'admin@bookboll.com',
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
                'name'     => 'Customer',
                'email'    => 'customer@bookboll.com',
                'password' => '12345678',
            ],
            [
                'name'     => 'Customer 1',
                'email'    => 'customer1@bookboll.com',
                'password' => '12345678',
            ],
            [
                'name'     => 'Customer 2',
                'email'    => 'customer2@bookboll.com',
                'password' => '12345678',
            ],
            [
                'name'     => 'Customer 3',
                'email'    => 'customer3@bookboll.com',
                'password' => '12345678',
            ],
        ];

        foreach ($users as $user) {
            User::create($user);
        }
    }
}
