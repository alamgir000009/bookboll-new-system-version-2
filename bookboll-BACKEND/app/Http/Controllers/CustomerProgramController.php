<?php

namespace App\Http\Controllers;

use App\Services\CustomerProgramService;
use Illuminate\Http\Request;

class CustomerProgramController extends Controller
{
    public function __construct(protected CustomerProgramService $programs)
    {}

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

        return $this->programs->enroll(auth()->user(), $uuid, $data);
    }

    public function mine(Request $request)
    {
        return $this->programs->mine(auth()->user(), $request);
    }

    public function cancel(string $uuid)
    {
        return $this->programs->cancel(auth()->user(), $uuid);
    }

    public function passes()
    {
        return $this->programs->passes(auth()->user());
    }
}
