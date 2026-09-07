<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Services\PaymentAccountService;
use Illuminate\Http\Request;

/** A provider's connected bank account (Listings → payment account). */
class PaymentAccountController extends Controller
{
    public function __construct(protected PaymentAccountService $accounts)
    {}

    public function show(Request $request)
    {
        return $this->accounts->show($request);
    }

    public function store(Request $request)
    {
        return $this->accounts->store($request);
    }

    public function update(Request $request, $id)
    {
        return $this->accounts->update($request, $id);
    }

    public function destroy(Request $request, $id)
    {
        return $this->accounts->destroy($request, $id);
    }
}
