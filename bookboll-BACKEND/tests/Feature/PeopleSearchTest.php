<?php

/**
 * The Search page's People tab had no source and said "coming soon".
 *
 * The only user data on the server was the admin customer list — tenant-scoped
 * staff data carrying email, phone and booking history. The document is
 * explicit that People must not scrape it, and must not pretend it can find
 * everyone either.
 *
 * These pin the parts that matter if this is wrong: that nothing private
 * leaves the server, that a short query is not reported as an empty database,
 * that paging cannot repeat or lose people, and that the count does not claim
 * more than it checked.
 */

use App\Models\GameJoinRequest;
use App\Models\HostedGame;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\Sanctum;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Str;
use Spatie\Permission\Models\Role;

uses(RefreshDatabase::class);

beforeEach(function () {
    foreach (['super_admin', 'customer', 'business'] as $r) {
        Role::findOrCreate($r, 'web');
    }
});

function player(string $name, array $over = []): User
{
    $u = User::factory()->create(array_merge([
        'name' => $name, 'status' => User::ACTIVE, 'uuid' => (string) Str::uuid(),
    ], $over));
    $u->assignRole('customer');

    return $u->fresh();
}

/**
 * Many customers at once, without a model round trip each.
 *
 * Only for volume tests — anything asserting behaviour uses player() so the
 * row goes through the same path real sign-ups do.
 */
function bulkPlayers(string $prefix, int $count): void
{
    $roleId = Role::findOrCreate('customer', 'web')->id;
    $rows = [];
    foreach (range(1, $count) as $i) {
        $rows[] = [
            'name' => "{$prefix} {$i}", 'uuid' => (string) Str::uuid(),
            'email' => "crowd{$i}@example.com", 'password' => bcrypt('x'),
            'status' => User::ACTIVE, 'is_discoverable' => true,
            'created_at' => now(), 'updated_at' => now(),
        ];
    }
    foreach (array_chunk($rows, 200) as $chunk) {
        DB::table('users')->insert($chunk);
    }

    $ids = DB::table('users')->where('name', 'like', $prefix.' %')->pluck('id');
    DB::table('model_has_roles')->insert($ids->map(fn ($id) => [
        'role_id' => $roleId, 'model_type' => User::class, 'model_id' => $id,
    ])->all());
}

/** The endpoint as the storefront calls it: signed out. */
function findPeople(array $query = [])
{
    return test()->withHeader('from', 'frontend-web')
        ->getJson('/api/marketplace/users?'.http_build_query($query));
}

function namesFound(array $query): array
{
    return collect(findPeople($query)->assertOk()->json('data.users'))->pluck('name')->all();
}

it('finds people by name and ranks exact, then prefix, then contains', function () {
    player('Ali');
    player('Ali Raza');
    player('Khalid Ali');

    expect(namesFound(['q' => 'ali']))->toBe(['Ali', 'Ali Raza', 'Khalid Ali']);
});

it('matches every word in any order, and treats wildcards as literal text', function () {
    player('Ali Raza Khan');
    player('Raza Ali');
    player('Hundred % Club');

    expect(namesFound(['q' => 'khan ali']))->toBe(['Ali Raza Khan']);

    // A % or _ in the query must match literally, not as a wildcard.
    expect(namesFound(['q' => 'd %']))->toBe(['Hundred % Club']);
    expect(namesFound(['q' => '_a']))->toBe([]);
});

it('never returns anything the public profile page keeps private', function () {
    $p = player('Private Player', [
        'email' => 'secret@example.com', 'phone' => '+97450001111',
        'bio' => 'my private bio', 'is_private' => true,
        'date_of_birth' => '1990-01-01', 'address' => '12 Hidden Street',
    ]);

    $card = findPeople(['q' => 'private'])->assertOk()->json('data.users.0');

    expect(array_keys($card))
        ->toBe(['id', 'url', 'name', 'avatar', 'isPrivate', 'hostedCount', 'joinedCount', 'joinedAt']);
    expect($card['id'])->toBe($p->uuid);
    expect($card['url'])->toBe('/u/'.$p->uuid);
    expect($card['isPrivate'])->toBeTrue();

    // Nothing private anywhere in the body, under any key name.
    $body = findPeople(['q' => 'private'])->getContent();
    foreach (['secret@example.com', '+97450001111', 'my private bio', '1990-01-01', 'Hidden Street', '"'.$p->id.'"'] as $leak) {
        expect($body)->not->toContain($leak);
    }
});

it('finds a private profile by name, the same way /u/:id already reaches it', function () {
    // is_private governs who sees posts and photos, not whether the person
    // exists; the profile page shows a stranger their name and avatar.
    player('Shy Player', ['is_private' => true]);

    expect(namesFound(['q' => 'shy']))->toBe(['Shy Player']);
});

it('leaves out anyone who turned off being found, but still shows them to themselves', function () {
    $hidden = player('Hidden Player', ['is_discoverable' => false]);
    player('Visible Player');

    expect(namesFound(['q' => 'player']))->toBe(['Visible Player']);

    Sanctum::actingAs($hidden);
    expect(namesFound(['q' => 'player']))->toBe(['Hidden Player', 'Visible Player']);
});

it('is a preference a customer can actually set and read back', function () {
    $p = player('Settings Player');
    Sanctum::actingAs($p);

    test()->postJson('/api/customer/update-profile', ['is_discoverable' => false])->assertOk();
    expect($p->fresh()->is_discoverable)->toBeFalse();

    test()->postJson('/api/customer/update-profile', ['is_discoverable' => true])->assertOk();
    expect($p->fresh()->is_discoverable)->toBeTrue();
});

it('defaults everyone who already existed to findable', function () {
    // The column was added to a live table; nobody silently disappeared.
    expect(player('Legacy Player')->is_discoverable)->toBeTrue();
    expect(namesFound(['q' => 'legacy']))->toBe(['Legacy Player']);
});

it('excludes non-customers and inactive accounts', function () {
    $staff = User::factory()->create(['name' => 'Staff Person', 'status' => User::ACTIVE, 'uuid' => (string) Str::uuid()]);
    $staff->assignRole('super_admin');

    $provider = User::factory()->create(['name' => 'Provider Person', 'status' => User::ACTIVE, 'uuid' => (string) Str::uuid()]);
    $provider->assignRole('business');

    player('Blocked Person', ['status' => User::INACTIVE]);
    player('Real Person');

    expect(namesFound(['q' => 'person']))->toBe(['Real Person']);
});

it('says a query is too short rather than reporting an empty database', function () {
    player('Ali');

    $data = findPeople(['q' => 'a'])->assertOk()->json('data');

    expect($data['searched'])->toBeFalse();
    expect($data['reason'])->toBe('query_too_short');
    expect($data['min_length'])->toBe(2);
    expect($data['coverage']['status'])->toBe('not_searched');
    expect($data['coverage']['total'])->toBeNull();
    expect($data['users'])->toBe([]);

    // A real search that finds nobody is a different answer entirely.
    $none = findPeople(['q' => 'zzzz'])->assertOk()->json('data');
    expect($none['searched'])->toBeTrue();
    expect($none['reason'])->toBeNull();
    expect($none['coverage'])->toBe(['status' => 'complete', 'total' => 0]);
});

it('reports a count it actually checked', function () {
    foreach (range(1, 3) as $i) {
        player("Counted Player {$i}");
    }

    expect(findPeople(['q' => 'counted'])->assertOk()->json('data.coverage'))
        ->toBe(['status' => 'complete', 'total' => 3]);
});

it('calls a count a floor rather than a total once it stops counting', function () {
    // The alternative is a full scan on every keystroke, or an exact-looking
    // number nobody checked. A floor that says it is a floor is the honest one.
    $ceiling = \App\Services\PeopleSearchService::COUNT_CEILING;
    bulkPlayers('Crowd Player', $ceiling + 1);

    expect(findPeople(['q' => 'crowd'])->assertOk()->json('data.coverage'))
        ->toBe(['status' => 'at_least', 'total' => $ceiling]);

    // One fewer and it is a real total again.
    DB::table('users')->where('name', 'Crowd Player 1')->delete();
    expect(findPeople(['q' => 'crowd'])->assertOk()->json('data.coverage'))
        ->toBe(['status' => 'complete', 'total' => $ceiling]);
});

it('pages without repeating or losing anyone', function () {
    foreach (range(1, 7) as $i) {
        player(sprintf('Paged Player %02d', $i));
    }

    $seen = [];
    $cursor = null;
    $pages = 0;

    do {
        $data = findPeople(array_filter(['q' => 'paged', 'limit' => 3, 'cursor' => $cursor]))
            ->assertOk()->json('data');
        $seen = array_merge($seen, collect($data['users'])->pluck('id')->all());
        $cursor = $data['next_cursor'];
        $pages++;
    } while ($cursor && $pages < 10);

    expect($pages)->toBe(3);
    expect($seen)->toHaveCount(7);
    expect(array_unique($seen))->toHaveCount(7);
});

it('refuses a page reference it cannot read instead of silently restarting', function () {
    player('Cursor Player');

    findPeople(['q' => 'cursor', 'cursor' => 'not-a-cursor'])->assertStatus(422);
    findPeople(['q' => 'cursor', 'cursor' => base64_encode('{"rank":1}')])->assertStatus(422);

    // The honest alternative — page one again — would repeat rows the caller
    // already has and look like duplicate people.
    expect(namesFound(['q' => 'cursor']))->toBe(['Cursor Player']);
});

it('caps the page size however much the caller asks for', function () {
    foreach (range(1, 4) as $i) {
        player("Limit Player {$i}");
    }

    expect(findPeople(['q' => 'limit', 'limit' => 2])->assertOk()->json('data.users'))->toHaveCount(2);
    findPeople(['q' => 'limit', 'limit' => 500])->assertStatus(422);
    findPeople(['q' => 'limit', 'limit' => 0])->assertStatus(422);
    findPeople(['q' => str_repeat('a', 121)])->assertStatus(422);
});

it('shows the activity counts the profile page already shows', function () {
    $host = player('Active Player');
    $other = player('Quiet Player');

    $game = fn (string $status) => HostedGame::create([
        'uuid' => (string) Str::uuid(), 'host_customer_id' => $host->uuid,
        'title' => 'Game', 'status' => 'open', 'moderation_status' => $status,
        // Unpublished games are invisible everywhere, including on the profile
        // page this count has to agree with.
        'published_at' => now(),
    ]);
    $open = $game('active');
    $game('active');
    $game('removed');

    foreach (['accepted', 'pending'] as $status) {
        GameJoinRequest::create([
            'uuid' => (string) Str::uuid(), 'game_id' => $open->id,
            'joiner_customer_id' => $host->uuid, 'status' => $status,
        ]);
    }

    $cards = collect(findPeople(['q' => 'player'])->assertOk()->json('data.users'))->keyBy('name');

    expect($cards['Active Player']['hostedCount'])->toBe(2);
    expect($cards['Active Player']['joinedCount'])->toBe(1);
    expect($cards['Quiet Player']['hostedCount'])->toBe(0);
});

it('states plainly that people search is not city-scoped', function () {
    player('Scope Player');

    // The document asks for a decision. Users carry no city, so a city filter
    // here would silently do nothing — which reads as "nobody plays near you".
    $scope = findPeople(['q' => 'scope'])->assertOk()->json('data.scope');

    expect($scope['kind'])->toBe('global');
    expect($scope['city_id'])->toBeNull();
    expect($scope['reason'])->toBe('people_have_no_city');
});

it('is reachable without signing in and is rate limited', function () {
    player('Public Player');

    expect(collect(Route::getRoutes())
        ->first(fn ($r) => $r->uri() === 'api/marketplace/users')
        ->gatherMiddleware())
        ->toContain('throttle:30,1');

    findPeople(['q' => 'public'])->assertOk();
});
