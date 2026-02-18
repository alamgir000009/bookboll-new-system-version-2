<?php
namespace App\Services;

use App\Models\GiftCard;
use App\Models\LoyaltyPoint;
use App\Models\Promocode;

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
        $customer = auth()->user();
        if ($customer) {
            $availablePoints = LoyaltyPoint::balance($customer->id);
            $redeemedPoints  = $customer->loyaltyPoints()->where('type', 'debit')->sum('points');
            $giftCardPromocodesTotal = Promocode::giftCardPromocodes()
                ->whereHas('users', fn($q) => $q->where('user_id', $customer->id))
                ->where('status', 'active')
                ->whereRaw('(usage_limit IS NULL OR used_count < usage_limit)')
                ->sum('discount_value');

            $data['balance']         = $giftCardPromocodesTotal;
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
            return sendError('Insufficient points to redeem this gift card.');
        }

        $customer->loyaltyPoints()->create([
            'type'   => 'debit',
            'points' => $giftCard->points_required,
            'reason' => "Redeemed '{$giftCard->title}' gift card",
        ]);

        $code = $this->generateUniqueGiftCardPromocode();

        $promocode = Promocode::create([
            'code'                   => $code,
            'status'                 => 'active',
            'description'            => "Gift card redeemed: {$giftCard->title}",
            'discount_type'          => 'fixed',
            'discount_value'         => $giftCard->value,
            'payment_methods'        => 'all',
            'usage_limit'            => 1,
            'per_user_limit'         => 1,
            'is_gift_card_promocode' => true,
            'gift_card_id'           => $giftCard->id,
        ]);

        $promocode->users()->attach($customer->id, ['usage_count' => 0]);

        $availablePoints = LoyaltyPoint::balance($customer->id);
        $redeemedPoints  = $customer->loyaltyPoints()->where('type', 'debit')->sum('points');

        $data['promocode']       = [
            'code'   => $promocode->code,
            'value'  => (float) $promocode->discount_value,
            'format' => "QAR {$promocode->discount_value} off - Use at checkout",
        ];
        $data['redeemedPoints']  = $redeemedPoints;
        $data['availablePoints'] = $availablePoints;

        return sendSuccess("Gift card redeemed successfully! Use code {$promocode->code} at checkout.", $data);
    }

    public function getUserGiftCardBalance(int $userId)
    {
        $userId = $userId ?? auth()->id();
        $balance = \App\Models\Transaction::balance($userId) ?? 0;
        return sendSuccess('Balance fetched successfully', compact('balance'));
    }

    public function getGiftCardPromocodes(int $userId)
    {
        $promocodes = Promocode::giftCardPromocodes()
            ->with('giftCard:id,title,value')
            ->whereHas('users', fn($q) => $q->where('user_id', $userId))
            ->where('status', 'active')
            ->whereRaw('(usage_limit IS NULL OR used_count < usage_limit)')
            ->latest()
            ->get();

        return sendSuccess('Gift card promocodes fetched successfully', compact('promocodes'));
    }

    protected function generateUniqueGiftCardPromocode(): string
    {
        do {
            $code = 'GC-' . strtoupper(substr(bin2hex(random_bytes(6)), 0, 8));
        } while (Promocode::where('code', $code)->exists());

        return $code;
    }
}
