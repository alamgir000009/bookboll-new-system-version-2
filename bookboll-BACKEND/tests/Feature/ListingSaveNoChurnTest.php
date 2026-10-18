<?php

/**
 * Pressing Save on an unchanged listing changes nothing, and says nothing.
 *
 * Opening the editor and clicking Save used to delete every Schedule row for
 * the listing and re-insert it, so a no-op save burned seven ids and wrote
 * seven "Schedule created" entries into the activity log. Worse, the mass
 * delete fired no model events, so the matching removals were never recorded —
 * the log showed creations from nowhere, for a listing nobody had edited. An
 * audit log that reports changes that did not happen cannot be used to find the
 * ones that did.
 */

use App\Models\Listing;
use App\Models\Schedule;
use App\Models\User;
use App\Models\Venue;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Spatie\Permission\Models\Role;

uses(RefreshDatabase::class);

beforeEach(function () {
    Role::findOrCreate('admin', 'web');
    $this->staff = User::factory()->create();
    $this->staff->assignRole('admin');

    $this->venue = Venue::create([
        'name' => 'Pitch', 'status' => Venue::ACTIVE,
        'maps_url' => 'https://maps.google.com/?q=0,0',
        'min_advance_booking' => 0, 'max_bookings_per_slot' => 1,
    ]);
    $this->listing = Listing::create([
        'name' => 'Court', 'venue_id' => $this->venue->id, 'status' => 'active',
        'base_price' => 250, 'capacity' => 10, 'slot_duration' => 60,
        'payment_options' => ['cash_on_arrival' => true], 'max_bookings_per_slot' => 1,
    ]);

    // Seven days open, plus a second evening period on Tuesday.
    $this->hours = collect(range(0, 6))->map(fn ($d) => [
        'day_of_week' => $d,
        'is_open'     => true,
        'periods'     => $d === 2
            ? [['open_time' => '09:00', 'close_time' => '12:00'], ['open_time' => '17:00', 'close_time' => '23:00']]
            : [['open_time' => '09:00', 'close_time' => '23:00']],
    ])->all();

    $this->save = fn (array $hours) => $this->actingAs($this->staff)
        ->withHeaders(['from' => 'admin-web'])
        ->putJson('/api/admin/listings/dashboard/' . $this->listing->id, [
            'name' => 'Court', 'location_id' => $this->venue->id, 'description' => 'x',
            'base_price' => 250, 'slot_duration' => 60, 'hours' => $hours,
        ]);

    $this->watch = function (callable $run) {
        $sql = [];
        DB::listen(function ($q) use (&$sql) {
            if (str_contains($q->sql, 'schedules')) {
                $sql[] = $q->sql;
            }
        });
        $run();

        return collect($sql);
    };

    ($this->save)($this->hours)->assertOk();
});

it('writes nothing when the hours are unchanged', function () {
    $before = Schedule::where('listing_id', $this->listing->id)->orderBy('id')->get();
    expect($before)->toHaveCount(8);   // 6 single-period days + Tuesday's two

    $sql = ($this->watch)(fn () => ($this->save)($this->hours)->assertOk());

    expect($sql->filter(fn ($q) => str_starts_with($q, 'insert')))->toBeEmpty()
        ->and($sql->filter(fn ($q) => str_starts_with($q, 'delete')))->toBeEmpty()
        ->and($sql->filter(fn ($q) => str_starts_with($q, 'update')))->toBeEmpty();

    $after = Schedule::where('listing_id', $this->listing->id)->orderBy('id')->get();
    expect($after->pluck('id')->all())->toBe($before->pluck('id')->all())
        ->and($after->pluck('updated_at')->map->toString()->all())
            ->toBe($before->pluck('updated_at')->map->toString()->all());
});

it('logs no activity for a save that changed nothing', function () {
    $before = DB::table('activity_log')->count();

    ($this->save)($this->hours)->assertOk();

    expect(DB::table('activity_log')->count())->toBe($before);
});

it('updates a changed day in place, leaving the others alone', function () {
    $before = Schedule::where('listing_id', $this->listing->id)->orderBy('id')->get();

    $hours = $this->hours;
    $hours[4]['periods'] = [['open_time' => '08:00', 'close_time' => '23:00']];   // Thursday opens earlier

    $sql = ($this->watch)(fn () => ($this->save)($hours)->assertOk());

    expect($sql->filter(fn ($q) => str_starts_with($q, 'update')))->toHaveCount(1)
        ->and($sql->filter(fn ($q) => str_starts_with($q, 'insert')))->toBeEmpty()
        ->and($sql->filter(fn ($q) => str_starts_with($q, 'delete')))->toBeEmpty();

    $after = Schedule::where('listing_id', $this->listing->id)->orderBy('id')->get();
    expect($after->pluck('id')->all())->toBe($before->pluck('id')->all());
});

it('inserts only a genuinely new period', function () {
    $ids = Schedule::where('listing_id', $this->listing->id)->pluck('id')->all();

    $hours = $this->hours;
    $hours[5]['periods'][] = ['open_time' => '23:00', 'close_time' => '23:59'];

    $sql = ($this->watch)(fn () => ($this->save)($hours)->assertOk());

    expect($sql->filter(fn ($q) => str_starts_with($q, 'insert')))->toHaveCount(1)
        ->and($sql->filter(fn ($q) => str_starts_with($q, 'delete')))->toBeEmpty()
        ->and(Schedule::whereIn('id', $ids)->count())->toBe(count($ids));
});

it('deletes only the period the editor dropped', function () {
    $tue = Schedule::where('listing_id', $this->listing->id)->where('day', 'tuesday')->orderBy('id')->get();
    expect($tue)->toHaveCount(2);

    $hours = $this->hours;
    $hours[2]['periods'] = [['open_time' => '09:00', 'close_time' => '12:00']];   // drop the evening block

    ($this->save)($hours)->assertOk();

    expect(Schedule::find($tue[0]->id))->not->toBeNull()
        ->and(Schedule::find($tue[1]->id))->toBeNull()
        ->and(Schedule::where('listing_id', $this->listing->id)->count())->toBe(7);
});

it('does not burn ids on repeated saves', function () {
    $max = Schedule::max('id');

    for ($i = 0; $i < 5; $i++) {
        ($this->save)($this->hours)->assertOk();
    }

    expect(Schedule::max('id'))->toBe($max);
});
