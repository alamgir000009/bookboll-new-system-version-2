<?php

namespace Database\Seeders;

use App\Models\ClassProgram;
use App\Models\Listing;
use App\Models\Venue;
use Illuminate\Database\Seeder;

/**
 * Local smoke data for /approvals when Supabase is unreachable.
 *
 *   php artisan db:seed --class=ApprovalsDemoSeeder
 */
class ApprovalsDemoSeeder extends Seeder
{
    public function run(): void
    {
        $venue = Venue::query()->orderBy('id')->first();
        if (! $venue) {
            $this->command?->warn('ApprovalsDemoSeeder: no venues — skip listings.');
        } else {
            Listing::query()->updateOrCreate(
                [
                    'venue_id' => $venue->id,
                    'name'     => 'Demo Pending Court',
                ],
                [
                    'description'           => 'Seeded pending listing for Approvals smoke tests.',
                    'base_price'            => 120,
                    'capacity'              => 10,
                    'slot_duration'         => 60,
                    'max_bookings_per_slot' => 1,
                    'status'                => 'active',
                    'sport_category'        => 'Futsal Court XL',
                    'category'              => 'sports',
                    'payment_options'       => ['pay_at_venue'],
                    'approval_status'       => 'pending',
                    'eligibility_status'    => 'eligible',
                    'show_on_marketplace'   => true,
                    'show_on_provider_site' => true,
                    'submitted_at'          => now(),
                    'submitted_by_name'     => 'Demo Provider',
                    'calendar_color'        => '#0ea5e9',
                    'photos'                => [],
                ]
            );

            $live = Listing::query()
                ->where('venue_id', $venue->id)
                ->where('name', '!=', 'Demo Pending Court')
                ->orderBy('id')
                ->first();
            if ($live) {
                $live->update([
                    'approval_status'    => 'approved',
                    'eligibility_status' => 'eligible',
                    'pending_content'    => [
                        'name'        => ($live->name ?: 'Listing') . ' (Updated)',
                        'description' => 'Staged content update awaiting review.',
                        'photo_url'   => $live->photo_url,
                        'photos'      => $live->photos ?: [],
                    ],
                ]);
            }
        }

        $program = ClassProgram::query()->orderByDesc('created_at')->first();
        if ($program) {
            $program->update([
                'approval_status'    => 'pending',
                'eligibility_status' => 'eligible',
                'admin_note'         => null,
                'previous_snapshot'  => null,
                'pending_content'    => null,
                'submitted_at'       => now(),
                'submitted_by_name'  => 'Demo Provider',
                'sport_category'     => $program->sport_category ?: 'Kids Academy Elite',
                'category'           => $program->category ?: 'academy',
            ]);
        } else {
            $this->call(ClassMembershipDemoSeeder::class);
            ClassProgram::query()->where('id', 'aaaaaaaa-bbbb-cccc-dddd-000000000001')->update([
                'approval_status'    => 'pending',
                'eligibility_status' => 'eligible',
                'submitted_at'       => now(),
                'submitted_by_name'  => 'Demo Provider',
            ]);
        }

        $this->call(CustomTypesFromSupabaseSeeder::class);
        $this->command?->info('Approvals demo rows ready (pending listing + program).');
    }
}
