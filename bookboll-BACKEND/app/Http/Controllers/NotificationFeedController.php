<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use App\Services\NotificationFeedService;
use App\Services\NotificationSweepService;

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

    /* ── Scheduled reminder sweep ────────────────────────────────────────── */

    public function sweepDue(Request $request, NotificationSweepService $sweep)
    {
        return $sweep->due($request);
    }

    public function sweepSent(Request $request, NotificationSweepService $sweep)
    {
        return $sweep->markSent($request);
    }
}
