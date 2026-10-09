<?php

namespace App\Http\Controllers;

use App\Models\ClassProgram;
use App\Services\CustomerProgramService;
use App\Services\MembershipBillingService;
use Illuminate\Http\Request;

class CustomerProgramController extends Controller
{
    public function __construct(
        protected CustomerProgramService $programs,
        protected MembershipBillingService $billing,
    ) {}

    public function index(Request $request)
    {
        return $this->programs->list($request);
    }

    public function show(Request $request, string $uuid)
    {
        return $this->programs->show($uuid, $request);
    }

    public function hours(string $uuid)
    {
        return $this->programs->hours($uuid);
    }

    public function checkOffer(Request $request)
    {
        return $this->programs->checkIntroOffer($request);
    }

    public function enroll(Request $request, string $uuid)
    {
        $data = $request->validate([
            'answers'         => 'nullable|array',
            'discount'        => 'nullable|numeric|min:0',
            'card_fp'         => 'nullable|string|max:128',
            'loyalty_redeem'  => 'nullable|numeric|min:0',
        ]);

        $user = auth()->user();
        $program = ClassProgram::with('venue')->where('uuid', $uuid)->first();
        if (! $program) {
            return sendError('Program not found.');
        }

        // Paid memberships always go through Stripe Checkout — the trial, the first
        // charge and every renewal are Stripe's. Free programs enrol directly.
        if ((float) ($program->monthly_price ?: 0) > 0) {
            $res = $this->billing->createCheckoutSession($user, $program, $data);

            return isset($res['error'])
                ? sendError($res['error'])
                : sendSuccess('Checkout session created', $res);
        }

        return $this->programs->enroll($user, $uuid, $data);
    }

    public function mine(Request $request)
    {
        return $this->programs->mine(auth()->user(), $request);
    }

    public function cancel(string $uuid)
    {
        return $this->programs->cancel(auth()->user(), $uuid);
    }

    /** Undo a cancellation that has not taken effect yet. */
    public function resume(string $uuid)
    {
        return $this->programs->resume(auth()->user(), $uuid);
    }

    public function checkoutState(string $sessionId)
    {
        return $this->billing->checkoutState(auth()->user(), $sessionId);
    }

    public function passes()
    {
        return $this->programs->passes(auth()->user());
    }
}
