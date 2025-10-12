<?php
namespace Database\Seeders;

use App\Models\Preference;
use App\Models\SportsCategory;
use App\Models\User;
use Illuminate\Database\Seeder;

class PreferenceTableSeeder extends Seeder
{
    public function run(): void
    {
        $users = User::where("id", ">", 5)->get();
        $sportCategories = SportsCategory::pluck('name')->toArray();

        foreach ($users as $user) {

            $randomCount = rand(3, 5);
            $favoriteSports = collect($sportCategories)->random($randomCount)->values()->toArray();

            Preference::updateOrCreate(
                ['user_id' => $user->id],
                [
                    'notifications'   => [
                        'email_bookings'   => true,
                        'sms_reminders'    => true,
                        'marketing_emails' => false,
                    ],
                    'favorite_sports' => $favoriteSports,
                    'language'        => 'english',
                    'currency'        => 'QAR',
                ]
            );
        }
    }

}
