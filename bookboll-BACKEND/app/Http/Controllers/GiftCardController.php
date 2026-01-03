<?php
namespace App\Http\Controllers;

use App\Services\GiftCardService;
use Illuminate\Http\Request;

class GiftCardController extends Controller
{
    public function __construct(protected GiftCardService $giftCardService)
    {}

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

    public function fetchGiftCards(Request $request)
    {
        $request->merge(['status' => 'active']);
        return $this->giftCardService->all($request);
    }

    public function redeemGiftCard(Request $request)
    {
        return $this->giftCardService->redeem($request);
    }

    public function getBalance()
    {
        return  $this->giftCardService->getUserGiftCardBalance(auth()->id());
    }
}
