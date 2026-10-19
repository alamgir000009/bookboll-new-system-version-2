<?php

namespace App\Http\Controllers;

use App\Services\MobileAppAdoptionService;
use Illuminate\Http\Request;

/**
 * Mobile-app adoption.
 *
 * The app audience is fixed by the route, not read from the body: the customer
 * endpoints can only ever write customer adoption, and the admin endpoints only
 * admin adoption. The account comes from the validated session in every case.
 */
class MobileAppAdoptionController extends Controller
{
    public function __construct(protected MobileAppAdoptionService $adoption)
    {
    }

    public function registerCustomer(Request $request)
    {
        return $this->adoption->register($request, 'customer');
    }

    public function registerAdmin(Request $request)
    {
        return $this->adoption->register($request, 'admin');
    }

    public function customerStatus(Request $request)
    {
        return $this->adoption->status($request, 'customer');
    }

    public function adminStatus(Request $request)
    {
        return $this->adoption->status($request, 'admin');
    }

    public function setCustomerPreference(Request $request)
    {
        return $this->adoption->setPreference($request, 'customer');
    }

    public function setAdminPreference(Request $request)
    {
        return $this->adoption->setPreference($request, 'admin');
    }

    public function report(Request $request)
    {
        return $this->adoption->report($request);
    }
}
