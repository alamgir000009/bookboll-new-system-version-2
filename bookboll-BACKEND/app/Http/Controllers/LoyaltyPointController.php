<?php
namespace App\Http\Controllers;

use App\Services\LoyaltyPointService;
use Illuminate\Http\Request;

class LoyaltyPointController extends Controller
{
    public function __construct(protected LoyaltyPointService $loyaltyPointService)
    {}

    public function extraDataForLoyalty(Request $request)
    {
        return $this->loyaltyPointService->extraDataForLoyalty($request);
    }

    public function customerLoyalty(Request $request)
    {
        return $this->loyaltyPointService->customerLoyalty($request);
    }

    /**
     * How many points this checkout may spend. Prices nothing of the
     * customer's: no booking is made and no points are debited or reserved.
     */
    public function checkoutQuote(Request $request)
    {
        return app(\App\Services\LoyaltyCheckoutQuoteService::class)->quote($request);
    }
}
