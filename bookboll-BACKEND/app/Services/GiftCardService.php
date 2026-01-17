<?php
namespace App\Services;

use App\Models\GiftCard;
use App\Models\LoyaltyPoint;
use App\Models\Transaction;

class GiftCardService
{
    public function __construct(protected GiftCard $giftCardModel)
    {}

    public function all($request)
    {

        $search    = $request->search ?? null;
        $giftCards = $this->giftCardModel::orderBy('title')
            ->when($search, function ($query, $search) {
                $query->where('title', 'LIKE', "%$search}%")
                    ->orWhere('value', 'LIKE', "%$search}%")
                    ->orWhere('status', 'LIKE', "%$search}%")
                    ->orWhere('currency', 'LIKE', "%$search}%")
                    ->orWhere('description', 'LIKE', "%$search}%");
            })
            ->when($request->status, function ($query, $status) {
                $query->where("status", $status);
            })
            ->get();

        $data['giftCards'] = $giftCards;
        if ($request->status) {

            $customer        = auth()->user();
            $availablePoints = LoyaltyPoint::balance($customer->id);
            $redeemedPoints  = $customer->loyaltyPoints()->where('type', 'debit')->sum('points');
            $balance         = Transaction::balance($customer->id);

            $data['balance']         = $balance;
            $data['redeemedPoints']  = $redeemedPoints;
            $data['availablePoints'] = $availablePoints;

        }

        return sendSuccess('Gift cards retrieved successfully', $data);
    }

    public function create($request)
    {
        $formData = $request->validate([
            'title'           => 'required|string|max:255|unique:gift_cards,title',
            'description'     => 'nullable|string|max:1000',
            'points_required' => 'required|integer|min:1',
            'value'           => 'required|numeric|min:0.01',
            'currency'        => 'nullable|max:100',
            'status'          => 'required|in:active,inactive',
            'image'           => 'nullable|image',
        ]);

        if (! empty($formData['image'])) {
            $formData['image'] = $formData['image']->store('gift-cards', 'public');
        }

        $giftCard = $this->giftCardModel::create($formData);
        return sendSuccess('Gift Card added successfullly', compact('giftCard'));
    }

    public function update($request, int $id)
    {
        $giftCard = GiftCard::findOrFail($id);

        $formData = $request->validate([
            'title'           => 'required|string|max:255|unique:gift_cards,title,' . $id,
            'description'     => 'nullable|string|max:1000',
            'points_required' => 'required|integer|min:1',
            'value'           => 'required|numeric|min:0.01',
            'currency'        => 'nullable|max:100',
            'status'          => 'required|in:active,inactive',
            'image'           => 'nullable|image',
        ]);

        if (! empty($formData['image'])) {
            $formData['image'] = $formData['image']->store('gift-cards', 'public');
        } else {
            unset($formData['image']);
        }

        $giftCard->update($formData);
        return sendSuccess('Gift card updated successfully', compact('giftCard'));
    }

    public function delete(int $id)
    {
        $giftCard = GiftCard::findOrFail($id);
        $giftCard->delete();
        return sendSuccess('Gift card deleted successfully', compact('giftCard'));
    }

    public function redeem($request)
    {
        $request->validate(['gift_card_id' => 'required|exists:gift_cards,id']);

        $customer = auth()->user();
        $giftCard = GiftCard::find($request->gift_card_id);

        if (LoyaltyPoint::balance($customer->id) < $giftCard->points_required) {
            return sendSuccess('Insufficient points to redeem this gift card.');
        }

        $customer->loyaltyPoints()->create([
            'type'   => 'debit',
            'points' => $giftCard->points_required,
            'reason' => "Redeemed '{$giftCard->title}' gift card",
        ]);

        $customer->transactions()->create([
            'gift_card_id' => $giftCard->id,
            'amount'       => $giftCard->value,
            'reason'       => "Gift card redeemed: {$giftCard->title}",
        ]);

        $availablePoints = LoyaltyPoint::balance($customer->id);
        $redeemedPoints  = $customer->loyaltyPoints()->where('type', 'debit')->sum('points');
        $balance         = Transaction::balance($customer->id);

        $data['balance']         = $balance;
        $data['redeemedPoints']  = $redeemedPoints;
        $data['availablePoints'] = $availablePoints;

        return sendSuccess("Gift card redeemed successfully", $data);
    }

    public function getUserGiftCardBalance(int $userId)
    {
        $balance = Transaction::balance($userId ?? auth()->id()) ?? 0;
        return sendSuccess('Balance fetched successfully', compact('balance'));
    }
}
