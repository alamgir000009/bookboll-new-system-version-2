<?php

namespace App\Http\Controllers;

use App\Services\CustomerGameService;
use Illuminate\Http\Request;

class CustomerGameController extends Controller
{
    public function __construct(protected CustomerGameService $games) {}

    /** Feature flags and the rates the host wizard prices with. */
    public function config(Request $request)
    {
        return $this->games->config(
            $request->filled('listing_id') ? (int) $request->query('listing_id') : null,
            $request->boolean('community')
        );
    }

    /** Price a selection without publishing it. */
    public function quote(Request $request)
    {
        return $this->games->quote(auth()->user(), $request->validate($this->rules()));
    }

    /** Publish a game or a hangout. */
    public function store(Request $request)
    {
        return $this->games->create(auth()->user(), $request->validate($this->rules()));
    }

    /** Events posted to a community. */
    public function communityEvents(string $uuid)
    {
        return $this->games->communityEvents($uuid);
    }

    /** Post an event to a community, or toggle its public listing. */
    public function setCommunity(Request $request, string $uuid)
    {
        $data = $request->validate([
            'community_id'     => 'nullable|string|max:64',
            'community_public' => 'nullable|boolean',
        ]);

        return $this->games->setCommunity(
            auth()->user(),
            $uuid,
            $data['community_id'] ?? null,
            array_key_exists('community_public', $data) ? (bool) $data['community_public'] : null
        );
    }

    /** Every joinable event in a city — the Events page. */
    public function index(Request $request)
    {
        return $this->games->list($request->filled('city_id') ? (int) $request->query('city_id') : null);
    }

    /** Tournament games waiting on a result from me. */
    public function dueMatches()
    {
        return $this->games->dueTournamentMatches(auth()->user());
    }

    /** Raw rows behind the notification bell. */
    public function notificationFeed()
    {
        return $this->games->notificationFeed(auth()->user());
    }

    /** Events I'm hosting. */
    public function mineHosted()
    {
        return $this->games->mineHosted(auth()->user());
    }

    /** Events I've asked to join. */
    public function mineJoined()
    {
        return $this->games->mineJoined(auth()->user());
    }

    /** One event, for the detail page. */
    public function show(Request $request, string $uuid)
    {
        return $this->games->show($uuid, $this->optionalSanctumUser($request));
    }

    /** The host's roster of join requests. */
    public function requests(string $uuid)
    {
        return $this->games->requests(auth()->user(), $uuid);
    }

    /** Ask to join an event. */
    public function join(Request $request, string $uuid)
    {
        $data = $request->validate([
            'joiner_name'     => 'nullable|string|max:120',
            'joiner_phone'    => 'nullable|string|max:40',
            'joiner_whatsapp' => 'nullable|string|max:40',
            'joiner_message'  => 'nullable|string|max:1000',
            'form_answers'    => 'nullable|array',
        ]);

        return $this->games->join(auth()->user(), $uuid, $data);
    }

    /** The signed-in viewer's own request for this event. */
    public function myRequest(string $uuid)
    {
        return $this->games->myRequest(auth()->user(), $uuid);
    }

    /** Host accepts everyone waiting, up to the cap. */
    public function acceptAllRequests(string $uuid)
    {
        return $this->games->acceptAllRequests(auth()->user(), $uuid);
    }

    /** Host accepts or rejects a request. */
    public function respondToRequest(Request $request, string $requestUuid)
    {
        $data = $request->validate(['status' => 'required|in:accepted,rejected']);

        return $this->games->respondToRequest(auth()->user(), $requestUuid, $data['status']);
    }

    /** Drop a request — the joiner backing out, or the host removing them. */
    public function cancelRequest(string $requestUuid)
    {
        return $this->games->cancelRequest(auth()->user(), $requestUuid);
    }

    /** Host edits the event after publishing. */
    public function update(Request $request, string $uuid)
    {
        $data = $request->validate([
            'title'            => 'sometimes|string|max:200',
            'description'      => 'sometimes|nullable|string|max:5000',
            'skill_level'      => 'sometimes|string|max:32',
            'price_per_person' => 'sometimes|numeric|min:0',
            'max_players'      => 'sometimes|integer|min:0|max:1000',
            'host_name'        => 'sometimes|string|max:120',
            'host_phone'       => 'sometimes|nullable|string|max:40',
            'host_whatsapp'    => 'sometimes|nullable|string|max:40',
            'request_deadline' => 'sometimes|nullable|date',
            'prize_pot'        => 'sometimes|numeric|min:0',
            'prize_note'       => 'sometimes|nullable|string|max:500',
            'game_date'        => 'sometimes|date_format:Y-m-d',
            'start_time'       => 'sometimes|string|max:32',
            'end_time'         => 'sometimes|string|max:32',
        ]);

        return $this->games->updateGame(auth()->user(), $uuid, $data);
    }

    /** Host calls the event off. */
    public function cancel(string $uuid)
    {
        return $this->games->cancelGame(auth()->user(), $uuid);
    }

    /** Host marks who turned up. */
    public function attendance(Request $request, string $requestUuid)
    {
        $data = $request->validate(['attended' => 'required|boolean']);

        return $this->games->markAttendance(auth()->user(), $requestUuid, (bool) $data['attended']);
    }

    /** Host saves the round sheet. */
    public function saveRounds(Request $request, string $uuid)
    {
        $data = $request->validate(['rounds' => 'nullable|array']);

        return $this->games->saveRounds(auth()->user(), $uuid, $data['rounds'] ?? []);
    }

    /** Publish a knockout bracket. */
    public function storeTournament(Request $request)
    {
        $rules = $this->rules();
        unset($rules['game_type'], $rules['game_date'], $rules['start_time'], $rules['end_time'], $rules['listing_id']);

        $data = $request->validate($rules + [
            'num_teams'            => 'required|integer|in:4,8,16,32',
            'matches'              => 'required|array|min:1',
            'matches.*.round'      => 'required|integer|min:0',
            'matches.*.index'      => 'required|integer|min:0',
            'matches.*.listing_id' => 'required|integer|exists:listings,id',
            'matches.*.date'       => 'required|date_format:Y-m-d',
            'matches.*.start_time' => 'required|string|max:32',
            'matches.*.end_time'   => 'required|string|max:32',
        ]);

        return $this->games->createTournament(auth()->user(), $data);
    }

    /** A tournament with its bracket. */
    public function showTournament(Request $request, string $uuid)
    {
        return $this->games->showTournament($uuid, $this->optionalSanctumUser($request));
    }

    /** Host seeds round one from the accepted teams. */
    public function generateMatchups(string $uuid)
    {
        return $this->games->generateMatchups(auth()->user(), $uuid);
    }

    /** Host records a match result. */
    public function setMatchWinner(Request $request, string $matchUuid)
    {
        $data = $request->validate(['winner' => 'required|in:home,away']);

        return $this->games->setMatchWinner(auth()->user(), $matchUuid, $data['winner']);
    }

    /** Host undoes a match result. */
    public function clearMatchWinner(string $matchUuid)
    {
        return $this->games->clearMatchWinner(auth()->user(), $matchUuid);
    }

    /**
     * A game page is public, but a signed-in viewer sees their own request, so the
     * token is read by hand rather than through auth middleware.
     */
    protected function optionalSanctumUser(Request $request): ?\App\Models\User
    {
        $user = $request->user('sanctum');

        if ($user instanceof \App\Models\User) {
            return $user;
        }

        $token = $request->bearerToken();

        if (! $token) {
            return null;
        }

        $accessToken = \Laravel\Sanctum\PersonalAccessToken::findToken($token);

        return $accessToken?->tokenable instanceof \App\Models\User ? $accessToken->tokenable : null;
    }

    /**
     * A hangout may skip the court and use the host's own spot, so listing_id is
     * optional throughout and the venue fields carry the location instead.
     *
     * @return array<string, string>
     */
    protected function rules(): array
    {
        return [
            // 'event' is what the wizard calls a Hangout.
            'game_type'               => 'nullable|in:game,event',
            'listing_id'              => 'nullable|integer|exists:listings,id',
            'city_id'                 => 'nullable|integer|exists:cities,id',
            'country_id'              => 'nullable|integer|exists:countries,id',
            'title'                   => 'nullable|string|max:200',
            'sport'                   => 'nullable|string|max:80',
            'game_date'               => 'required|date_format:Y-m-d',
            'start_time'              => 'required|string|max:32',
            'end_time'                => 'required|string|max:32',
            'price_per_person'        => 'nullable|numeric|min:0',
            'max_players'             => 'nullable|integer|min:0|max:1000',
            'skill_level'             => 'nullable|string|max:32',
            'description'             => 'nullable|string|max:5000',
            'prize_pot'               => 'nullable|numeric|min:0',
            'prize_note'              => 'nullable|string|max:500',
            'custom_location_name'    => 'nullable|string|max:200',
            'custom_location_address' => 'nullable|string|max:500',
            'hide_location'           => 'nullable|boolean',
            'images'                  => 'nullable|array',
            'images.*'                => 'nullable|string|max:2048',
            'signup_form'             => 'nullable|array',
            'request_deadline'        => 'nullable|date',
            'approval_mode'           => 'nullable|in:review,instant',
            'allow_waitlist'          => 'nullable|boolean',
            'lock_minutes_before'     => 'nullable|integer|min:0',
            'play_format'             => 'nullable|string|max:64',
            'courts_count'            => 'nullable|integer|min:1',
            'community_id'            => 'nullable|string|max:64',
            'community_public'        => 'nullable|boolean',
            'host_name'               => 'nullable|string|max:120',
            'host_phone'              => 'nullable|string|max:40',
            'host_whatsapp'           => 'nullable|string|max:40',
            // What the host chose to spend, not what they owe — the server prices
            // the bill and caps redemption at the balance they actually hold.
            'loyalty_redeem'          => 'nullable|numeric|min:0',
            'ticket_mode'             => 'nullable|in:arrival,stripe',
        ];
    }
}
