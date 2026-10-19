<?php

/**
 * Categories were ordered by how many listings carried them.
 *
 * That measures inventory, not demand: one operator with forty padel courts
 * and a single football pitch made padel look like what the city wants. The
 * document asks for ordering by what visitors actually choose, counted per
 * scope — the marketplace and each provider storefront separately — with an
 * endpoint that receives those choices.
 *
 * These pin the parts that are easy to get subtly wrong: that a client cannot
 * invent a category or a count, that one person cannot manufacture a ranking,
 * that a provider's storefront never reorders the marketplace, and that a
 * city borrowing its country's ranking says so.
 */

use App\Models\Business;
use App\Models\Listing;
use App\Models\Venue;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Nnjeim\World\Models\City;
use Nnjeim\World\Models\Country;
use Nnjeim\World\Models\State;

uses(RefreshDatabase::class);

function popCountry(string $name, string $iso2, string $iso3): Country
{
    return Country::create([
        'name' => $name, 'iso2' => $iso2, 'iso3' => $iso3,
        'phone_code' => '0', 'region' => 'Asia', 'subregion' => 'Asia', 'status' => 1,
    ]);
}

function popCity(Country $country, string $name): City
{
    $state = State::firstOrCreate(
        ['name' => $country->name.' State', 'country_id' => $country->id],
        ['country_code' => $country->iso3],
    );

    return City::create([
        'name' => $name, 'country_id' => $country->id,
        'country_code' => $country->iso3, 'state_id' => $state->id, 'status' => 1,
    ]);
}

function popListing(City $city, string $category, string $sport = '', array $over = []): Listing
{
    $venue = Venue::create([
        'name' => 'Venue '.uniqid(), 'country_id' => $city->country_id, 'city_id' => $city->id,
        'status' => Venue::ACTIVE, 'maps_url' => 'https://maps.google.com/?q=0,0',
        'min_advance_booking' => 0, 'max_bookings_per_slot' => 1,
    ]);

    return Listing::create(array_merge([
        'name' => 'Court '.uniqid(), 'venue_id' => $venue->id, 'status' => 'active',
        'category' => $category, 'sport_category' => $sport,
        'base_price' => 45, 'capacity' => 4, 'slot_duration' => 1,
        'payment_options' => ['cash_on_arrival' => '0'], 'max_bookings_per_slot' => 1,
    ], $over));
}

/** A real visitor: a browser user agent, posting from the storefront. */
function clickAs(array $payload, string $agent = 'Mozilla/5.0 (Macintosh) Safari/605.1', string $ip = '203.0.113.7')
{
    return test()->withHeader('from', 'frontend-web')
        ->withHeader('User-Agent', $agent)
        ->withServerVariables(['REMOTE_ADDR' => $ip])
        ->postJson('/api/marketplace/category-clicks', $payload);
}

/** Category ranking as the catalogue reports it, highest first. */
function rankingFor(string $query = ''): array
{
    $json = test()->withHeader('from', 'frontend-web')
        ->getJson('/api/marketplace/listings'.($query ? "?{$query}" : ''))
        ->assertOk()
        ->json('data');

    return collect($json['category_popularity'] ?? [])
        ->mapWithKeys(fn ($r) => [$r['category'].($r['type'] ? '/'.$r['type'] : '') => $r['click_count']])
        ->all();
}

beforeEach(function () {
    $qatar = popCountry('Qatar', 'QA', 'QAT');
    $this->doha = popCity($qatar, 'Doha');
    $this->wakrah = popCity($qatar, 'Al Wakrah');

    $pakistan = popCountry('Pakistan', 'PK', 'PAK');
    $this->lahore = popCity($pakistan, 'Lahore');
    $this->karachi = popCity($pakistan, 'Karachi');

    popListing($this->doha, 'sports', 'Padel');
    popListing($this->doha, 'sports', 'Football');
    popListing($this->doha, 'fitness');
    popListing($this->lahore, 'sports', 'Cricket');
    popListing($this->karachi, 'sports', 'Cricket');
});

it('orders categories by what visitors chose, not by how many listings exist', function () {
    // Inventory says padel and football are equal. Choices say otherwise.
    foreach ([['Padel', '198.51.100.1'], ['Padel', '198.51.100.2'], ['Football', '198.51.100.3']] as [$sport, $ip]) {
        clickAs(['category' => 'sports', 'type' => $sport, 'source' => 'home',
            'city_id' => $this->doha->id, 'country_iso' => 'QA'], ip: $ip)
            ->assertOk()->assertJsonPath('data.counted', true);
    }

    expect(rankingFor("city_id={$this->doha->id}&city=Doha&country_iso=QA"))
        ->toBe(['sports/Padel' => 2, 'sports/Football' => 1]);
});

it('advertises click tracking only because the endpoint that receives clicks exists', function () {
    $data = test()->withHeader('from', 'frontend-web')
        ->getJson('/api/marketplace/listings')->assertOk()->json('data');

    expect($data['category_click_tracking_enabled'])->toBeTrue();
    expect(route('marketplace.category-clicks'))->toContain('marketplace/category-clicks');
});

it('refuses a category that is not in the catalogue', function () {
    clickAs(['category' => 'helicopter-rides', 'source' => 'home', 'city_id' => $this->doha->id])
        ->assertStatus(422);

    clickAs(['category' => 'sports', 'type' => 'Quidditch', 'source' => 'home', 'city_id' => $this->doha->id])
        ->assertStatus(422);

    expect(DB::table('category_clicks')->count())->toBe(0);
});

it('takes no count from the client, only the fact of a selection', function () {
    clickAs(['category' => 'sports', 'type' => 'Padel', 'source' => 'home',
        'city_id' => $this->doha->id, 'click_count' => 5000, 'count' => 5000])->assertOk();

    expect(rankingFor("city_id={$this->doha->id}&city=Doha&country_iso=QA"))->toBe(['sports/Padel' => 1]);
});

it('counts one person clicking the same chip repeatedly as one opinion', function () {
    foreach (range(1, 4) as $ignored) {
        clickAs(['category' => 'sports', 'type' => 'Padel', 'source' => 'home',
            'city_id' => $this->doha->id])->assertOk();
    }

    expect(DB::table('category_clicks')->count())->toBe(1);

    // Still one opinion when they switch between Home and the catalogue.
    clickAs(['category' => 'sports', 'type' => 'Padel', 'source' => 'catalog',
        'city_id' => $this->doha->id])->assertOk()->assertJsonPath('data.counted', false);

    expect(DB::table('category_clicks')->count())->toBe(1);
});

it('lets the same person choose a different category, and choose again later', function () {
    clickAs(['category' => 'sports', 'type' => 'Padel', 'source' => 'home', 'city_id' => $this->doha->id])->assertOk();
    clickAs(['category' => 'fitness', 'source' => 'home', 'city_id' => $this->doha->id])
        ->assertOk()->assertJsonPath('data.counted', true);

    // An hour on, the same chip is a fresh visit rather than a double-click.
    DB::table('category_clicks')->update(['created_at' => now()->subHours(2)]);
    clickAs(['category' => 'sports', 'type' => 'Padel', 'source' => 'home', 'city_id' => $this->doha->id])
        ->assertOk()->assertJsonPath('data.counted', true);

    expect(rankingFor("city_id={$this->doha->id}&city=Doha&country_iso=QA"))
        ->toBe(['sports/Padel' => 2, 'fitness' => 1]);
});

it('caps how much ranking one visitor can manufacture', function () {
    $rows = [];
    foreach (range(1, \App\Services\CategoryPopularityService::VISITOR_CAP) as $i) {
        $rows[] = [
            'category' => 'sports', 'type' => 'Padel', 'source' => 'home',
            'city_id' => $this->doha->id, 'country_id' => $this->doha->country_id,
            'brand_slug' => null,
            'visitor_hash' => hash('sha256', implode('|', [
                config('app.key'), '203.0.113.7', 'Mozilla/5.0 (Macintosh) Safari/605.1',
            ])),
            'created_at' => now()->subHours(3)->subMinutes($i),
        ];
    }
    DB::table('category_clicks')->insert($rows);

    clickAs(['category' => 'fitness', 'source' => 'home', 'city_id' => $this->doha->id])
        ->assertOk()->assertJsonPath('data.counted', false);

    // Someone else is unaffected by that visitor hitting the cap.
    clickAs(['category' => 'fitness', 'source' => 'home', 'city_id' => $this->doha->id], ip: '198.51.100.44')
        ->assertOk()->assertJsonPath('data.counted', true);
});

it('does not count crawlers or a provider previewing their own storefront', function () {
    foreach (['Googlebot/2.1 (+http://www.google.com/bot.html)', 'curl/8.4.0', 'python-requests/2.31', ''] as $agent) {
        clickAs(['category' => 'sports', 'type' => 'Padel', 'source' => 'home',
            'city_id' => $this->doha->id], agent: $agent)->assertOk()->assertJsonPath('data.counted', false);
    }

    clickAs(['category' => 'sports', 'type' => 'Padel', 'source' => 'home',
        'city_id' => $this->doha->id, 'preview' => true])->assertOk()->assertJsonPath('data.counted', false);

    expect(DB::table('category_clicks')->count())->toBe(0);
});

it('keeps each city separate and never lets one city reorder another', function () {
    clickAs(['category' => 'sports', 'type' => 'Padel', 'source' => 'home',
        'city_id' => $this->doha->id, 'country_iso' => 'QA'])->assertOk();

    expect(rankingFor("city_id={$this->lahore->id}&city=Lahore&country_iso=PK"))->toBe([]);
    expect(rankingFor("city_id={$this->doha->id}&city=Doha&country_iso=QA"))->toBe(['sports/Padel' => 1]);
});

it('says when a city borrowed its country ranking instead of implying it earned it', function () {
    clickAs(['category' => 'sports', 'type' => 'Cricket', 'source' => 'home',
        'city_id' => $this->lahore->id, 'country_iso' => 'PK'])->assertOk();

    // Karachi has no choices of its own yet, so it shows Pakistan's — and the
    // response has to say that, or the ranking reads as locally earned.
    $borrowed = test()->withHeader('from', 'frontend-web')
        ->getJson("/api/marketplace/listings?city_id={$this->karachi->id}&city=Karachi&country_iso=PK")
        ->assertOk()->json('data');

    expect($borrowed['category_popularity'])->toBe([['category' => 'sports', 'type' => 'Cricket', 'click_count' => 1]]);
    expect($borrowed['category_popularity_scope']['fallback'])->toBe('country');
    expect($borrowed['category_popularity_scope']['country_iso'])->toBe('PK');

    // Lahore's own ranking is its own, and is not labelled a fallback.
    $own = test()->withHeader('from', 'frontend-web')
        ->getJson("/api/marketplace/listings?city_id={$this->lahore->id}&city=Lahore&country_iso=PK")
        ->assertOk()->json('data.category_popularity_scope');

    expect($own['fallback'])->toBeNull();
    expect($own['city_id'])->toBe($this->lahore->id);
});

it('ranks a one-market country at country level without calling it a fallback', function () {
    // Qatar is a single marketplace: /venues/doha already lists Al Wakrah, so
    // the ranking is genuinely the country's, not Doha's borrowed by Doha.
    clickAs(['category' => 'sports', 'type' => 'Padel', 'source' => 'home',
        'city_id' => $this->wakrah->id, 'country_iso' => 'QA'])->assertOk();

    $scope = test()->withHeader('from', 'frontend-web')
        ->getJson("/api/marketplace/listings?city_id={$this->doha->id}&city=Doha&country_iso=QA")
        ->assertOk()->json('data.category_popularity_scope');

    expect($scope['city_id'])->toBeNull();
    expect($scope['country_iso'])->toBe('QA');
    expect($scope['fallback'])->toBeNull();
});

it('discards a city that does not belong to the country the client claimed', function () {
    // Lahore with country_iso=QA is not a scope; it must not be stored as one.
    clickAs(['category' => 'sports', 'type' => 'Cricket', 'source' => 'home',
        'city_id' => $this->lahore->id, 'country_iso' => 'QA'])->assertOk();

    $row = DB::table('category_clicks')->first();
    expect($row->city_id)->toBeNull();
    expect(rankingFor("city_id={$this->lahore->id}&city=Lahore&country_iso=PK"))->toBe([]);
});

it('keeps a provider storefront ranking out of the marketplace and vice versa', function () {
    $business = Business::create([
        'owner_id' => null, 'name' => 'North Club', 'username' => 'north-club',
        'status' => Business::STATUS_ACTIVE, 'plan' => 'free',
    ]);

    clickAs(['category' => 'fitness', 'source' => 'home',
        'city_id' => $this->doha->id, 'brand_slug' => $business->username])->assertOk();
    clickAs(['category' => 'sports', 'type' => 'Padel', 'source' => 'home',
        'city_id' => $this->doha->id], ip: '198.51.100.9')->assertOk();

    // The marketplace sees only its own choices.
    expect(rankingFor("city_id={$this->doha->id}&city=Doha&country_iso=QA"))->toBe(['sports/Padel' => 1]);

    $brandRows = DB::table('category_clicks')->whereNotNull('brand_slug')->get();
    expect($brandRows)->toHaveCount(1);
    expect($brandRows[0]->category)->toBe('fitness');
});

it('reports the same window the server actually counted', function () {
    clickAs(['category' => 'sports', 'type' => 'Padel', 'source' => 'home', 'city_id' => $this->doha->id])->assertOk();
    DB::table('category_clicks')->update([
        'created_at' => now()->subDays(\App\Services\CategoryPopularityService::WINDOW_DAYS + 1),
    ]);

    $data = test()->withHeader('from', 'frontend-web')
        ->getJson("/api/marketplace/listings?city_id={$this->doha->id}&city=Doha&country_iso=QA")
        ->assertOk()->json('data');

    expect($data['category_popularity'])->toBe([]);
    expect($data['category_popularity_scope']['window_days'])
        ->toBe(\App\Services\CategoryPopularityService::WINDOW_DAYS);
});

it('echoes the scope in terms the storefront can verify against its own request', function () {
    // The storefront drops the counts if this block names a scope it did not
    // ask for, so it must not carry internal ids the client never sent.
    clickAs(['category' => 'sports', 'type' => 'Cricket', 'source' => 'home',
        'city_id' => $this->lahore->id, 'country_iso' => 'PK'])->assertOk();

    $scope = test()->withHeader('from', 'frontend-web')
        ->getJson("/api/marketplace/listings?city_id={$this->lahore->id}&city=Lahore&country_iso=PK")
        ->assertOk()->json('data.category_popularity_scope');

    expect(array_keys($scope))->toBe(['city_id', 'country_iso', 'brand_slug', 'fallback', 'window_days']);
    expect($scope['city_id'])->toBe($this->lahore->id);
    expect($scope['country_iso'])->toBe('PK');
    expect($scope['brand_slug'])->toBeNull();
});

it('echoes the brand slug the storefront asked with, and never one it did not', function () {
    $business = Business::create([
        'owner_id' => null, 'name' => 'North Club', 'username' => 'north-club',
        'status' => Business::STATUS_ACTIVE, 'plan' => 'free',
    ]);

    $scope = test()->withHeader('from', 'frontend-web')
        ->getJson("/api/marketplace/listings?brand_slug={$business->username}&city_id={$this->lahore->id}&city=Lahore&country_iso=PK")
        ->assertOk()->json('data.category_popularity_scope');

    expect($scope['brand_slug'])->toBe('north-club');
});
