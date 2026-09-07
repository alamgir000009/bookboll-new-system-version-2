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
}
