<?php
namespace App\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Models\Booking;
use App\Models\Promocode;
use App\Services\PromocodeService;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

class PromocodeController extends Controller
{
    public function __construct(protected PromocodeService $promocodeService)
    {}

    public function index(Request $request)
    {
        return $this->promocodeService->all($request);
    }

    public function store(Request $request)
    {
        return $this->promocodeService->create($request);
    }

    public function update(Request $request, $id)
    {
        return $this->promocodeService->update($request, $id);
    }

    public function destroy($id)
    {
        return $this->promocodeService->delete($id);
    }

    public function validate(Request $request)
    {
        return $this->promocodeService->validatePromocode($request);
    }

    // Apply promocode to order (increments usage)
    public function apply(Request $request, Promocode $promocode)
    {
        return $this->promocodeService->applyPromocode($request, $promocode);
    }

    // Search endpoints for frontend autocomplete
    public function searchVenues(Request $request)
    {
        $venues = $this->promocodeService->getApplicableVenues($request);
        return sendSuccess('Venues found', compact('venues'));
    }

    public function searchListings(Request $request)
    {
        $listings = $this->promocodeService->getApplicableListings($request);
        return sendSuccess('Listings found', compact('listings'));
    }

    public function applyPromocode(Request $request)
    {
        $request->validate([
            'promocode'      => 'required|string',
            'order_amount'   => 'required|numeric|min:0',
            'user_id'        => 'nullable|exists:users,id',
            'payment_method' => 'nullable|string',
            'venue_id'       => 'nullable|exists:venues,id',
            'listing_id'     => 'nullable|exists:listings,id',
        ]);

        $promocode = Promocode::where('code', $request->promocode)->active()->first();

        if (! $promocode) {
            return sendError('Invalid or inactive promocode');
        }

        $isValid = $promocode->isValidForOrder(
            $request->order_amount,
            $request->user_id,
            $request->payment_method,
            $request->venue_id,
            $request->listing_id
        );

        if (! $isValid) {
            return sendError('Promocode is not applicable');
        }

        $discount = $promocode->applyDiscount($request->order_amount);

        $promocode->incrementUsage($request->user_id);

        return sendSuccess('Promocode applied successfully', [
            'promocode' => [
                'discount_amount'  => $discount,
                'code'             => $promocode->code,
                'discount'         => $promocode->formatted_discount,
                'new_order_amount' => $request->order_amount - $discount,
            ],
        ]);
    }
}
