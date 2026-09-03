<?php

namespace App\Http\Controllers;

use App\Services\CheckInService;
use Illuminate\Http\Request;

class CheckInController extends Controller
{
    public function __construct(protected CheckInService $checkIn) {}

    public function lookup(Request $request)
    {
        return $this->checkIn->lookup($request);
    }

    public function record(Request $request)
    {
        return $this->checkIn->record($request);
    }

    public function recent()
    {
        return $this->checkIn->recent();
    }

    public function roster()
    {
        return $this->checkIn->roster();
    }

    public function markPresent(Request $request)
    {
        return $this->checkIn->markPresent($request);
    }
}
