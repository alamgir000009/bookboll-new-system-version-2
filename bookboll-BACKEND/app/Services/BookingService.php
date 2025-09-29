<?php
namespace App\Services;

use App\Models\AddOn;
use App\Models\Booking;
use App\Models\Listing;
use App\Models\Promocode;
use Carbon\Carbon;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\ValidationException;

class BookingService
{
    public function __construct(protected Booking $bookingModel)
    {}

    public function all($request)
    {
        $perPage = $request->per_page ?? 10;
        $search  = $request->search ?? null;

        $bookings = $this->bookingModel::with(['user', 'venue', 'listing', 'promocode', 'addOns', 'user'])
            ->when($search, function ($q) use ($search) {
                $q->where(function ($q) use ($search) {
                    $q->whereHas('listing', fn($l) => $l->where('name', 'LIKE', "%{$search}%"))
                        ->orWhere('duration', 'LIKE', "%{$search}%")
                        ->orWhere('total_price', 'LIKE', "%{$search}%")
                        ->orWhere('payment_method', 'LIKE', "%{$search}%")
                        ->orWhere('notes', 'LIKE', "%{$search}%")
                        ->orWhere('created_at', 'LIKE', "%{$search}%")
                        ->orWhere('subtotal', 'LIKE', "%{$search}%");
                });
            })
            ->when($request->status ?? null, fn($q, $status) => $q->where('status', $status))
            ->when($request->from_date ?? null, fn($q, $from) => $q->whereDate('created_at', '>=', $from))
            ->when($request->to_date ?? null, fn($q, $to) => $q->whereDate('created_at', '<=', $to))
            ->when($request->venue ?? null, function ($q, $venue) {
                $q->where('venue_id', 'LIKE', "%{$venue}%");
            })
            ->when($request->user ?? null, function ($q, $user) {
                $q->whereHas('user', fn($u) => $u->where('name', 'LIKE', "%{$user}%"));
            })
            ->paginate($perPage);

        return sendSuccess('Bookings fetched successfully', compact('bookings'));
    }

    public function create($request)
    {
        $validated = $this->validateRequest($request);

        $startTime = Carbon::parse($validated['start_time']);
        $duration  = intval($validated['duration']);
        $endTime   = $startTime->copy()->addHours($duration);

        $listing = Listing::findOrFail($validated['listing_id']);

        $subtotal = $this->calculateSubtotal($listing, $startTime, $duration);

        $addOns         = $validated['add_ons'] ?? [];
        $addOnCost      = 0;
        $addOnExtension = 0;
        foreach ($addOns as $addOnId) {
            $addOn = AddOn::findOrFail($addOnId);
            $addOnCost += $addOn->price;
            $addOnExtension += $addOn->hours_extension;
        }

        $endTime->addHours($addOnExtension);
        $duration += $addOnExtension;

        $subtotal += $addOnCost;

        $discount = 0;
        if ($validated['promocode']) {
            $promocode                 = $this->validatePromocode($validated['promocode'], $subtotal, $validated['venue_id'], $validated['listing_id']);
            $discount                  = $promocode->applyDiscount($subtotal);
            $validated['promocode_id'] = $promocode->id;
            $promocode->incrementUsage(Auth::id());
        }

        $totalPrice = $subtotal - $discount;

        $validated['user_id']         = Auth::id();
        $validated['start_time']      = $startTime;
        $validated['end_time']        = $endTime;
        $validated['subtotal']        = $subtotal;
        $validated['discount_amount'] = $discount;
        $validated['total_price']     = $totalPrice;

        $booking = $this->bookingModel::create($validated);

        $booking->addOns()->sync($addOns);

        $booking->load(['user', 'venue', 'listing', 'promocode', 'addOns']);

        return sendSuccess('Booking created successfully', compact('booking'), 201);
    }

    public function update(Booking $booking, $request)
    {
        $validated = $this->validateRequest($request, true);

        // Recalculate if times or add-ons changed
        if (isset($validated['start_time']) || isset($validated['duration']) || isset($validated['add_ons']) || isset($validated['promocode'])) {
            $startTime = Carbon::parse($validated['start_time'] ?? $booking->start_time);
            $duration  = $validated['duration'] ?? $booking->duration;
            $endTime   = $startTime->copy()->addHours($duration);

            $subtotal = $this->calculateSubtotal($booking->listing, $startTime, $duration);

            $addOns         = $validated['add_ons'] ?? $booking->addOns->pluck('id')->toArray();
            $addOnCost      = 0;
            $addOnExtension = 0;
            foreach ($addOns as $addOnId) {
                $addOn = AddOn::findOrFail($addOnId);
                $addOnCost += $addOn->price;
                $addOnExtension += $addOn->hours_extension;
            }

            $endTime->addHours($addOnExtension);
            $duration += $addOnExtension;

            $subtotal += $addOnCost;

            $discount = 0;
            if (isset($validated['promocode'])) {
                $promocode                 = $this->validatePromocode($validated['promocode'], $subtotal, $booking->venue_id, $booking->listing_id);
                $discount                  = $promocode->applyDiscount($subtotal);
                $validated['promocode_id'] = $promocode->id;
                $promocode->incrementUsage(Auth::id());
            }

            $totalPrice = $subtotal - $discount;

            $validated['start_time']      = $startTime;
            $validated['end_time']        = $endTime;
            $validated['subtotal']        = $subtotal;
            $validated['discount_amount'] = $discount;
            $validated['total_price']     = $totalPrice;
        }

        $booking->update($validated);

        if (isset($validated['add_ons'])) {
            $booking->addOns()->sync($validated['add_ons']);
        }

        $booking->load(['user', 'venue', 'listing', 'promocode', 'addOns']);

        return sendSuccess('Booking updated successfully', compact('booking'));
    }

    public function delete($id)
    {
        $booking = $this->bookingModel->findOrFail($id);
        $booking->delete();
        return sendSuccess('Booking delete successfully');
    }

    public function cancel(Booking $booking)
    {
        if ($booking->status === 'cancelled') {
            throw ValidationException::withMessages(['status' => 'Booking already cancelled']);
        }

        $booking->update(['status' => 'cancelled']);

        return sendSuccess('Booking cancelled successfully');
    }

    protected function calculateSubtotal(Listing $listing, Carbon $startTime, int $duration)
    {
        $pricePerHour = $listing->base_price;
        $day          = strtolower($startTime->format('l'));
        $startHour    = $startTime->format('H:i');

        // Find applicable pricing group
        $pricingGroup = $listing->pricingGroups()->whereJsonContains('days', $day)
            ->where('start_time', '<=', $startHour)
            ->where('end_time', '>=', $startHour)
            ->first();

        if ($pricingGroup) {
            $pricePerHour = $pricingGroup->price_per_hour;
        }

        return $pricePerHour * $duration;
    }

    protected function validatePromocode($code, $amount, $venueId, $listingId)
    {
        $promocode = Promocode::where('code', $code)->firstOrFail();
        if (! $promocode->isValidForOrder($amount, Auth::id(), $request->payment_method, $venueId, $listingId)) {
            throw ValidationException::withMessages(['promocode' => 'Invalid or expired promocode']);
        }
        return $promocode;
    }

    protected function validateRequest($request, bool $isUpdate = false): array
    {
        $rules = [
            'venue_id'       => 'required|exists:venues,id',
            'listing_id'     => 'required|exists:listings,id',
            'start_time'     => 'required|date_format:Y-m-d H:i',
            'payment_method' => 'required|in:cash_on_arrival,online,deposit',
            'promocode'      => 'nullable|string|exists:promocodes,code',
            'duration'       => 'required',
            'add_ons'        => 'nullable|array',
            'add_ons.*'      => 'exists:add_ons,id',
            'notes'          => 'nullable|string',
        ];

        if ($isUpdate) {
            $rules = array_map(fn($rule) => str_replace('required', 'sometimes|required', $rule), $rules);
        }

        return $request->validate($rules);
    }
}
