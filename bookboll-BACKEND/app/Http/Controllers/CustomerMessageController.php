<?php

namespace App\Http\Controllers;

use App\Services\CustomerMessageService;
use Illuminate\Http\Request;

/**
 * Direct messages between customers. Community group chat is served by
 * CustomerCommunityController; the inbox here lists both.
 */
class CustomerMessageController extends Controller
{
    public function __construct(protected CustomerMessageService $messages)
    {}

    /** The inbox: direct conversations + a row per community group chat. */
    public function index()
    {
        return $this->messages->inbox(auth()->user());
    }

    /** One direct thread with a peer, plus that peer's name and avatar. */
    public function thread(Request $request)
    {
        $data = $request->validate([
            'peer_id' => 'required|string|max:64',
            'limit'   => 'nullable|integer|min:1|max:500',
        ]);

        return $this->messages->thread(auth()->user(), $data['peer_id'], (int) ($data['limit'] ?? 200));
    }

    public function store(Request $request)
    {
        $data = $request->validate([
            'recipient_id' => 'required|string|max:64',
            'body'         => 'required|string|max:4000',
        ]);

        return $this->messages->send(auth()->user(), $data['recipient_id'], $data['body']);
    }

    public function markRead(Request $request)
    {
        $data = $request->validate([
            'peer_id' => 'required|string|max:64',
        ]);

        return $this->messages->markRead(auth()->user(), $data['peer_id']);
    }
}
