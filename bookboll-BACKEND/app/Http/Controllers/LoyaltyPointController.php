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
}
