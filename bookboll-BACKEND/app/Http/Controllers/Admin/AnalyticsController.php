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

    /**
     * Server-computed totals with an explicit coverage statement.
     *
     * Separate from index() on purpose: that endpoint's raw arrays stay exactly
     * as they are for existing consumers, and this is what the finance-shaped
     * cards read instead of adding up a truncated page in the browser.
     */
    public function aggregates(Request $request)
    {
        return app(\App\Services\AnalyticsAggregateService::class)->aggregates($request);
    }
}
