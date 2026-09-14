<?php

namespace App\Services;

use App\Models\Booking;
use App\Models\Community;
use App\Models\CommunityMember;
use App\Models\GameJoinRequest;
use App\Models\HostedGame;
use App\Models\Listing;
use App\Models\LoyaltyPoint;
use App\Models\LoyaltyProgram;
use App\Models\Payment;
use App\Models\TournamentMatch;
use App\Models\User;
use App\Models\Venue;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Hosting a game, hangout or tournament.
 *
 * A hosted game that uses a venue reserves the court with a real booking, exactly
 * like a normal customer booking, and the host pays Bookboll up front: the court
 * deposit (or the full price at a Bookboll-owned venue) plus a non-refundable
 * seats service fee. The rest is settled with the venue on arrival.
 *
 * A hangout at the host's own spot books no court and costs nothing.
 *
 * All money is priced here from the listing and the platform's rates — the client
 * sends what the host picked, never an amount.
 */
class CustomerGameService
{
    /** Used when neither the listing nor the country sets a rate. */
    private const COURT_COMMISSION_PCT = 15;

    private const SEAT_FEE_PCT = 10;

    /** Points awarded for hosting, once per game. */
    private const HOST_BONUS = 50;

    public function __construct(protected CustomerBookingQuoteService $quotes) {}

    /**
     * What the wizard needs before it can show prices: whether the features are on,
     * and the two percentages that drive the host's bill.
     */
    public function config(?int $listingId = null, bool $community = false)
    {
        $listing = $listingId ? Listing::find($listingId) : null;

        $plan = $listing ? $this->paymentPlan($listing, $this->venueFor($listing)) : null;

        return sendSuccess('Game config', [
            'games_enabled'       => true,
            'tournaments_enabled' => true,
            'hangout_enabled'     => true,
            'court_commission_pct' => $this->courtCommissionPct($listing),
            'seat_fee_pct'         => $this->seatFeePct($listing, $community),
            'bookboll_owned'       => $listing ? ! $this->venueFor($listing)?->owner_user_id : false,
            // What the provider takes on this court, so the host is offered the
            // same choice a customer booking it would get.
            'payment_methods'      => $plan['methods'] ?? [],
            'deposit_amount'       => $plan['deposit_amount'] ?? 0,
            'deposit_percentage'   => $plan['deposit_percentage'] ?? 0,
        ]);
    }

    /**
     * Price the host's selection without creating anything, so the wizard can show
     * the bill. Same numbers the create call will charge.
     */
    public function quote(User $host, array $data)
    {
        $listing = ! empty($data['listing_id']) ? Listing::find($data['listing_id']) : null;

        if (! empty($data['listing_id']) && ! $listing) {
            return sendError('Court not found.', [], 404);
        }

        return sendSuccess('Quote', ['charge' => $this->priceHost($host, $listing, $data)]);
    }

    /** Publish a game or a hangout. */
    public function create(User $host, array $data)
    {
        if (! $host->uuid) {
            return sendError('Please sign in to host.');
        }

        $listing = ! empty($data['listing_id']) ? Listing::find($data['listing_id']) : null;

        if (! empty($data['listing_id']) && ! $listing) {
            return sendError('Court not found.', [], 404);
        }

        // No court means a hangout at the host's own spot: nothing to reserve or pay.
        $ownSpot = ! $listing;

        if ($ownSpot && trim((string) ($data['custom_location_name'] ?? '')) === '') {
            return sendError('Tell people where to meet.', [], 422);
        }

        $venue    = $listing ? $this->venueFor($listing) : null;
        $charge   = $this->priceHost($host, $listing, $data);
        $currency = $charge['currency'];

        if (! $ownSpot && $this->slotTaken($listing->id, $data['game_date'], $data['start_time'], $data['end_time'])) {
            return sendError('That court is already booked for this time. Pick another time or court.', [], 422);
        }

        $community = ! empty($data['community_id'])
            ? Community::where('uuid', $data['community_id'])->first()
            : null;

        return DB::transaction(function () use ($host, $listing, $venue, $ownSpot, $data, $charge, $currency, $community) {
            $booking = $ownSpot ? null : $this->reserveCourt($host, $listing, $venue, $data, $charge);

            $game = HostedGame::create([
                'host_customer_id'        => $host->uuid,
                'host_name'               => $data['host_name'] ?? ($host->name ?: ''),
                'host_phone'              => $data['host_phone'] ?? ($host->phone ?: ''),
                'host_whatsapp'           => $data['host_whatsapp'] ?? ($data['host_phone'] ?? ''),
                'listing_id'              => $listing?->id,
                'location_id'             => $venue?->id,
                'booking_id'              => $booking?->id,
                'city_id'                 => $data['city_id'] ?? $venue?->city_id,
                'country_id'              => $data['country_id'] ?? $venue?->country_id,
                'title'                   => $data['title'] ?? '',
                'sport'                   => $data['sport'] ?? '',
                'game_type'               => $data['game_type'] ?? 'game',
                'game_date'               => $data['game_date'],
                'start_time'              => $data['start_time'],
                'end_time'                => $data['end_time'],
                'price_per_person'        => round((float) ($data['price_per_person'] ?? 0), 2),
                'currency'                => $currency,
                'max_players'             => (int) ($data['max_players'] ?? 0),
                'skill_level'             => $data['skill_level'] ?? 'all',
                'description'             => $data['description'] ?? '',
                'status'                  => 'open',
                'prize_pot'               => round((float) ($data['prize_pot'] ?? 0), 2),
                'prize_note'              => $data['prize_note'] ?? null,
                'custom_location_name'    => $data['custom_location_name'] ?? null,
                'custom_location_address' => $data['custom_location_address'] ?? null,
                'hide_location'           => (bool) ($data['hide_location'] ?? false),
                'images'                  => $data['images'] ?? [],
                'signup_form'             => $data['signup_form'] ?? [],
                'request_deadline'        => $data['request_deadline'] ?? null,
                'approval_mode'           => $data['approval_mode'] ?? 'review',
                'allow_waitlist'          => ($data['allow_waitlist'] ?? true) !== false,
                'lock_minutes_before'     => $data['lock_minutes_before'] ?? null,
                'play_format'             => $data['play_format'] ?? null,
                'courts_count'            => $data['courts_count'] ?? null,
                'charge'                  => $charge,
                'community_id'            => $community?->id,
                'community_public'        => $community ? (bool) ($data['community_public'] ?? false) : false,
                'moderation_status'       => 'active',
            ]);

            if ($booking) {
                $this->recordHostPayments($host, $booking, $charge, $currency);
            }

            $this->awardHostBonus($host);

            return sendSuccess('Game published', [
                'game'   => $this->mapGame($game),
                'charge' => $charge,
            ]);
        });
    }

    /**
     * Tournament games whose time has passed with no winner recorded — the host
     * still has to advance the bracket. Powers the bell and the on-login prompt.
     */
    public function dueTournamentMatches(User $user)
    {
        $tournaments = HostedGame::where('host_customer_id', $user->uuid)
            ->where('game_type', 'tournament')
            ->where('status', 'open')
            ->get()
            ->keyBy('id');

        if ($tournaments->isEmpty()) {
            return sendSuccess('Due matches', ['due' => []]);
        }

        $matches = TournamentMatch::whereIn('tournament_id', $tournaments->keys())
            ->whereNotNull('match_date')
            ->orderBy('match_date')->orderBy('start_time')
            ->get();

        $today = now()->toDateString();
        $nowHM = now()->format('H:i');

        $due = $matches->filter(function (TournamentMatch $m) use ($today, $nowHM) {
            // Only a match that can actually be decided: both teams known, no winner.
            if ($m->winner || ! $m->home_team || ! $m->away_team) {
                return false;
            }

            $date = Carbon::parse($m->match_date)->toDateString();
            $end  = substr((string) ($m->end_time ?: '23:59'), 0, 5);

            return $date < $today || ($date === $today && $end <= $nowHM);
        })->map(function (TournamentMatch $m) use ($tournaments) {
            $t = $tournaments->get($m->tournament_id);

            return [
                'match'       => $this->mapMatch($m, collect()),
                'tournament'  => $this->mapGame($t),
                'totalRounds' => $this->roundsFor((int) ($t->num_teams ?: $t->max_players)),
            ];
        })->values()->all();

        return sendSuccess('Due matches', ['due' => $due]);
    }

    /**
     * The rows the notification bell builds its list from. The wording and links
     * stay in the storefront — this only supplies the data behind them.
     */
    public function notificationFeed(User $user)
    {
        $hosted = HostedGame::where('host_customer_id', $user->uuid)->get();

        $hostRequests = $hosted->isEmpty() ? collect() : GameJoinRequest::whereIn('game_id', $hosted->pluck('id'))
            ->whereIn('status', ['pending', 'accepted'])
            ->orderByDesc('created_at')->limit(60)->get();

        $myRequests = GameJoinRequest::where('joiner_customer_id', $user->uuid)
            ->whereIn('status', ['pending', 'accepted', 'rejected'])
            ->orderByDesc('created_at')->limit(60)->get();

        $myGames = HostedGame::whereIn('id', $myRequests->pluck('game_id')->unique())->get();

        // Decided matches in tournaments I have a team in, so we can tell someone
        // they advanced or were knocked out.
        $myTournamentIds = $myGames->where('game_type', 'tournament')->pluck('id');
        $matches = $myTournamentIds->isEmpty() ? collect() : TournamentMatch::whereIn('tournament_id', $myTournamentIds)
            ->whereNotNull('winner')->where('winner', '!=', '')->limit(80)->get();

        return sendSuccess('Notification feed', [
            'dueMatches'   => $this->dueTournamentMatches($user)->getData(true)['data']['due'] ?? [],
            'hostedGames'  => $hosted->map(fn ($g) => $this->mapNotificationGame($g))->values()->all(),
            'hostRequests' => $hostRequests->map(fn ($r) => $this->mapNotificationRequest($r))->values()->all(),
            'myRequests'   => $myRequests->map(fn ($r) => $this->mapNotificationRequest($r))->values()->all(),
            'myGames'      => $myGames->map(fn ($g) => $this->mapNotificationGame($g))->values()->all(),
            'matches'      => $matches->map(fn ($m) => [
                'id'              => $m->uuid,
                'tournament_id'   => $myGames->firstWhere('id', $m->tournament_id)?->uuid,
                'round'           => (int) $m->round,
                'match_index'     => (int) $m->match_index,
                'home_team'       => $m->home_team ?? '',
                'away_team'       => $m->away_team ?? '',
                'home_request_id' => $m->home_request_id ? GameJoinRequest::find($m->home_request_id)?->uuid : null,
                'away_request_id' => $m->away_request_id ? GameJoinRequest::find($m->away_request_id)?->uuid : null,
                'winner'          => $m->winner ?? '',
                'match_date'      => $m->match_date ? Carbon::parse($m->match_date)->toDateString() : null,
            ])->values()->all(),
        ]);
    }

    /** @return array<string, mixed> */
    protected function mapNotificationGame(HostedGame $g): array
    {
        return [
            'id'                => $g->uuid,
            'title'             => $g->title ?? '',
            'sport'             => $g->sport ?? '',
            'game_type'         => $g->game_type ?? 'game',
            'status'            => $g->status ?? 'open',
            'game_date'         => $g->game_date ? Carbon::parse($g->game_date)->toDateString() : null,
            'start_time'        => $g->start_time,
            'approval_mode'     => $g->approval_mode ?? 'review',
            'num_teams'         => (int) $g->num_teams,
            'moderation_status' => $g->moderation_status ?: 'active',
            'moderation_reason' => $g->moderation_reason ?? '',
            'moderated_at'      => optional($g->moderated_at)?->toIso8601String(),
        ];
    }

    /** @return array<string, mixed> */
    protected function mapNotificationRequest(GameJoinRequest $r): array
    {
        return [
            'id'          => $r->uuid,
            'game_id'     => $r->game?->uuid,
            'joiner_name' => $r->joiner_name ?? '',
            'status'      => $r->status ?? 'pending',
            'created_at'  => optional($r->created_at)?->toIso8601String(),
        ];
    }

    /** Events I'm hosting, newest first, with how the roster is filling up. */
    public function mineHosted(User $user)
    {
        $games = HostedGame::where('host_customer_id', $user->uuid)
            ->orderByDesc('game_date')->get();

        $counts = GameJoinRequest::whereIn('game_id', $games->pluck('id'))
            ->get()->groupBy('game_id');

        $idByUuid = $games->pluck('id', 'uuid');

        $rows = $this->decorateGames($games)->map(function (array $g) use ($counts, $idByUuid) {
            $mine = $counts->get($idByUuid->get($g['id']), collect());

            return $g + [
                'pendingCount'  => $mine->where('status', 'pending')->count(),
                'acceptedCount' => $mine->where('status', 'accepted')->count(),
            ];
        })->values()->all();

        return sendSuccess('My hosted events', ['games' => $rows]);
    }

    /** Events I've asked to join, each with the event attached. */
    public function mineJoined(User $user)
    {
        $requests = GameJoinRequest::where('joiner_customer_id', $user->uuid)
            ->orderByDesc('created_at')->get();

        $games     = HostedGame::whereIn('id', $requests->pluck('game_id')->unique())->get();
        $decorated = $this->decorateGames($games)->keyBy('id');
        $uuidById  = $games->pluck('uuid', 'id');

        $rows = $requests->map(function (GameJoinRequest $r) use ($decorated, $uuidById) {
            $uuid = $uuidById->get($r->game_id);

            return $this->mapRequest($r) + ['game' => $uuid ? $decorated->get($uuid) : null];
        })->values()->all();

        return sendSuccess('My join requests', ['requests' => $rows]);
    }


    /**
     * Every event a city can join right now — the Events page.
     *
     * Only open events that haven't finished: a past date is out, and today's are
     * out once their end time has gone. Events with no date stay (they're flexible).
     * Community events are listed here on purpose — discovery is how communities
     * grow — and membership is enforced when someone actually tries to join.
     */
    public function list(?int $cityId)
    {
        if (! $cityId) {
            return sendSuccess('Events', ['games' => []]);
        }

        $today = now()->toDateString();

        $games = HostedGame::where('city_id', $cityId)
            ->where('status', 'open')
            ->where('moderation_status', '!=', 'removed')
            ->where(function ($w) use ($today) {
                $w->whereNull('game_date')->orWhere('game_date', '>=', $today);
            })
            ->orderBy('game_date')
            ->get()
            ->filter(function (HostedGame $g) use ($today) {
                $date = $g->game_date ? Carbon::parse($g->game_date)->toDateString() : null;

                if (! $date || $date > $today || ! $g->end_time) {
                    return true;
                }

                return substr((string) $g->end_time, 0, 5) >= now()->format('H:i');
            })
            ->values();

        return sendSuccess('Events', ['games' => $this->decorateGames($games)->all()]);
    }

    /** Events posted to a community — the community page's Meetups tab. */
    public function communityEvents(string $communityUuid)
    {
        $community = Community::where('uuid', $communityUuid)->first();

        if (! $community) {
            return sendError('Community not found.', [], 404);
        }

        $games = HostedGame::where('community_id', $community->id)
            ->where('moderation_status', '!=', 'removed')
            ->where('status', '!=', 'cancelled')
            ->get();

        // Upcoming first (soonest first), then past (most recent first) — the order
        // the community page has always shown.
        $today    = now()->toDateString();
        $upcoming = $games->filter(fn ($g) => ! $g->game_date || $g->game_date >= $today)
            ->sortBy(fn ($g) => (string) ($g->game_date ?: '9999-12-31'));
        $past = $games->filter(fn ($g) => $g->game_date && $g->game_date < $today)
            ->sortByDesc(fn ($g) => (string) $g->game_date);

        $ordered = $upcoming->concat($past)->values();

        return sendSuccess('Community events', [
            'events' => $this->decorateGames($ordered)->all(),
        ]);
    }

    /** Post an existing game to a community, or change whether it is publicly listed. */
    public function setCommunity(User $host, string $gameUuid, ?string $communityUuid, ?bool $public)
    {
        $game = HostedGame::where('uuid', $gameUuid)->first();

        if (! $game) {
            return sendError('Event not found.', [], 404);
        }

        if ($game->host_customer_id !== $host->uuid) {
            return sendError('Only the host can change this event.', [], 403);
        }

        if ($communityUuid !== null) {
            $community = Community::where('uuid', $communityUuid)->first();

            if (! $community) {
                return sendError('Community not found.', [], 404);
            }

            $game->community_id = $community->id;
        }

        if ($public !== null) {
            $game->community_public = $public;
        }

        $game->save();

        return sendSuccess('Event updated', ['game' => $this->mapGame($game)]);
    }

    // ── Money ────────────────────────────────────────────────────────────────

    /**
     * What the provider actually lets someone pay with on this court, and the
     * deposit they set. Same source of truth a normal booking uses, so hosting a
     * game can never offer a method the venue doesn't take.
     *
     * @return array{methods: array<int,string>, deposit_amount: float, deposit_percentage: float}
     */
    protected function paymentPlan(Listing $listing, ?Venue $venue): array
    {
        $options = is_array($listing->payment_options) ? $listing->payment_options : [];
        $deposit = is_array($options['deposit'] ?? null) ? $options['deposit'] : [];

        $methods = [];

        if ($listing->online_payment || filter_var($options['online_payment_enabled'] ?? false, FILTER_VALIDATE_BOOLEAN)) {
            $methods[] = 'online';
        }

        if ($deposit !== [] || filter_var($options['deposit_enabled'] ?? false, FILTER_VALIDATE_BOOLEAN)) {
            $methods[] = 'deposit';
        }

        if (filter_var($options['cash_on_arrival'] ?? false, FILTER_VALIDATE_BOOLEAN)) {
            $methods[] = 'cash';
        }

        // Nothing configured yet — fall back on ownership, as the booking flow does.
        if ($methods === []) {
            $methods = $venue?->owner_user_id ? ['deposit'] : ['online'];
        }

        return [
            'methods'            => $methods,
            'deposit_amount'     => (float) ($deposit['amount'] ?? 0),
            'deposit_percentage' => (float) ($deposit['percentage'] ?? 0),
        ];
    }

    /**
     * Split a court total the way the chosen method pays for it: all now, all on
     * arrival, or the provider's deposit now and the rest at the venue.
     *
     * @return array{now: float, arrival: float}
     */
    protected function splitCourt(float $total, string $method, array $plan): array
    {
        if ($method === 'online') {
            return ['now' => $total, 'arrival' => 0.0];
        }

        if ($method === 'cash') {
            return ['now' => 0.0, 'arrival' => $total];
        }

        // A flat deposit wins over a percentage — the rule the booking flow uses.
        $deposit = $plan['deposit_amount'] > 0
            ? $plan['deposit_amount']
            : floor($total * ($plan['deposit_percentage'] > 0 ? $plan['deposit_percentage'] : 20) / 100);

        $deposit = max(0, min($deposit, $total));

        return ['now' => round($deposit, 2), 'arrival' => round($total - $deposit, 2)];
    }

    /** The method to charge on: what the host picked, if the provider allows it. */
    protected function resolveMethod(array $plan, ?string $wanted): string
    {
        if ($wanted && in_array($wanted, $plan['methods'], true)) {
            return $wanted;
        }

        // Otherwise take the most-paid-up-front option the provider does allow.
        foreach (['online', 'deposit', 'cash'] as $method) {
            if (in_array($method, $plan['methods'], true)) {
                return $method;
            }
        }

        return 'cash';
    }

    /**
     * The host's bill. The court is priced from the listing's own rates; the seats
     * service fee is a percentage of the seats the host is selling. Hosts selling
     * tickets through their own Stripe pay no seats fee — Stripe collects it there.
     */
    protected function priceHost(User $host, ?Listing $listing, array $data): array
    {
        $venue    = $listing ? $this->venueFor($listing) : null;
        $currency = $venue?->currency_override ?: '';

        if (! $listing) {
            return $this->charge($currency, 0, 0, 0, false, 0);
        }

        $courtTotal = $this->courtPrice($listing, $data);

        // Pay for the court the way the provider allows — the same choice and the
        // same deposit a customer booking this court would get.
        $plan   = $this->paymentPlan($listing, $venue);
        $method = $this->resolveMethod($plan, $data['payment_method'] ?? null);
        $split  = $this->splitCourt($courtTotal, $method, $plan);
        $courtNow = $split['now'];

        $seats      = max(0, (int) ($data['max_players'] ?? 0));
        $seatsValue = round((float) ($data['price_per_person'] ?? 0), 2) * $seats;
        $serviceFee = ($data['ticket_mode'] ?? '') === 'stripe'
            ? 0
            : round($seatsValue * $this->seatFeePct($listing, ! empty($data['community_id'])) / 100);

        $loyalty = $this->loyaltyAllowed($host, (float) ($data['loyalty_redeem'] ?? 0), $courtNow + $serviceFee);

        $charge = $this->charge($currency, $courtTotal, $courtNow, $serviceFee, $method === 'online', $loyalty);
        $charge['paymentMethod']  = $method;
        $charge['paymentMethods'] = $plan['methods'];

        return $charge;
    }

    /** @return array<string, mixed> */
    protected function charge(string $currency, float $courtTotal, float $courtNow, float $serviceFee, bool $owned, float $loyalty): array
    {
        return [
            'currency'      => $currency,
            'courtTotal'    => round($courtTotal, 2),
            'courtNow'      => round($courtNow, 2),
            'courtArrival'  => round($courtTotal - $courtNow, 2),
            'serviceFee'    => round($serviceFee, 2),
            'bookbollOwned' => $owned,
            'loyaltyRedeem' => round($loyalty, 2),
            'dueNow'        => round(max(0, $courtNow + $serviceFee - $loyalty), 2),
            'dueArrival'    => round($courtTotal - $courtNow, 2),
        ];
    }

    /** Court price for the host's date and time, straight from the listing's rates. */
    protected function courtPrice(Listing $listing, array $data): float
    {
        try {
            $quote = $this->quotes->quoteBlock(
                $listing,
                (string) $data['game_date'],
                (string) $data['start_time'],
                (string) $data['end_time'],
                max(1, (int) ($data['max_players'] ?? 1))
            );

            return round((float) ($quote['subtotal'] ?? 0), 2);
        } catch (\Throwable) {
            return 0.0;
        }
    }

    /** Listing override → the venue's country rate → platform default. */
    protected function courtCommissionPct(?Listing $listing): float
    {
        if (! $listing) {
            return self::COURT_COMMISSION_PCT;
        }

        if ($listing->commission_rate !== null && $listing->commission_rate !== '') {
            return (float) $listing->commission_rate;
        }

        $rate = $this->countryRate($this->venueFor($listing)?->country_id, 'facility');

        return $rate ?? self::COURT_COMMISSION_PCT;
    }

    /** Community rate → the venue's country event rate → platform default. */
    protected function seatFeePct(?Listing $listing, bool $community = false): float
    {
        if ($community) {
            $setting = $this->setting('community_commission');

            if (is_numeric($setting)) {
                return (float) $setting;
            }
        }

        $rate = $this->countryRate($listing ? $this->venueFor($listing)?->country_id : null, 'event');

        if ($rate !== null) {
            return $rate;
        }

        $global = $this->setting('global_commission_event');

        return is_numeric($global) ? (float) $global : self::SEAT_FEE_PCT;
    }

    protected function countryRate(?int $countryId, string $listingType): ?float
    {
        if (! $countryId) {
            return null;
        }

        $rate = DB::table('commission_rates')
            ->where('country_id', $countryId)
            ->where('listing_type', $listingType)
            ->value('rate_percent');

        return $rate === null ? null : (float) $rate;
    }

    protected function setting(string $key): mixed
    {
        return DB::table('settings')->where('key', $key)->value('value');
    }

    /**
     * How much loyalty the host may actually spend: never more than they hold, and
     * never more than the amount Bookboll is collecting now. Points never reduce
     * the venue's on-arrival balance — that is the provider's money.
     */
    protected function loyaltyAllowed(User $host, float $requested, float $bookbollNow): float
    {
        $wanted = min(max(0, $requested), max(0, $bookbollNow));

        if ($wanted <= 0) {
            return 0.0;
        }

        $config = LoyaltyProgram::where('country_id', $host->country_id)->first();
        $rate   = (int) ($config->redeem_rate ?? LoyaltyProgram::defaults()['redeem_rate']);
        $unit   = (float) ($config->redeem_value ?? LoyaltyProgram::defaults()['redeem_value']) ?: 1;
        $held   = (int) (LoyaltyPoint::balance($host->id) ?? 0);

        return round(min($wanted, $held / max(1, $rate) * $unit), 2);
    }

    // ── The court reservation ────────────────────────────────────────────────

    /** Any confirmed booking overlapping this court and time blocks the host. */
    protected function slotTaken(int $listingId, string $date, string $start, string $end): bool
    {
        return Booking::where('listing_id', $listingId)
            ->where('date', $date)
            ->where('status', '!=', 'cancelled')
            ->where('start_time', '<', $end)
            ->where('end_time', '>', $start)
            ->exists();
    }

    protected function reserveCourt(User $host, Listing $listing, ?Venue $venue, array $data, array $charge): Booking
    {
        // Record it under the method the host actually paid on.
        if ($charge['courtTotal'] <= 0) {
            $paymentStatus = 'payment_skipped';
            $paymentMethod = 'payment_skipped';
        } elseif (($charge['paymentMethod'] ?? '') === 'cash') {
            $paymentStatus = 'pending';
            $paymentMethod = 'cash_on_arrival';
        } elseif ($charge['courtArrival'] > 0) {
            $paymentStatus = 'deposit_paid';
            $paymentMethod = 'deposit_remaining_on_arrival';
        } else {
            $paymentStatus = 'fully_paid';
            $paymentMethod = 'online_payment';
        }

        return Booking::create([
            'user_id'        => $host->id,
            'listing_id'     => $listing->id,
            'venue_id'       => $venue?->id,
            'date'           => $data['game_date'],
            'start_time'     => $data['start_time'],
            'end_time'       => $data['end_time'],
            'players'        => max(1, (int) ($data['max_players'] ?? 1)),
            'subtotal'       => $charge['courtTotal'],
            'total_price'    => $charge['courtTotal'],
            // A hosted game's court is confirmed on the same rule as every
            // other booking: only when nothing is left to collect. A host who
            // paid a deposit with the balance due at the venue leaves this
            // pending until the venue collects it.
            'status'         => initialBookingStatus($paymentStatus, (float) $charge['courtTotal']),
            'payment_status' => $paymentStatus,
            'payment_method' => $paymentMethod,
            'origin'         => 'customer_game',
            'notes'          => 'Hosted '.($data['game_type'] ?? 'game').': '.($data['title'] ?? $data['sport'] ?? '')
                .'. Player seat payments handled offline; Bookboll service fee pre-paid by host.',
        ]);
    }

    /**
     * What the host paid, split the way the money actually moves: the deposit and
     * the seats fee go to Bookboll now, the balance is owed to the venue on arrival.
     * Loyalty is Bookboll's to give, so it comes off the service fee first, then
     * the deposit — never the venue's balance.
     */
    protected function recordHostPayments(User $host, Booking $booking, array $charge, string $currency, bool $spendLoyalty = true): void
    {
        $remaining = $charge['loyaltyRedeem'];

        $serviceNet = max(0, $charge['serviceFee'] - $remaining);
        $remaining  = max(0, $remaining - $charge['serviceFee']);

        $courtNet  = max(0, $charge['courtNow'] - $remaining);
        $remaining = max(0, $remaining - $charge['courtNow']);

        $rows = [];

        if ($courtNet > 0) {
            $rows[] = [
                'type'      => $charge['bookbollOwned'] ? 'full' : 'deposit',
                'amount'    => $courtNet,
                'recipient' => '@bookboll',
                'status'    => 'paid',
                'notes'     => $charge['bookbollOwned'] ? 'Court paid in full online' : 'Court deposit',
            ];
        }

        if ($serviceNet > 0) {
            $rows[] = [
                'type'      => 'commission',
                'amount'    => $serviceNet,
                'recipient' => '@bookboll',
                'status'    => 'paid',
                'notes'     => 'Seats service fee (non-refundable)',
            ];
        }

        if ($charge['courtArrival'] > 0) {
            $rows[] = [
                'type'      => 'remaining',
                'amount'    => $charge['courtArrival'],
                'recipient' => '@provider',
                'status'    => 'pending',
                'notes'     => 'Balance due to the venue on arrival',
            ];
        }

        foreach ($rows as $row) {
            Payment::create([
                'payment_id'  => 'game-'.Str::uuid(),
                'user_id'     => $host->id,
                'booking_id'  => $booking->id,
                'source_type' => 'booking',
                'type'        => $row['type'],
                'method'      => 'online',
                'amount'      => $row['amount'],
                'currency'    => $currency,
                'recipient'   => $row['recipient'],
                'status'      => $row['status'],
                'date'        => now()->toDateString(),
                'paid_at'     => $row['status'] === 'paid' ? now() : null,
                'notes'       => $row['notes'],
            ]);
        }

        // A bracket spends its points once for the whole thing, not per match.
        if ($spendLoyalty && $charge['loyaltyRedeem'] > 0) {
            $this->spendLoyalty($host, $charge['loyaltyRedeem']);
        }
    }

    protected function spendLoyalty(User $host, float $amount): void
    {
        $config = LoyaltyProgram::where('country_id', $host->country_id)->first();
        $rate   = (int) ($config->redeem_rate ?? LoyaltyProgram::defaults()['redeem_rate']);
        $unit   = (float) ($config->redeem_value ?? LoyaltyProgram::defaults()['redeem_value']) ?: 1;

        $points = min((int) ceil($amount / $unit * $rate), (int) (LoyaltyPoint::balance($host->id) ?? 0));

        if ($points <= 0) {
            return;
        }

        LoyaltyPoint::create([
            'user_id' => $host->id,
            'type'    => 'debit',
            'points'  => $points,
            'reason'  => 'Redeemed on hosting',
        ]);

        $host->updateLoyaltyTier();
    }

    /** Hosting earns a flat bonus, once per game. */
    protected function awardHostBonus(User $host): void
    {
        LoyaltyPoint::create([
            'user_id' => $host->id,
            'type'    => 'credit',
            'points'  => self::HOST_BONUS,
            'reason'  => 'Hosted a game',
        ]);

        $host->updateLoyaltyTier();
    }


    // ── Viewing and joining ──────────────────────────────────────────────────

    /** One event, with the people context the detail page shows. */
    public function show(string $uuid, ?User $viewer = null)
    {
        $game = HostedGame::where('uuid', $uuid)->first();

        // Removed by a Bookboll admin — hidden even from a direct link.
        if (! $game || $game->moderation_status === 'removed') {
            return sendError('Event not found.', [], 404);
        }

        [$decorated] = $this->decorateGames(collect([$game]))->all();

        $mine = $viewer?->uuid
            ? GameJoinRequest::where('game_id', $game->id)->where('joiner_customer_id', $viewer->uuid)->first()
            : null;

        return sendSuccess('Event', [
            'game'      => $decorated,
            'myRequest' => $mine ? $this->mapRequest($mine) : null,
            'isHost'    => $viewer?->uuid && $viewer->uuid === $game->host_customer_id,
        ]);
    }

    /** Everyone who asked to join — the host's roster. */
    public function requests(User $host, string $uuid)
    {
        $game = HostedGame::where('uuid', $uuid)->first();

        if (! $game) {
            return sendError('Event not found.', [], 404);
        }

        if ($game->host_customer_id !== $host->uuid) {
            return sendError('Only the host can see the roster.', [], 403);
        }

        $rows = GameJoinRequest::where('game_id', $game->id)->orderBy('created_at')->get();

        return sendSuccess('Requests', [
            'requests' => $rows->map(fn ($r) => $this->mapRequest($r))->values()->all(),
        ]);
    }

    /**
     * Ask to join. Instant events skip approval, a full event queues on the
     * waitlist, and a members-only community event is closed to non-members.
     */
    public function join(User $user, string $uuid, array $data)
    {
        $game = HostedGame::where('uuid', $uuid)->first();

        if (! $game || $game->moderation_status === 'removed') {
            return sendError('Event not found.', [], 404);
        }

        if ($game->request_deadline && $game->request_deadline->isPast()) {
            return sendError('Requests for this event have closed.', [], 422);
        }

        $accepted = GameJoinRequest::where('game_id', $game->id)->where('status', 'accepted')->count();

        // Once an event locks, starts or ends the roster is final.
        $state = $this->meetupState($game, $accepted);

        if ($state === 'locked') {
            return sendError('This event is locked — the roster is final. Message the host if you still want in.', [], 422);
        }

        if ($state === 'active') {
            return sendError('This event has already started.', [], 422);
        }

        if ($state === 'archived') {
            return sendError('This event has ended.', [], 422);
        }

        if ($state === 'cancelled') {
            return sendError('This event was cancelled.', [], 422);
        }

        // A community event that was never listed publicly is members-only.
        if ($game->community_id && ! $game->community_public) {
            $isMember = CommunityMember::where('community_id', $game->community_id)
                ->where('customer_id', $user->uuid)
                ->where('status', 'accepted')
                ->exists();

            if (! $isMember) {
                return sendError('This is a members-only community event — join the community first.', [
                    'community_id' => Community::find($game->community_id)?->uuid,
                ], 422);
            }
        }

        $cap = (int) $game->max_players;
        $full = $cap > 0 && $accepted >= $cap;

        if ($full && $game->allow_waitlist === false) {
            return sendError('This event is full.', [], 422);
        }

        $status = $full ? 'waitlisted' : ($game->approval_mode === 'instant' ? 'accepted' : 'pending');

        $request = GameJoinRequest::updateOrCreate(
            ['game_id' => $game->id, 'joiner_customer_id' => $user->uuid],
            [
                'joiner_name'     => $data['joiner_name'] ?? ($user->name ?: ''),
                'joiner_phone'    => $data['joiner_phone'] ?? ($user->phone ?: ''),
                'joiner_whatsapp' => $data['joiner_whatsapp'] ?? ($data['joiner_phone'] ?? ''),
                'joiner_message'  => $data['joiner_message'] ?? '',
                'form_answers'    => $data['form_answers'] ?? [],
                'status'          => $status,
            ]
        );

        return sendSuccess('Request sent', ['request' => $this->mapRequest($request->fresh())]);
    }

    /** The joiner's own request, so the page knows what to show them. */
    public function myRequest(User $user, string $uuid)
    {
        $game = HostedGame::where('uuid', $uuid)->first();

        if (! $game) {
            return sendError('Event not found.', [], 404);
        }

        $row = GameJoinRequest::where('game_id', $game->id)
            ->where('joiner_customer_id', $user->uuid)
            ->first();

        return sendSuccess('My request', ['request' => $row ? $this->mapRequest($row) : null]);
    }

    /**
     * Accept everyone waiting, oldest first, but never past the cap — anyone who
     * doesn't fit stays pending so the host can decide.
     */
    public function acceptAllRequests(User $host, string $uuid)
    {
        $game = HostedGame::where('uuid', $uuid)->first();

        if (! $game) {
            return sendError('Event not found.', [], 404);
        }

        if ($game->host_customer_id !== $host->uuid) {
            return sendError('Only the host can accept requests.', [], 403);
        }

        $rows     = GameJoinRequest::where('game_id', $game->id)->orderBy('created_at')->get();
        $pending  = $rows->where('status', 'pending')->values();
        $accepted = $rows->where('status', 'accepted')->count();

        if ($pending->isEmpty()) {
            return sendSuccess('Nothing to accept', ['accepted' => 0, 'leftover' => 0]);
        }

        // A tournament caps on teams; everything else on players.
        $cap  = (int) ($game->game_type === 'tournament' ? ($game->num_teams ?: $game->max_players) : $game->max_players);
        $take = $pending;

        if ($cap > 0) {
            $remaining = max(0, $cap - $accepted);

            if ($remaining <= 0) {
                return sendSuccess('Event is full', [
                    'accepted' => 0,
                    'leftover' => $pending->count(),
                    'full'     => true,
                ]);
            }

            $take = $pending->take($remaining);
        }

        GameJoinRequest::whereIn('id', $take->pluck('id'))->update(['status' => 'accepted']);

        return sendSuccess('Requests accepted', [
            'accepted' => $take->count(),
            'leftover' => $pending->count() - $take->count(),
        ]);
    }

    /** Host accepts or rejects someone. Accepting never overfills the event. */
    public function respondToRequest(User $host, string $requestUuid, string $status)
    {
        $request = GameJoinRequest::where('uuid', $requestUuid)->first();

        if (! $request) {
            return sendError('Request not found.', [], 404);
        }

        $game = HostedGame::find($request->game_id);

        if (! $game || $game->host_customer_id !== $host->uuid) {
            return sendError('Only the host can decide this.', [], 403);
        }

        if ($status === 'accepted') {
            $cap = (int) $game->max_players;
            $accepted = GameJoinRequest::where('game_id', $game->id)
                ->where('status', 'accepted')
                ->where('id', '!=', $request->id)
                ->count();

            if ($cap > 0 && $accepted >= $cap) {
                return sendError('This event is already full.', [], 422);
            }
        }

        $request->update(['status' => $status]);

        if ($status !== 'accepted') {
            $this->promoteFromWaitlist($game);
        }

        return sendSuccess('Request updated', ['request' => $this->mapRequest($request->fresh())]);
    }

    /** A joiner drops out. Their spot goes to whoever waited longest. */
    public function cancelRequest(User $user, string $requestUuid)
    {
        $request = GameJoinRequest::where('uuid', $requestUuid)->first();

        if (! $request) {
            return sendError('Request not found.', [], 404);
        }

        $game = HostedGame::find($request->game_id);
        $isOwner = $request->joiner_customer_id === $user->uuid;
        $isHost = $game && $game->host_customer_id === $user->uuid;

        if (! $isOwner && ! $isHost) {
            return sendError('You cannot drop this request.', [], 403);
        }

        // Close to kick-off the roster is fixed, so a confirmed spot can't be dropped.
        if ($game && $request->status === 'accepted') {
            $state = $this->meetupState($game, GameJoinRequest::where('game_id', $game->id)->where('status', 'accepted')->count());

            if (in_array($state, ['locked', 'active'], true) && $isOwner) {
                return sendError("This event is locked — spots can't be dropped this close to start. Message the host if you really can't make it.", [], 422);
            }
        }

        $request->delete();

        if ($game) {
            $this->promoteFromWaitlist($game);
        }

        return sendSuccess('Request removed', ['ok' => true]);
    }

    /**
     * Fill a freed spot from the waitlist, oldest first. An instant-join event
     * takes them straight in; one the host reviews puts them back in the queue.
     */
    protected function promoteFromWaitlist(HostedGame $game): void
    {
        $cap = (int) $game->max_players;

        if ($cap <= 0) {
            return;
        }

        $accepted = GameJoinRequest::where('game_id', $game->id)->where('status', 'accepted')->count();

        if ($accepted >= $cap) {
            return;
        }

        $next = GameJoinRequest::where('game_id', $game->id)
            ->where('status', 'waitlisted')
            ->orderBy('created_at')
            ->first();

        $next?->update(['status' => $game->approval_mode === 'instant' ? 'accepted' : 'pending']);
    }

    /** Host edits the details people can safely be told about after publishing. */
    public function updateGame(User $host, string $uuid, array $patch)
    {
        $game = HostedGame::where('uuid', $uuid)->first();

        if (! $game) {
            return sendError('Event not found.', [], 404);
        }

        if ($game->host_customer_id !== $host->uuid) {
            return sendError('Only the host can edit this event.', [], 403);
        }

        // The cap can never drop below the players already holding a spot.
        if (array_key_exists('max_players', $patch) && (int) $patch['max_players'] > 0) {
            $accepted = GameJoinRequest::where('game_id', $game->id)->where('status', 'accepted')->count();

            if ($accepted > (int) $patch['max_players']) {
                return sendError("$accepted players are already in — max players can't go below that.", [], 422);
            }
        }

        $game->update($patch);

        return sendSuccess('Event updated', ['game' => $this->mapGame($game->fresh())]);
    }

    /** Host calls the event off; the court reservation is released with it. */
    public function cancelGame(User $host, string $uuid)
    {
        $game = HostedGame::where('uuid', $uuid)->first();

        if (! $game) {
            return sendError('Event not found.', [], 404);
        }

        if ($game->host_customer_id !== $host->uuid) {
            return sendError('Only the host can cancel this event.', [], 403);
        }

        $game->update(['status' => 'cancelled']);

        if ($game->booking_id) {
            Booking::where('id', $game->booking_id)->update(['status' => 'cancelled']);
        }

        return sendSuccess('Event cancelled', ['ok' => true]);
    }

    /** Host ticks off who actually turned up. */
    public function markAttendance(User $host, string $requestUuid, bool $attended)
    {
        $request = GameJoinRequest::where('uuid', $requestUuid)->first();

        if (! $request) {
            return sendError('Request not found.', [], 404);
        }

        $game = HostedGame::find($request->game_id);

        if (! $game || $game->host_customer_id !== $host->uuid) {
            return sendError('Only the host can mark attendance.', [], 403);
        }

        $request->update(['attended' => $attended]);

        return sendSuccess('Attendance saved', ['ok' => true]);
    }

    /** The host's saved round/matchmaking sheet for an event. */
    public function saveRounds(User $host, string $uuid, $rounds)
    {
        $game = HostedGame::where('uuid', $uuid)->first();

        if (! $game) {
            return sendError('Event not found.', [], 404);
        }

        if ($game->host_customer_id !== $host->uuid) {
            return sendError('Only the host can save rounds.', [], 403);
        }

        $game->update(['rounds' => $rounds]);

        return sendSuccess('Rounds saved', ['ok' => true]);
    }

    /**
     * Where an event is in its life: cancelled, ended, running, locked to new
     * joins, full, or open. Mirrors meetup.js so both sides agree.
     */
    protected function meetupState(HostedGame $game, int $acceptedCount = 0): string
    {
        if ($game->status === 'cancelled') {
            return 'cancelled';
        }

        $date = $game->game_date ? Carbon::parse($game->game_date)->toDateString() : null;

        if ($date) {
            $start = Carbon::parse($date.' '.($game->start_time ?: '00:00'));
            $end = Carbon::parse($date.' '.($game->end_time ?: '23:59'));

            if (now()->greaterThan($end)) {
                return 'archived';
            }

            if (now()->greaterThanOrEqualTo($start)) {
                return 'active';
            }

            $lockMinutes = (int) $game->lock_minutes_before;

            if ($lockMinutes > 0 && now()->greaterThanOrEqualTo($start->copy()->subMinutes($lockMinutes))) {
                return 'locked';
            }
        }

        $cap = (int) $game->max_players;

        if ($cap > 0 && $acceptedCount >= $cap) {
            return 'waitlisted';
        }

        return 'open';
    }

    /** @return array<string, mixed> */
    protected function mapRequest(GameJoinRequest $r): array
    {
        return [
            'id'                 => $r->uuid,
            'game_id'            => $r->game?->uuid,
            'joiner_customer_id' => $r->joiner_customer_id,
            'joiner_name'        => $r->joiner_name ?? '',
            'joiner_phone'       => $r->joiner_phone ?? '',
            'joiner_whatsapp'    => $r->joiner_whatsapp ?? '',
            'joiner_message'     => $r->joiner_message ?? '',
            'status'             => $r->status ?? 'pending',
            'form_answers'       => $r->form_answers ?? [],
            'attended'           => (bool) $r->attended,
            'created_at'         => optional($r->created_at)?->toIso8601String(),
        ];
    }


    // ── Tournaments ──────────────────────────────────────────────────────────

    /**
     * Publish a knockout bracket: one parent event plus a match per game, each
     * holding its own court. If any court can't be reserved the whole thing is
     * rolled back — a half-booked tournament would be worse than none.
     */
    public function createTournament(User $host, array $data)
    {
        if (! $host->uuid) {
            return sendError('Please sign in to host.');
        }

        $teams = (int) ($data['num_teams'] ?? 0);

        if (! in_array($teams, [4, 8, 16, 32], true)) {
            return sendError('Pick how many teams will play.', [], 422);
        }

        $matches = $data['matches'] ?? [];

        if (count($matches) !== $teams - 1) {
            return sendError('Please schedule every game.', [], 422);
        }

        // Earliest game represents the tournament; the last one gives its end time.
        usort($matches, fn ($a, $b) => strcmp($a['date'].'T'.$a['start_time'], $b['date'].'T'.$b['start_time']));
        $first = $matches[0];
        $last  = $matches[count($matches) - 1];

        $listings = Listing::whereIn('id', array_filter(array_column($matches, 'listing_id')))->get()->keyBy('id');

        foreach ($matches as $m) {
            if (empty($m['listing_id']) || ! $listings->has($m['listing_id'])) {
                return sendError('Every game needs a venue and court.', [], 422);
            }
        }

        $charge   = $this->priceTournament($host, $listings, $matches, $data);
        $currency = $charge['currency'];

        $community = ! empty($data['community_id'])
            ? Community::where('uuid', $data['community_id'])->first()
            : null;

        try {
            return DB::transaction(function () use ($host, $data, $teams, $matches, $first, $last, $listings, $charge, $currency, $community) {
                $firstListing = $listings->get($first['listing_id']);

                $tournament = HostedGame::create([
                    'host_customer_id'    => $host->uuid,
                    'host_name'           => $data['host_name'] ?? ($host->name ?: ''),
                    'host_phone'          => $data['host_phone'] ?? ($host->phone ?: ''),
                    'host_whatsapp'       => $data['host_whatsapp'] ?? ($data['host_phone'] ?? ''),
                    'listing_id'          => $firstListing?->id,
                    'location_id'         => $firstListing ? $this->venueFor($firstListing)?->id : null,
                    'city_id'             => $data['city_id'] ?? null,
                    'country_id'          => $data['country_id'] ?? null,
                    'title'               => $data['title'] ?? '',
                    'sport'               => $data['sport'] ?? '',
                    'game_type'           => 'tournament',
                    'num_teams'           => $teams,
                    'format'              => 'single_elim',
                    'game_date'           => $first['date'],
                    'start_time'          => $first['start_time'],
                    'end_time'            => $last['end_time'],
                    'price_per_person'    => round((float) ($data['price_per_person'] ?? 0), 2),
                    'currency'            => $currency,
                    'max_players'         => $teams,
                    'skill_level'         => $data['skill_level'] ?? 'all',
                    'description'         => $data['description'] ?? '',
                    'status'              => 'open',
                    'prize_pot'           => round((float) ($data['prize_pot'] ?? 0), 2),
                    'prize_note'          => $data['prize_note'] ?? null,
                    'signup_form'         => $data['signup_form'] ?? [],
                    'request_deadline'    => $data['request_deadline'] ?? null,
                    'approval_mode'       => $data['approval_mode'] ?? 'review',
                    'allow_waitlist'      => ($data['allow_waitlist'] ?? true) !== false,
                    'lock_minutes_before' => $data['lock_minutes_before'] ?? null,
                    'charge'              => $charge,
                    'community_id'        => $community?->id,
                    'community_public'    => $community ? (bool) ($data['community_public'] ?? false) : false,
                    'moderation_status'   => 'active',
                ]);

                $firstBooking = null;

                foreach ($matches as $i => $m) {
                    $listing = $listings->get($m['listing_id']);
                    $venue   = $this->venueFor($listing);
                    $line    = $charge['matches'][$i];

                    if ($this->slotTaken($listing->id, $m['date'], $m['start_time'], $m['end_time'])) {
                        throw new \RuntimeException(
                            'That court is already booked on '.$m['date'].' at '.$m['start_time'].' — pick another time for that game.'
                        );
                    }

                    $booking = $this->reserveCourt($host, $listing, $venue, [
                        'game_date'   => $m['date'],
                        'start_time'  => $m['start_time'],
                        'end_time'    => $m['end_time'],
                        'max_players' => $teams,
                        'game_type'   => 'tournament',
                        'title'       => ($data['title'] ?? $data['sport'] ?? '').' — round '.((int) $m['round'] + 1).', match '.((int) $m['index'] + 1),
                    ], $line);

                    $firstBooking ??= $booking;
                    $this->recordHostPayments($host, $booking, $line, $currency, spendLoyalty: false);

                    TournamentMatch::create([
                        'tournament_id' => $tournament->id,
                        'round'         => (int) $m['round'],
                        'match_index'   => (int) $m['index'],
                        'listing_id'    => $listing->id,
                        'location_id'   => $venue?->id,
                        'booking_id'    => $booking->id,
                        'match_date'    => $m['date'],
                        'start_time'    => $m['start_time'],
                        'end_time'      => $m['end_time'],
                        'status'        => 'scheduled',
                    ]);
                }

                // One seats fee covers the whole bracket, so it is booked once
                // against the first match rather than repeated per court.
                if ($charge['serviceFee'] > 0 && $firstBooking) {
                    $this->recordHostPayments($host, $firstBooking, $this->charge(
                        $currency, 0, 0, $charge['serviceFee'], true, $charge['serviceFeeLoyalty']
                    ), $currency, spendLoyalty: false);
                }

                // Points are spent once for the whole bracket, not per match.
                if ($charge['loyaltyRedeem'] > 0) {
                    $this->spendLoyalty($host, $charge['loyaltyRedeem']);
                }

                $this->awardHostBonus($host);

                return sendSuccess('Tournament published', [
                    'tournament' => $this->mapGame($tournament),
                    'charge'     => $charge,
                ]);
            });
        } catch (\RuntimeException $e) {
            return sendError($e->getMessage(), [], 422);
        }
    }

    /**
     * The host's bill for a bracket: every match's court, plus one seats service
     * fee for the whole thing. Loyalty comes off the fee first, then each court's
     * deposit in order — never a venue's on-arrival balance.
     *
     * @return array<string, mixed>
     */
    protected function priceTournament(User $host, $listings, array $matches, array $data): array
    {
        $teams    = (int) ($data['num_teams'] ?? 0);
        $currency = '';
        $lines    = [];
        $bookbollNow = 0.0;

        foreach ($matches as $m) {
            $listing = $listings->get($m['listing_id']);
            $venue   = $this->venueFor($listing);
            $currency = $venue?->currency_override ?: $currency;

            $courtTotal = $this->courtPrice($listing, [
                'game_date'   => $m['date'],
                'start_time'  => $m['start_time'],
                'end_time'    => $m['end_time'],
                'max_players' => $teams,
            ]);

            $plan     = $this->paymentPlan($listing, $venue);
            $method   = $this->resolveMethod($plan, $data['payment_method'] ?? null);
            $split    = $this->splitCourt($courtTotal, $method, $plan);
            $courtNow = $split['now'];

            $bookbollNow += $courtNow;
            $line = $this->charge($currency, $courtTotal, $courtNow, 0, $method === 'online', 0);
            $line['paymentMethod'] = $method;
            $lines[] = $line;
        }

        $seatsValue = round((float) ($data['price_per_person'] ?? 0), 2) * $teams;
        $serviceFee = ($data['ticket_mode'] ?? '') === 'stripe'
            ? 0
            : round($seatsValue * $this->seatFeePct($listings->first(), ! empty($data['community_id'])) / 100);

        $loyalty = $this->loyaltyAllowed($host, (float) ($data['loyalty_redeem'] ?? 0), $bookbollNow + $serviceFee);

        // Spread the points: the seats fee first, then across each court in turn.
        $budget = max(0, $loyalty - $serviceFee);

        foreach ($lines as $i => $line) {
            $take = min($budget, $line['courtNow']);
            $budget = round($budget - $take, 2);
            $lines[$i]['loyaltyRedeem'] = round($take, 2);
            $lines[$i]['dueNow'] = round(max(0, $line['courtNow'] - $take), 2);
        }

        // The whole bracket's bill, with each match's line kept for the bookings.
        // The bracket only counts as fully online if every one of its courts is.
        $total = $this->charge(
            $currency,
            array_sum(array_column($lines, 'courtTotal')),
            array_sum(array_column($lines, 'courtNow')),
            $serviceFee,
            $lines !== [] && ! in_array(false, array_column($lines, 'bookbollOwned'), true),
            $loyalty
        );
        $total['matches'] = $lines;
        $total['serviceFeeLoyalty'] = round(min($loyalty, $serviceFee), 2);

        return $total;
    }

    /** A tournament with its bracket, for the tournament page. */
    public function showTournament(string $uuid, ?User $viewer = null)
    {
        $tournament = HostedGame::where('uuid', $uuid)->where('game_type', 'tournament')->first();

        if (! $tournament || $tournament->moderation_status === 'removed') {
            return sendError('Tournament not found.', [], 404);
        }

        [$decorated] = $this->decorateGames(collect([$tournament]))->all();

        $matches  = TournamentMatch::where('tournament_id', $tournament->id)
            ->orderBy('round')->orderBy('match_index')->get();
        $listings = Listing::whereIn('id', $matches->pluck('listing_id')->filter()->unique())->get()->keyBy('id');
        $requests = GameJoinRequest::where('game_id', $tournament->id)->orderBy('created_at')->get();

        $mine = $viewer?->uuid ? $requests->firstWhere('joiner_customer_id', $viewer->uuid) : null;

        return sendSuccess('Tournament', [
            'tournament' => $decorated,
            'matches'    => $matches->map(fn ($m) => $this->mapMatch($m, $listings))->values()->all(),
            'requests'   => $requests->map(fn ($r) => $this->mapRequest($r))->values()->all(),
            'totalRounds' => $this->roundsFor((int) $tournament->num_teams),
            'myRequest'  => $mine ? $this->mapRequest($mine) : null,
            'isHost'     => $viewer?->uuid && $viewer->uuid === $tournament->host_customer_id,
        ]);
    }

    /**
     * Seed the first round from the accepted teams, in the order they were
     * accepted. A team left without an opponent walks into the next round.
     */
    public function generateMatchups(User $host, string $uuid)
    {
        $tournament = HostedGame::where('uuid', $uuid)->where('game_type', 'tournament')->first();

        if (! $tournament) {
            return sendError('Tournament not found.', [], 404);
        }

        if ($tournament->host_customer_id !== $host->uuid) {
            return sendError('Only the host can seed the bracket.', [], 403);
        }

        $accepted = GameJoinRequest::where('game_id', $tournament->id)
            ->where('status', 'accepted')->orderBy('created_at')->get()->values();

        $round0 = TournamentMatch::where('tournament_id', $tournament->id)
            ->where('round', 0)->orderBy('match_index')->get();

        $totalRounds = $this->roundsFor((int) $tournament->num_teams);

        foreach ($round0 as $i => $match) {
            $home = $accepted[$i * 2] ?? null;
            $away = $accepted[$i * 2 + 1] ?? null;

            $match->update([
                'home_team'       => $home?->joiner_name ?? '',
                'home_request_id' => $home?->id,
                'away_team'       => $away?->joiner_name ?? '',
                'away_request_id' => $away?->id,
            ]);

            // A lone team has nobody to beat, so it advances instead of stalling.
            if ($home && ! $away) {
                $match->update(['winner' => 'home', 'status' => 'done']);
                $this->advanceWinner($tournament, $match, $home->joiner_name, $home->id, $totalRounds);
            }
        }

        return sendSuccess('Bracket seeded', [
            'count'    => $accepted->count(),
            'overflow' => max(0, $accepted->count() - $round0->count() * 2),
        ]);
    }

    /** Host records who won; the winner moves into the next round. */
    public function setMatchWinner(User $host, string $matchUuid, string $side)
    {
        $match = TournamentMatch::where('uuid', $matchUuid)->first();

        if (! $match) {
            return sendError('Match not found.', [], 404);
        }

        $tournament = HostedGame::find($match->tournament_id);

        if (! $tournament || $tournament->host_customer_id !== $host->uuid) {
            return sendError('Only the host can record results.', [], 403);
        }

        $team = $side === 'home' ? $match->home_team : $match->away_team;

        if (! $team) {
            return sendError('That side has no team yet.', [], 422);
        }

        $match->update(['winner' => $side, 'status' => 'done']);

        $this->advanceWinner(
            $tournament,
            $match,
            $team,
            $side === 'home' ? $match->home_request_id : $match->away_request_id,
            $this->roundsFor((int) $tournament->num_teams)
        );

        return sendSuccess('Result saved', ['ok' => true]);
    }

    /** Undo a result — only while the winner hasn't already played on. */
    public function clearMatchWinner(User $host, string $matchUuid)
    {
        $match = TournamentMatch::where('uuid', $matchUuid)->first();

        if (! $match) {
            return sendError('Match not found.', [], 404);
        }

        $tournament = HostedGame::find($match->tournament_id);

        if (! $tournament || $tournament->host_customer_id !== $host->uuid) {
            return sendError('Only the host can change results.', [], 403);
        }

        $next = $this->nextMatchFor(
            (int) $match->round,
            (int) $match->match_index,
            $this->roundsFor((int) $tournament->num_teams)
        );

        if ($next) {
            $target = TournamentMatch::where('tournament_id', $tournament->id)
                ->where('round', $next['round'])->where('match_index', $next['index'])->first();

            if ($target?->winner) {
                return sendError('That team already played their next match. Undo that later result first, then change this one.', [], 422);
            }

            $target?->update($next['slot'] === 'home'
                ? ['home_team' => '', 'home_request_id' => null]
                : ['away_team' => '', 'away_request_id' => null]);
        }

        $match->update(['winner' => null, 'status' => 'scheduled']);

        return sendSuccess('Result cleared', ['ok' => true]);
    }

    /** Carry a winning team into its next-round slot. */
    protected function advanceWinner(HostedGame $tournament, TournamentMatch $match, string $team, ?int $requestId, int $totalRounds): void
    {
        $next = $this->nextMatchFor((int) $match->round, (int) $match->match_index, $totalRounds);

        if (! $next) {
            return;
        }

        $target = TournamentMatch::where('tournament_id', $tournament->id)
            ->where('round', $next['round'])->where('match_index', $next['index'])->first();

        $target?->update($next['slot'] === 'home'
            ? ['home_team' => $team, 'home_request_id' => $requestId]
            : ['away_team' => $team, 'away_request_id' => $requestId]);
    }

    /** Rounds in a single-elimination bracket. Mirrors bracket.js. */
    protected function roundsFor(int $teams): int
    {
        return max(1, (int) round(log(max(2, $teams), 2)));
    }

    /**
     * Where the winner of (round, index) goes — null at the Final. Winners of the
     * even-numbered match take the home slot, the odd one the away slot.
     *
     * @return array{round:int,index:int,slot:string}|null
     */
    protected function nextMatchFor(int $round, int $index, int $totalRounds): ?array
    {
        if ($round >= $totalRounds - 1) {
            return null;
        }

        return [
            'round' => $round + 1,
            'index' => intdiv($index, 2),
            'slot'  => $index % 2 === 0 ? 'home' : 'away',
        ];
    }

    /** @return array<string, mixed> */
    protected function mapMatch(TournamentMatch $m, $listings): array
    {
        return [
            'id'              => $m->uuid,
            'round'           => (int) $m->round,
            'match_index'     => (int) $m->match_index,
            'listing_id'      => $m->listing_id,
            'listingName'     => $listings->get($m->listing_id)?->name ?? '',
            'location_id'     => $m->location_id,
            'match_date'      => $m->match_date ? Carbon::parse($m->match_date)->toDateString() : null,
            'start_time'      => $m->start_time,
            'end_time'        => $m->end_time,
            'home_team'       => $m->home_team ?? '',
            'away_team'       => $m->away_team ?? '',
            'home_request_id' => $m->home_request_id,
            'away_request_id' => $m->away_request_id,
            'winner'          => $m->winner ?? '',
            'status'          => $m->status ?? 'scheduled',
        ];
    }

    // ── Shaping ──────────────────────────────────────────────────────────────



    protected function venueFor(Listing $listing): ?Venue
    {
        return $listing->venue ?: Venue::find($listing->venue_id);
    }

    /**
     * Add the people context the event cards show — who is going, who is hosting,
     * and the community an event belongs to.
     *
     * @param  \Illuminate\Support\Collection<int, HostedGame>  $games
     */
    protected function decorateGames($games)
    {
        $ids      = $games->pluck('id')->all();
        $requests = $ids
            ? GameJoinRequest::whereIn('game_id', $ids)->where('status', 'accepted')->get()
            : collect();

        $venues = Venue::whereIn('id', $games->pluck('location_id')->filter()->unique())->get()->keyBy('id');
        $hosts  = User::whereIn('uuid', $games->pluck('host_customer_id')->filter()->unique())->get()->keyBy('uuid');
        // A list can mix communities, so load them all rather than assume one.
        $communities = Community::whereIn('id', $games->pluck('community_id')->filter()->unique())->get()->keyBy('id');

        return $games->map(function (HostedGame $g) use ($requests, $venues, $hosts, $communities) {
            $community = $g->community_id ? $communities->get($g->community_id) : null;
            $going = $requests->where('game_id', $g->id);
            $host  = $hosts->get($g->host_customer_id);
            $venue = $g->location_id ? $venues->get($g->location_id) : null;

            return $this->mapGame($g, $communities) + [
                'locationName'    => $venue?->name ?? '',
                'locationAddress' => $venue?->address ?? '',
                'joinedCount'     => $going->count(),
                // Legacy rows point at customers that were never migrated, so the
                // name saved on the row is the dependable one.
                'hostName'        => $host?->name ?: (string) $g->host_name,
                'hostAvatar'      => $host?->image ?: '',
                'communityName'   => $community?->name ?? '',
                'communityAvatar' => $community?->avatar_image ?? '',
                'goingAvatars'    => $going->take(5)->map(fn ($r) => [
                    'name'   => (string) $r->joiner_name,
                    'avatar' => '',
                ])->values()->all(),
                'goingNames'      => $going->take(2)
                    ->map(fn ($r) => Str::before(trim((string) $r->joiner_name), ' '))
                    ->filter()->values()->all(),
            ];
        });
    }

    /** @return array<string, mixed> */
    protected function mapGame(HostedGame $g, $communities = null): array
    {
        return [
            'id'                      => $g->uuid,
            'host_customer_id'        => $g->host_customer_id,
            'host_name'               => $g->host_name ?? '',
            'title'                   => $g->title ?? '',
            'sport'                   => $g->sport ?? '',
            'game_type'               => $g->game_type ?? 'game',
            'game_date'               => $g->game_date ? Carbon::parse($g->game_date)->toDateString() : null,
            'start_time'              => $g->start_time,
            'end_time'                => $g->end_time,
            'price_per_person'        => (float) $g->price_per_person,
            'currency'                => $g->currency ?? '',
            'max_players'             => (int) $g->max_players,
            'skill_level'             => $g->skill_level ?? 'all',
            'description'             => $g->description ?? '',
            'status'                  => $g->status ?? 'open',
            'prize_pot'               => (float) $g->prize_pot,
            'prize_note'              => $g->prize_note ?? '',
            'custom_location_name'    => $g->custom_location_name ?? '',
            'custom_location_address' => $g->custom_location_address ?? '',
            'hide_location'           => (bool) $g->hide_location,
            'images'                  => $g->images ?? [],
            'approval_mode'           => $g->approval_mode ?? 'review',
            'allow_waitlist'          => $g->allow_waitlist !== false,
            'request_deadline'        => optional($g->request_deadline)?->toIso8601String(),
            'lock_minutes_before'     => $g->lock_minutes_before,
            'play_format'             => $g->play_format,
            'num_teams'               => (int) $g->num_teams,
            'community_id'            => $g->community_id
                ? ($communities?->get($g->community_id) ?? Community::find($g->community_id))?->uuid
                : null,
            'community_public'        => (bool) $g->community_public,
            'moderation_status'       => $g->moderation_status ?: 'active',
            'created_at'              => optional($g->created_at)?->toIso8601String(),
        ];
    }
}
