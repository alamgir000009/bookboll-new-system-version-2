<?php
namespace App\Services;

use App\Models\GiftCard;

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
            ->get();

        return sendSuccess('Gift cards retrieved successfully', compact('giftCards'));
    }

    public function create($request)
    {
        $validated = $request->validate([
            'title'           => 'required|string|max:255|unique:gift_cards,title',
            'description'     => 'nullable|string|max:1000',
            'points_required' => 'required|integer|min:1',
            'value'           => 'required|numeric|min:0.01',
            'currency'        => 'required|max:100',
            'status'          => 'required|in:active,inactive',
        ]);

        $giftCard = $this->giftCardModel::create($validated);
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
            'currency'        => 'required|max:100',
            'status'          => 'required|in:active,inactive',
        ]);

        $giftCard->update($formData);
        return sendSuccess('Gift card updated successfully', compact('giftCard'));
    }

    public function delete(int $id)
    {
        $giftCard = GiftCard::findOrFail($id);
        $giftCard->delete();
        return sendSuccess('Gift card deleted successfully', compact('giftCard'));
    }
}
