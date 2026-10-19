<?php

namespace App\Http\Controllers;

use App\Models\User;
use App\Services\PeopleSearchService;
use App\Services\PublicProfileService;
use Illuminate\Http\Request;
use Laravel\Sanctum\PersonalAccessToken;

class PublicProfileController extends Controller
{
    public function __construct(protected PublicProfileService $publicProfileService)
    {}

    public function show(Request $request, string $uuid)
    {
        $viewer = $this->optionalSanctumUser($request);

        return $this->publicProfileService->show($uuid, $viewer);
    }

    /**
     * Public people search for the Search page's People tab.
     *
     * Signed out by design — the tab is reachable without an account, exactly
     * like /u/:id. A viewer is read when a token happens to be present, only
     * so somebody who has opted out of being listed can still find themselves.
     */
    public function search(Request $request, PeopleSearchService $people)
    {
        return $people->search($request, $this->optionalSanctumUser($request));
    }

    protected function optionalSanctumUser(Request $request): ?User
    {
        $user = $request->user('sanctum');
        if ($user instanceof User) {
            return $user;
        }

        $token = $request->bearerToken();
        if (! $token) {
            return null;
        }

        $accessToken = PersonalAccessToken::findToken($token);

        return $accessToken?->tokenable instanceof User ? $accessToken->tokenable : null;
    }
}
