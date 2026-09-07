<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Services\AdminOverviewService;
use Illuminate\Http\Request;

/**
 * Dashboard landing: the onboarding checklist plus the caller's venues at a
 * glance. Backs Home.vue and the floating SetupCoach.
 */
class OverviewController extends Controller
{
    public function __construct(protected AdminOverviewService $overview)
    {}

    public function index(Request $request)
    {
        return $this->overview->index($request);
    }
}
