<?php

namespace App\Http\Controllers;

use App\Services\HostPayoutService;
use Illuminate\Http\Request;

class HostPayoutController extends Controller
{
    public function __construct(protected HostPayoutService $payouts) {}

    /** The host's current payout setup, if any. */
    public function show()
    {
        return $this->payouts->status(auth()->user());
    }

    /** Begin Stripe Express onboarding and hand back the hosted link. */
    public function connect(Request $request)
    {
        $data = $request->validate([
            'return_url'  => 'required|url|max:2048',
            'refresh_url' => 'required|url|max:2048',
        ]);

        return $this->payouts->startConnect(auth()->user(), $data['return_url'], $data['refresh_url']);
    }

    /** Re-read the connected account from Stripe after onboarding. */
    public function sync()
    {
        return $this->payouts->syncConnect(auth()->user());
    }

    /** Verify and store the host's own Stripe keys. */
    public function saveKeys(Request $request)
    {
        $data = $request->validate([
            'secret_key'      => 'required|string|max:255',
            'publishable_key' => 'nullable|string|max:255',
        ]);

        return $this->payouts->saveKeys(auth()->user(), $data['secret_key'], $data['publishable_key'] ?? null);
    }

    public function destroy()
    {
        return $this->payouts->disconnect(auth()->user());
    }
}
