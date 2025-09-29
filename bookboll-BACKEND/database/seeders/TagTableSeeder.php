<?php
namespace Database\Seeders;

use App\Models\Tag;
use Illuminate\Database\Seeder;

class TagTableSeeder extends Seeder
{
    public function run(): void
    {
        $tags = [
            ['name' => 'vip', 'color' => 'bg-purple-100 text-purple-800'],
            ['name' => 'corporate', 'color' => 'bg-blue-100 text-blue-800'],
            ['name' => 'regular', 'color' => 'bg-green-100 text-green-800'],
            ['name' => 'new', 'color' => 'bg-yellow-100 text-yellow-800'],
        ];

        foreach ($tags as $tag) {
            Tag::firstOrCreate(['name' => $tag['name']], $tag);
        }
    }
}
