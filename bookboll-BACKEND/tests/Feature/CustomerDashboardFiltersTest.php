<?php

/**
 * Every filter on the customers screen narrows the list.
 *
 * The filter sheet offered fourteen choices and the server applied three —
 * search, status, customer type. Country, city, venue, listing, program, sport,
 * currency, tier, gender, tags, marketing consent, joined range, spend range,
 * booking-count range and last activity were sent and read by nobody, so
 * "customers of this court" returned every customer, and a filtered export was
 * the full list under a filtered filename.
 *
 * Each case here asks the real endpoint with one filter and checks exactly who
 * comes back.
 */

use App\Models\Booking;
use App\Models\Listing;
use App\Models\User;
use App\Models\Venue;
use App\Support\BookingPurpose;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Spatie\Permission\Models\Role;

uses(RefreshDatabase::class);

beforeEach(function () {
    Role::findOrCreate('super_admin', 'web');
    Role::findOrCreate('customer', 'web');
    $this->admin = User::factory()->create(['email' => 'super@test.local', 'status' => User::ACTIVE]);
    $this->admin->assignRole('super_admin');

    // The vendor geography tables ship empty under RefreshDatabase and carry a
    // scatter of NOT NULL columns without defaults. Fill whatever each one
    // demands so the fixture does not break the next time a column is added.
    $insert = function (string $table, array $attrs): int {
        $required = DB::select("select column_name c, data_type t from information_schema.columns
            where table_schema = database() and table_name = ? and is_nullable = 'NO'
            and column_default is null and extra not like '%auto_increment%'", [$table]);
        foreach ($required as $col) {
            if (! array_key_exists($col->c, $attrs)) {
                $attrs[$col->c] = in_array($col->t, ['int', 'bigint', 'tinyint', 'smallint', 'decimal', 'double', 'float'], true) ? 0 : 'x';
            }
        }

        return DB::table($table)->insertGetId($attrs);
    };
    $qatar  = $insert('countries', ['iso2' => 'QA', 'iso3' => 'QAT', 'name' => 'Qatar', 'status' => 1]);
    $pk     = $insert('countries', ['iso2' => 'PK', 'iso3' => 'PAK', 'name' => 'Pakistan', 'status' => 1]);
    $stateQ = $insert('states', ['name' => 'Doha', 'country_id' => $qatar]);
    $stateP = $insert('states', ['name' => 'Punjab', 'country_id' => $pk]);
    $doha   = $insert('cities', ['name' => 'Doha', 'country_id' => $qatar, 'state_id' => $stateQ]);
    $lahore = $insert('cities', ['name' => 'Lahore', 'country_id' => $pk, 'state_id' => $stateP]);
    $this->ids = compact('qatar', 'pk', 'doha', 'lahore');

    $this->venueA = Venue::create(['name' => 'Doha Padel', 'status' => Venue::ACTIVE, 'city_id' => $doha, 'country_id' => $qatar]);
    $this->venueB = Venue::create(['name' => 'Lahore Football', 'status' => Venue::ACTIVE, 'city_id' => $lahore, 'country_id' => $pk]);
    $this->padel  = Listing::create(['name' => 'Padel 1', 'venue_id' => $this->venueA->id, 'status' => 'active', 'base_price' => 100, 'capacity' => 4, 'slot_duration' => 60, 'sport_category' => 'Padel', 'payment_options' => []]);
    $this->pitch  = Listing::create(['name' => 'Pitch 1', 'venue_id' => $this->venueB->id, 'status' => 'active', 'base_price' => 100, 'capacity' => 18, 'slot_duration' => 60, 'sport_category' => 'Football', 'payment_options' => []]);

    // loyalty_tier_id is NOT NULL and the tiers are not guaranteed to exist under
    // RefreshDatabase, so make the two this test needs.
    $tier = fn (string $slug) => (int) (DB::table('loyalty_tiers')->where('slug', $slug)->value('id')
        ?? $insert('loyalty_tiers', ['name' => ucfirst($slug), 'slug' => $slug, 'min_points' => $slug === 'gold' ? 500 : 0, 'max_points' => 0, 'order' => 0, 'is_active' => 1]));
    $gold   = (object) ['id' => $tier('gold'), 'name' => DB::table('loyalty_tiers')->where('slug', 'gold')->value('name') ?? 'Gold'];
    $bronze = (object) ['id' => $tier('bronze'), 'name' => DB::table('loyalty_tiers')->where('slug', 'bronze')->value('name') ?? 'Bronze'];
    $this->goldName = $gold->name;

    // A new account is opted in to every channel by default (User::booted()), so
    // the consent each customer holds is stated outright: the filter assertions
    // below depend on Sara and Noor being opted OUT everywhere.
    $optedOut = ['marketing_email' => false, 'marketing_sms' => false, 'marketing_whatsapp' => false];
    $mk = function (string $email, array $attrs = []) use ($optedOut) {
        $u = User::factory()->create(array_merge(['email' => $email, 'status' => User::ACTIVE], $optedOut, $attrs));
        $u->assignRole('customer');

        return $u;
    };
    // Ali: Qatari, male, gold, booked padel in Doha twice recently, QAR, opted into email.
    $this->ali = $mk('ali@test.local', ['gender' => 'male', 'country_id' => $qatar, 'loyalty_tier_id' => $gold->id, 'marketing_email' => true, 'created_at' => now()->subDays(100)]);
    // Sara: Pakistani, female, bronze, booked football in Lahore once, 200 days ago, PKR.
    $this->sara = $mk('sara@test.local', ['gender' => 'female', 'country_id' => $pk, 'loyalty_tier_id' => $bronze->id, 'marketing_email' => false, 'created_at' => now()->subDays(5)]);
    // Noor: never booked anything, gender not given.
    $this->noor = $mk('noor@test.local', ['gender' => null, 'country_id' => $qatar, 'loyalty_tier_id' => $bronze->id, 'created_at' => now()->subDays(50)]);

    $book = fn (User $u, Listing $l, int $daysAgo, float $price, string $cur) => Booking::create([
        'user_id' => $u->id, 'venue_id' => $l->venue_id, 'listing_id' => $l->id,
        'date' => now()->subDays($daysAgo)->toDateString(), 'status' => 'confirmed',
        'record_purpose' => BookingPurpose::SALE, 'currency' => $cur,
        'subtotal' => $price, 'total_price' => $price,
    ]);
    $book($this->ali, $this->padel, 3, 150, 'QAR');
    $book($this->ali, $this->padel, 20, 150, 'QAR');
    $book($this->sara, $this->pitch, 200, 2000, 'PKR');

    $this->ali->syncTags(['vip']);

    $this->emails = function (array $query) {
        $res = $this->actingAs($this->admin)->withHeaders(['from' => 'admin-web'])
            ->getJson('/api/admin/customers/dashboard?' . http_build_query($query + ['include_lookups' => 0]));
        $res->assertOk();
        $rows = $res->json('data.customers.data') ?? $res->json('data.customers') ?? [];

        return collect($rows)->pluck('email')->sort()->values()->all();
    };
});

it('returns everyone when nothing is filtered', function () {
    expect(($this->emails)([]))->toBe(['ali@test.local', 'noor@test.local', 'sara@test.local']);
});

it('filters by listing', function () {
    expect(($this->emails)(['listing_id' => [$this->padel->id]]))->toBe(['ali@test.local'])
        ->and(($this->emails)(['listing_id' => [$this->pitch->id]]))->toBe(['sara@test.local']);
});

it('filters by venue and by the city booked in', function () {
    expect(($this->emails)(['location_id' => [$this->venueB->id]]))->toBe(['sara@test.local'])
        ->and(($this->emails)(['city_id' => [$this->ids['doha']]]))->toBe(['ali@test.local']);
});

it('filters by registered country', function () {
    expect(($this->emails)(['country_id' => [$this->ids['qatar']]]))->toBe(['ali@test.local', 'noor@test.local']);
});

it('filters by sport and by currency', function () {
    expect(($this->emails)(['sport' => ['Football']]))->toBe(['sara@test.local'])
        ->and(($this->emails)(['currency' => ['QAR']]))->toBe(['ali@test.local']);
});

it('filters by gender, including "not specified"', function () {
    expect(($this->emails)(['gender' => ['female']]))->toBe(['sara@test.local'])
        ->and(($this->emails)(['gender' => ['other']]))->toBe(['noor@test.local'])
        ->and(($this->emails)(['gender' => ['male', 'other']]))->toBe(['ali@test.local', 'noor@test.local']);
});

it('filters by loyalty tier name, tag and marketing consent', function () {
    expect(($this->emails)(['tier' => $this->goldName]))->toBe(['ali@test.local'])
        ->and(($this->emails)(['tag' => ['vip']]))->toBe(['ali@test.local'])
        ->and(($this->emails)(['marketing' => ['email']]))->toBe(['ali@test.local'])
        ->and(($this->emails)(['marketing' => ['none']]))->toBe(['noor@test.local', 'sara@test.local']);
});

it('filters by when they joined', function () {
    expect(($this->emails)(['joined_from' => now()->subDays(10)->toDateString()]))->toBe(['sara@test.local'])
        ->and(($this->emails)(['joined_to' => now()->subDays(60)->toDateString()]))->toBe(['ali@test.local']);
});

it('filters by spend and by number of bookings', function () {
    expect(($this->emails)(['spend_min' => 250]))->toBe(['ali@test.local', 'sara@test.local'])
        ->and(($this->emails)(['spend_min' => 250, 'currency' => ['QAR']]))->toBe(['ali@test.local'])
        ->and(($this->emails)(['spend_max' => 500]))->toBe(['ali@test.local'])
        ->and(($this->emails)(['bk_min' => 2]))->toBe(['ali@test.local'])
        ->and(($this->emails)(['bk_max' => 1]))->toBe(['sara@test.local']);
});

it('filters by last activity, driven by bookings not logins', function () {
    expect(($this->emails)(['last_activity' => '7d']))->toBe(['ali@test.local'])
        ->and(($this->emails)(['last_activity' => '30d']))->toBe(['ali@test.local'])
        ->and(($this->emails)(['last_activity' => 'inactive']))->toBe(['sara@test.local'])
        ->and(($this->emails)(['last_activity' => 'never']))->toBe(['noor@test.local']);
});

it('combines filters with AND, and echoes what it applied', function () {
    $res = $this->actingAs($this->admin)->withHeaders(['from' => 'admin-web'])
        ->getJson('/api/admin/customers/dashboard?' . http_build_query(['listing_id' => [$this->padel->id], 'gender' => ['female'], 'include_lookups' => 0]))
        ->assertOk();

    expect(collect($res->json('data.customers.data') ?? $res->json('data.customers'))->count())->toBe(0)
        ->and($res->json('data.filters_applied.listing_id'))->toBe([$this->padel->id])
        ->and($res->json('data.filters_applied.gender'))->toBe(['female']);
});

it('keeps the headline counts in step with the filtered rows', function () {
    $res = $this->actingAs($this->admin)->withHeaders(['from' => 'admin-web'])
        ->getJson('/api/admin/customers/dashboard?' . http_build_query(['listing_id' => [$this->padel->id], 'include_lookups' => 1]))
        ->assertOk();

    expect((int) ($res->json('data.customers.total') ?? 0))->toBe(1);
});
