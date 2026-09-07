<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Services\AdminAnalyticsService;
use Illuminate\Http\Request;

/**
 * The analytics dashboard's dataset. Returns raw collections — the page owns the
 * KPI and chart maths.
 */
class AnalyticsController extends Controller
{
    public function __construct(protected AdminAnalyticsService $analytics)
    {}

    public function index(Request $request)
    {
        return $this->analytics->index($request);
    }
}
