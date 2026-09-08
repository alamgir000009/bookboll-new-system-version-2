<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Services\ProviderPlanService;
use Illuminate\Http\Request;

/**
 * Bookboll for Business Premium, and the one-time custom-domain fee — the two
 * things a provider pays Bookboll for. See App\Services\ProviderPlanService.
 */
class ProviderPlanController extends Controller
{
    public function __construct(protected ProviderPlanService $plans)
    {}

    public function show(Request $request)
    {
        return $this->plans->state($request);
    }

    public function checkout(Request $request)
    {
        return $this->plans->checkout($request);
    }

    public function confirm(Request $request, string $session)
    {
        return $this->plans->confirmCheckout($request, $session);
    }

    public function cancel(Request $request)
    {
        return $this->plans->cancel($request);
    }

    public function resume(Request $request)
    {
        return $this->plans->resume($request);
    }

    public function domainCheckout(Request $request)
    {
        return $this->plans->domainCheckout($request);
    }

    public function confirmDomain(Request $request, string $session)
    {
        return $this->plans->confirmDomainCheckout($request, $session);
    }

    public function verifyDomain(Request $request)
    {
        return $this->plans->verifyDomain($request);
    }
}
