<?php

namespace App\Http\Controllers;

use App\Services\MarketingService;
use Illuminate\Http\Request;

class MarketingController extends Controller
{
    public function __construct(protected MarketingService $marketing)
    {}

    /**
     * One-click unsubscribe from a marketing email. Public by design — the
     * recipient has no session when they tap the link; the token is the credential.
     */
    public function unsubscribe(Request $request)
    {
        return $this->marketing->unsubscribe($request);
    }

    /* ── Campaign ledger (admin) ─────────────────────────────────────────── */

    public function campaigns(Request $request)
    {
        return $this->marketing->campaigns($request);
    }

    public function recordCampaign(Request $request)
    {
        return $this->marketing->recordCampaign($request);
    }

    public function deleteCampaign(Request $request, $id)
    {
        return $this->marketing->deleteCampaign($request, $id);
    }
}
