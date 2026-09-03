<?php

namespace App\Http\Controllers;

use App\Services\NotificationFeedService;

class NotificationFeedController extends Controller
{
    public function __construct(protected NotificationFeedService $feed) {}

    public function index()
    {
        return $this->feed->feed();
    }

    public function markAllRead()
    {
        return $this->feed->markAllRead();
    }
}
