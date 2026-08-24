<?php

namespace Database\Seeders;

use App\Models\ClassProgram;
use App\Models\ClassSession;
use App\Models\ClassSubscription;
use App\Models\Venue;
use Illuminate\Database\Seeder;

/**
 * Small local smoke data when Supabase credentials are missing / unreachable.
 *
 *   php artisan db:seed --class=ClassMembershipDemoSeeder
 */
class ClassMembershipDemoSeeder extends Seeder
{
    public function run(): void
    {
        $venue = Venue::query()->orderBy('id')->first();
        $programId = 'aaaaaaaa-bbbb-cccc-dddd-000000000001';
        $subId = 'aaaaaaaa-bbbb-cccc-dddd-000000000002';
        $sessionId = 'aaaaaaaa-bbbb-cccc-dddd-000000000003';

        ClassProgram::query()->updateOrCreate(
            ['id' => $programId],
            [
                'name'                     => 'Demo Football Academy',
                'description'              => 'Seeded demo membership program (not from Supabase).',
                'sport_category'           => 'Football',
                'coach_name'               => 'Demo Coach',
                'monthly_price'            => 299,
                'currency'                 => 'AED',
                'sessions_per_week'        => 2,
                'session_duration_minutes' => 60,
                'day_of_week_mask'         => [1, 3],
                'session_time'             => '18:00',
                'capacity'                 => 12,
                'commission_percentage'    => 20,
                'status'                   => 'active',
                'billing_interval'         => 'monthly',
                'free_trial_days'          => 7,
                'free_trial_enabled'       => true,
                'category'                 => 'academy',
                'gender_policy'            => 'mixed',
                'approval_status'          => 'approved',
                'eligibility_status'       => 'eligible',
                'show_on_marketplace'      => true,
                'show_on_provider_site'    => true,
                'venue_id'                 => $venue?->id,
                'color'                    => '#7c3aed',
                'signup_form'              => [],
                'images'                   => [],
            ]
        );

        ClassSubscription::query()->updateOrCreate(
            ['id' => $subId],
            [
                'program_id'       => $programId,
                'customer_name'    => 'Demo Member',
                'customer_email'   => 'demo.member@example.com',
                'started_at'       => now()->toDateString(),
                'next_billing_at'  => now()->addDays(7)->toDateString(),
                'trial_ends_at'    => now()->addDays(7)->toDateString(),
                'status'           => 'active',
                'trial_status'     => 'in_trial',
                'billing_interval' => 'monthly',
                'monthly_amount'   => 299,
                'provider_payout'  => 239.2,
                'platform_fee'     => 59.8,
                'is_comp'          => false,
                'form_answers'     => [],
            ]
        );

        ClassSession::query()->updateOrCreate(
            ['id' => $sessionId],
            [
                'subscription_id'  => $subId,
                'program_id'       => $programId,
                'session_date'     => now()->next('Monday')->toDateString(),
                'session_time'     => '18:00',
                'duration_minutes' => 60,
                'status'           => 'scheduled',
            ]
        );

        // Second light program for list UI smoke tests
        $programId2 = 'aaaaaaaa-bbbb-cccc-dddd-000000000011';
        ClassProgram::query()->updateOrCreate(
            ['id' => $programId2],
            [
                'name'                     => 'Demo Yoga Membership',
                'description'              => 'Second demo program.',
                'sport_category'           => 'Yoga',
                'monthly_price'            => 149,
                'currency'                 => 'AED',
                'sessions_per_week'        => 3,
                'session_duration_minutes' => 45,
                'day_of_week_mask'         => [2, 4, 6],
                'session_time'             => '09:00',
                'capacity'                 => 20,
                'status'                   => 'active',
                'billing_interval'         => 'monthly',
                'free_trial_days'          => 0,
                'free_trial_enabled'       => false,
                'category'                 => 'fitness',
                'approval_status'          => 'approved',
                'eligibility_status'       => 'eligible',
                'venue_id'                 => $venue?->id,
                'color'                    => '#0f766e',
            ]
        );

        $this->command?->info('Demo class membership rows ready (2 programs, 1 subscription, 1 session).');
        $this->command?->info('IDs: program=' . $programId . ' sub=' . $subId);
    }
}
