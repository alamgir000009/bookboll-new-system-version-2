<?php

namespace App\Http\Controllers;

use App\Models\User;
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
