<?php

use App\Models\ClassProgram;
use App\Models\HostedGame;
use App\Models\Venue;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Nnjeim\World\Models\City;
use Nnjeim\World\Models\Country;
use Nnjeim\World\Models\State;

uses(RefreshDatabase::class);

function setupQatarGeo(): array
{
    $qatar = Country::create([
        'name' => 'Qatar',
        'iso2' => 'QA',
        'iso3' => 'QAT',
        'phone_code' => '974',
        'region' => 'Asia',
        'subregion' => 'Western Asia',
        'status' => 1,
    ]);

    $state = State::create([
        'name' => 'Ad Dawhah',
        'country_id' => $qatar->id,
        'country_code' => 'QAT',
    ]);

    $doha = City::create([
        'name' => 'Doha',
        'country_id' => $qatar->id,
        'country_code' => 'QAT',
        'state_id' => $state->id,
        'status' => 1,
    ]);

    $alWakrah = City::create([
        'name' => 'Al Wakrah',
        'country_id' => $qatar->id,
        'country_code' => 'QAT',
        'state_id' => $state->id,
        'status' => 1,
    ]);

    return [$qatar, $doha, $alWakrah];
}

test('programs in Qatar expand to all cities in Qatar', function () {
    [$qatar, $doha, $alWakrah] = setupQatarGeo();

    $venueDoha = Venue::create([
        'name' => 'Doha Padel Club',
        'country_id' => $qatar->id,
        'city_id' => $doha->id,
        'status' => 'active',
        'maps_url' => 'https://maps.google.com/?q=25.28,51.53',
        'min_advance_booking' => 0,
        'max_bookings_per_slot' => 1,
    ]);

    $venueWakrah = Venue::create([
        'name' => 'Wakrah Football Arena',
        'country_id' => $qatar->id,
        'city_id' => $alWakrah->id,
        'status' => 'active',
        'maps_url' => 'https://maps.google.com/?q=25.17,51.60',
        'min_advance_booking' => 0,
        'max_bookings_per_slot' => 1,
    ]);

    ClassProgram::create([
        'name' => 'Doha Junior Academy',
        'venue_id' => $venueDoha->id,
        'status' => 'active',
        'approval_status' => 'approved',
        'show_on_marketplace' => true,
    ]);

    ClassProgram::create([
        'name' => 'Wakrah Adult Coaching',
        'venue_id' => $venueWakrah->id,
        'status' => 'active',
        'approval_status' => 'approved',
        'show_on_marketplace' => true,
    ]);

    // Querying with Doha's city_id should return both Doha and Wakrah programs
    $response = $this->getJson("/api/marketplace/programs?city_id={$doha->id}");
    $response->assertOk();
    $programs = collect($response->json('data.programs'));
    expect($programs->pluck('name'))->toContain('Doha Junior Academy');
    expect($programs->pluck('name'))->toContain('Wakrah Adult Coaching');

    // Querying with country_iso=QA should also return both
    $responseIso = $this->getJson('/api/marketplace/programs?country_iso=QA');
    $responseIso->assertOk();
    $programsIso = collect($responseIso->json('data.programs'));
    expect($programsIso->pluck('name'))->toContain('Doha Junior Academy');
    expect($programsIso->pluck('name'))->toContain('Wakrah Adult Coaching');
});

test('events/games in Qatar expand to all cities in Qatar', function () {
    [$qatar, $doha, $alWakrah] = setupQatarGeo();

    HostedGame::create([
        'title' => 'Doha Evening Football',
        'sport' => 'Football',
        'country_id' => $qatar->id,
        'city_id' => $doha->id,
        'status' => 'open',
        'moderation_status' => 'approved',
        'game_date' => now()->addDay()->toDateString(),
        'start_time' => '18:00',
        'end_time' => '19:00',
        'published_at' => now(),
    ]);

    HostedGame::create([
        'title' => 'Wakrah Padel Match',
        'sport' => 'Padel',
        'country_id' => $qatar->id,
        'city_id' => $alWakrah->id,
        'status' => 'open',
        'moderation_status' => 'approved',
        'game_date' => now()->addDay()->toDateString(),
        'start_time' => '20:00',
        'end_time' => '21:00',
        'published_at' => now(),
    ]);

    // Querying with Wakrah city_id should return both Doha and Wakrah events
    $response = $this->getJson("/api/marketplace/games?city_id={$alWakrah->id}");
    $response->assertOk();
    $games = collect($response->json('data.games'));
    expect($games->pluck('title'))->toContain('Doha Evening Football');
    expect($games->pluck('title'))->toContain('Wakrah Padel Match');

    // Querying with country_iso=QA should also return both
    $responseIso = $this->getJson('/api/marketplace/games?country_iso=QA');
    $responseIso->assertOk();
    $gamesIso = collect($responseIso->json('data.games'));
    expect($gamesIso->pluck('title'))->toContain('Doha Evening Football');
    expect($gamesIso->pluck('title'))->toContain('Wakrah Padel Match');
});

test('non-Qatar countries still filter strictly by city', function () {
    $uae = Country::create([
        'name' => 'United Arab Emirates',
        'iso2' => 'AE',
        'iso3' => 'ARE',
        'phone_code' => '971',
        'region' => 'Asia',
        'subregion' => 'Western Asia',
        'status' => 1,
    ]);

    $stateDubai = State::create([
        'name' => 'Dubai',
        'country_id' => $uae->id,
        'country_code' => 'ARE',
    ]);

    $dubai = City::create([
        'name' => 'Dubai',
        'country_id' => $uae->id,
        'country_code' => 'ARE',
        'state_id' => $stateDubai->id,
        'status' => 1,
    ]);

    $abuDhabi = City::create([
        'name' => 'Abu Dhabi',
        'country_id' => $uae->id,
        'country_code' => 'ARE',
        'state_id' => $stateDubai->id,
        'status' => 1,
    ]);

    HostedGame::create([
        'title' => 'Dubai Football',
        'sport' => 'Football',
        'country_id' => $uae->id,
        'city_id' => $dubai->id,
        'status' => 'open',
        'moderation_status' => 'approved',
        'game_date' => now()->addDay()->toDateString(),
        'start_time' => '18:00',
        'end_time' => '19:00',
        'published_at' => now(),
    ]);

    HostedGame::create([
        'title' => 'Abu Dhabi Padel',
        'sport' => 'Padel',
        'country_id' => $uae->id,
        'city_id' => $abuDhabi->id,
        'status' => 'open',
        'moderation_status' => 'approved',
        'game_date' => now()->addDay()->toDateString(),
        'start_time' => '20:00',
        'end_time' => '21:00',
        'published_at' => now(),
    ]);

    // Querying Dubai should only return Dubai
    $res = $this->getJson("/api/marketplace/games?city_id={$dubai->id}");
    $res->assertOk();
    $games = collect($res->json('data.games'));
    expect($games->pluck('title'))->toContain('Dubai Football');
    expect($games->pluck('title'))->not->toContain('Abu Dhabi Padel');
});
