<?php

/**
 * Saving edits the pricing group rows; it does not replace them.
 *
 * syncPricing() used to delete every group for the listing and insert the
 * payload fresh, so a group kept its identity only until the next Save: a block
 * of auto-increment ids burned per save, created_at/created_by reset to whoever
 * saved last, an activity-log create+delete pair for every row, and the
 * `source_id` the slot API reports moving for a group nobody had touched.
 *
 * This watches the actual SQL, because reading the method cannot prove what it
 * emits.
 */

use App\Models\Listing;
use App\Models\PricingGroup;
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

    $venue = Venue::create([
        'name' => 'Pitch', 'status' => Venue::ACTIVE,
        'maps_url' => 'https://maps.google.com/?q=0,0',
        'min_advance_booking' => 0, 'max_bookings_per_slot' => 1,
    ]);
    $this->listing = Listing::create([
        'name' => 'Court', 'venue_id' => $venue->id, 'status' => 'active',
        'base_price' => 250, 'capacity' => 10, 'slot_duration' => 60,
        'payment_options' => ['cash_on_arrival' => true], 'max_bookings_per_slot' => 1,
    ]);

    foreach ([['Peak', '19:00', '22:00', 400, '1,2'], ['Late', '22:00', '00:00', 300, '5,6']] as $i => [$n, $s, $e, $p, $d]) {
        PricingGroup::create([
            'listing_id' => $this->listing->id, 'name' => $n, 'start_time' => $s,
            'end_time' => $e, 'price_per_hour' => $p, 'days' => explode(',', $d), 'sort_order' => $i,
        ]);
    }

    $this->payload = function (array $overrides = []) {
        $rows = PricingGroup::where('listing_id', $this->listing->id)->orderBy('sort_order')->get();

        return $rows->map(fn ($g, $i) => array_replace([
            'id' => (string) $g->id, 'name' => $g->name, 'price' => (float) $g->price_per_hour,
            'sort_order' => (int) $g->sort_order, 'start_time' => $g->start_time,
            'end_time' => $g->end_time, 'days' => implode(',', $g->days),
        ], $overrides[$i] ?? []))->all();
    };

    $this->save = fn (array $groups) => $this->actingAs($this->staff)
        ->withHeaders(['from' => 'admin-web'])
        ->putJson('/api/admin/listings/dashboard/' . $this->listing->id, [
            'name' => 'Court', 'location_id' => $this->listing->venue_id, 'description' => 'x',
            'base_price' => 250, 'slot_duration' => 60,
            'pricing_groups' => $groups, 'pricing_cells' => [],
        ]);

    $this->watch = function (callable $run) {
        $sql = [];
        DB::listen(function ($q) use (&$sql) {
            if (str_contains($q->sql, 'pricing_groups')) {
                $sql[] = $q->sql;
            }
        });
        $run();
        DB::flushQueryLog();

        return collect($sql);
    };
});

it('issues no insert and no delete when nothing changed', function () {
    $before = PricingGroup::where('listing_id', $this->listing->id)->orderBy('id')->get();

    $sql = ($this->watch)(fn () => ($this->save)(($this->payload)())->assertOk());

    expect($sql->filter(fn ($q) => str_starts_with($q, 'insert')))->toBeEmpty()
        ->and($sql->filter(fn ($q) => str_starts_with($q, 'delete')))->toBeEmpty()
        // Eloquent skips the UPDATE entirely when no attribute is dirty.
        ->and($sql->filter(fn ($q) => str_starts_with($q, 'update')))->toBeEmpty();

    $after = PricingGroup::where('listing_id', $this->listing->id)->orderBy('id')->get();
    expect($after->pluck('id')->all())->toBe($before->pluck('id')->all())
        ->and($after->pluck('updated_at')->map->toString()->all())
            ->toBe($before->pluck('updated_at')->map->toString()->all());
});

it('updates in place when a price changes', function () {
    $peak = PricingGroup::where('name', 'Peak')->sole();

    $sql = ($this->watch)(fn () => ($this->save)(($this->payload)([0 => ['price' => 555]]))->assertOk());

    expect($sql->filter(fn ($q) => str_starts_with($q, 'update')))->toHaveCount(1)
        ->and($sql->filter(fn ($q) => str_starts_with($q, 'insert')))->toBeEmpty()
        ->and($sql->filter(fn ($q) => str_starts_with($q, 'delete')))->toBeEmpty();

    expect(PricingGroup::find($peak->id))->not->toBeNull()
        ->and((float) PricingGroup::find($peak->id)->price_per_hour)->toBe(555.0);
});

it('inserts only the group that is actually new', function () {
    $ids = PricingGroup::where('listing_id', $this->listing->id)->pluck('id')->all();

    $p = ($this->payload)();
    $p[] = ['id' => 'tmp-g-1', 'name' => 'Grid 900', 'price' => 900, 'sort_order' => 2,
            'start_time' => '02:00', 'end_time' => '04:00', 'days' => '4'];

    $sql = ($this->watch)(fn () => ($this->save)($p)->assertOk());

    expect($sql->filter(fn ($q) => str_starts_with($q, 'insert')))->toHaveCount(1)
        ->and($sql->filter(fn ($q) => str_starts_with($q, 'delete')))->toBeEmpty();

    // The two that already existed kept their rows.
    expect(PricingGroup::whereIn('id', $ids)->count())->toBe(2);
});

it('deletes only the group the editor dropped', function () {
    $peak = PricingGroup::where('name', 'Peak')->sole();
    $late = PricingGroup::where('name', 'Late')->sole();

    ($this->save)([($this->payload)()[0]])->assertOk();

    expect(PricingGroup::find($peak->id))->not->toBeNull()
        ->and(PricingGroup::find($late->id))->toBeNull();
});

it('keeps created_at and created_by across many saves', function () {
    $before = PricingGroup::where('listing_id', $this->listing->id)->orderBy('id')
        ->get(['id', 'created_at', 'created_by']);

    for ($i = 0; $i < 5; $i++) {
        ($this->save)(($this->payload)([0 => ['price' => 400 + $i]]))->assertOk();
    }

    $after = PricingGroup::where('listing_id', $this->listing->id)->orderBy('id')
        ->get(['id', 'created_at', 'created_by']);

    expect($after->pluck('id')->all())->toBe($before->pluck('id')->all())
        ->and($after->pluck('created_at')->map->toString()->all())
            ->toBe($before->pluck('created_at')->map->toString()->all());

    // Five saves, and the ids never advanced — the old code burned a block each time.
    expect(PricingGroup::max('id'))->toBe($before->max('id'));
});
