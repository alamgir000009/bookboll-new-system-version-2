<?php

namespace App\Http\Controllers;

use App\Services\OAuthBrokerService;
use Illuminate\Http\Request;

/**
 * The single origin every tenant storefront borrows to sign in with Google.
 *
 * See App\Services\OAuthBrokerService for why this exists and how the three steps fit
 * together. `start` and `callback` are browser redirects inside a popup; `exchange` is
 * the storefront's own fetch.
 */
class OAuthBrokerController extends Controller
{
    public function __construct(protected OAuthBrokerService $broker)
    {
    }

    public function start(Request $request)
    {
        return $this->broker->start($request);
    }

    public function callback(Request $request)
    {
        return $this->broker->callback($request);
    }

    public function exchange(Request $request)
    {
        return $this->broker->exchange($request);
    }
}
