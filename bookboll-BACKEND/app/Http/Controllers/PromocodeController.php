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

    // Validate/apply promocode
    public function validate(Request $request, Promocode $promocode)
    {
        $request->validate([
            'order_amount'   => 'required|numeric|min:0',
            'payment_method' => 'nullable|string|in:online,cash_on_arrival',
            'venue_id'       => 'nullable|exists:venues,id',
            'listing_id'     => 'nullable|exists:listings,id',
        ]);

        try {
            $promocode = $this->promocodeService->validateForUse(
                $promocode->code,
                $request->order_amount,
                auth()->id(),
                $request->payment_method,
                $request->venue_id,
                $request->listing_id
            );

            $discount = $promocode->applyDiscount($request->order_amount);

            return sendSuccess('Promocode is valid', [
                'promocode'          => [
                    'id'                 => $promocode->id,
                    'code'               => $promocode->code,
                    'discount_type'      => $promocode->discount_type,
                    'discount_value'     => $promocode->discount_value,
                    'formatted_discount' => $promocode->formatted_discount,
                    'used_count'         => $promocode->used_count,
                    'usage_remaining'    => $promocode->usage_limit ? max(0, $promocode->usage_limit - $promocode->used_count) : null,
                ],
                'discount_amount'    => number_format($discount, 2),
                'final_amount'       => number_format($request->order_amount - $discount, 2),
                'order_amount'       => number_format($request->order_amount, 2),
                'savings_percentage' => $promocode->discount_type === 'percentage' ? $promocode->discount_value : null,
            ]);

        } catch (ValidationException $e) {
            return sendError('Invalid promocode', $e->errors(), 422);
        }
    }

    // Apply promocode to order (increments usage)
    public function apply(Request $request, Promocode $promocode)
    {
        $request->validate([
            'discount_amount' => 'required|numeric|min:0',
            'venue_id'        => 'nullable|exists:venues,id',
            'booking_id'      => 'required|exists:bookings,id',
            'listing_id'      => 'nullable|exists:listings,id',
            'payment_method'  => 'nullable|string|in:online,cash_on_arrival',
        ]);

        $booking = Booking::findOrFail($request->booking_id);

        try {
            $promocode = $this->promocodeService->validateForUse(
                $promocode->code,
                $request->order_amount,
                auth()->id(),
                $request->payment_method,
                $request->venue_id,
                $request->listing_id
            );

            $discount = $promocode->applyDiscount($request->order_amount);

            $promocode->incrementUsage(auth()->id());

            return sendSuccess('Promocode applied successfully', [
                'promocode'       => $promocode->fresh(),
                'booking_id'      => $booking->booking_id,
                'discount_amount' => number_format($discount, 2),
                'final_amount'    => number_format($request->order_amount - $discount, 2),
            ]);

        } catch (ValidationException $e) {
            return sendError('Cannot apply promocode', $e->errors(), 422);
        }
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
                'code'             => $promocode->code,
                'discount'         => $promocode->formatted_discount,
                'discount_amount'  => $discount,
                'new_order_amount' => $request->order_amount - $discount,
            ],
        ]);
    }
}
