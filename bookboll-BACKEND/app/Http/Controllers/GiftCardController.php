<?php

namespace App\Http\Controllers;

use App\Services\GiftCardService;
use Illuminate\Http\Request;

class GiftCardController extends Controller
{
    public function __construct(protected GiftCardService $giftCardService){}

    public function index(Request $request)
    {
        return $this->giftCardService->all($request);
    }

    public function store(Request $request)
    {
        return $this->giftCardService->create($request);
    }

    public function update(Request $request, $id)
    {
        return $this->giftCardService->update($request, $id);
    }

    public function destroy($id)
    {
        return $this->giftCardService->delete($id);
    }
}
