<?php

namespace App\Http\Controllers;

use App\Services\CustomerSocialService;
use Illuminate\Http\Request;

class CustomerSocialController extends Controller
{
    public function __construct(protected CustomerSocialService $socialService)
    {}

    public function follow(string $uuid)
    {
        return $this->socialService->follow(auth()->user(), $uuid);
    }

    public function unfollow(string $uuid)
    {
        return $this->socialService->unfollow(auth()->user(), $uuid);
    }

    public function followList(Request $request, string $uuid)
    {
        return $this->socialService->followList(
            $uuid,
            (string) $request->query('kind', 'followers'),
            auth()->user()
        );
    }

    public function respondFollowRequest(Request $request, int $id)
    {
        $request->validate(['accept' => 'required|boolean']);

        return $this->socialService->respondFollowRequest(
            auth()->user(),
            $id,
            (bool) $request->input('accept')
        );
    }

    public function createPost(Request $request)
    {
        $data = $request->validate([
            'body'    => 'nullable|string|max:1000',
            'photos'  => 'nullable|array',
            'photos.*'=> 'nullable|string|max:2048',
        ]);

        return $this->socialService->createPost(
            auth()->user(),
            $data['body'] ?? '',
            $data['photos'] ?? []
        );
    }

    public function deletePost(string $id)
    {
        return $this->socialService->deletePost(auth()->user(), $id);
    }
}
