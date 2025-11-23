<?php
namespace App\Services;

use App\Models\Booking;
use App\Models\Listing;
use App\Models\Promocode;
use App\Models\Venue;
use Carbon\Carbon;
use Exception;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;

class PromocodeService
{
    public function __construct(protected Promocode $promocodeModel, protected Booking $bookingModel)
    {}

    public function all($request)
    {
        $records_per_page = $request->records_per_page ?? 10;
        $search           = $request->search ?? null;

        $query = $this->promocodeModel::with(['venues', 'listings'])->latest()
            ->when($search, function ($query, $search) {
                $query->where(function ($q) use ($search) {
                    $q->where('code', 'LIKE', "%{$search}%")
                        ->orWhere('description', 'LIKE', "%{$search}%")
                        ->orWhere('discount_type', 'LIKE', "%{$search}%")
                        ->orWhere('valid_from', 'LIKE', "%{$search}%")
                        ->orWhere('expires_at', 'LIKE', "%{$search}%")
                        ->orWhere('min_order_amount', 'LIKE', "%{$search}%")
                        ->orWhere('max_discount_amount', 'LIKE', "%{$search}%")
                        ->orWhere('usage_limit', 'LIKE', "%{$search}%");
                });
            })
            ->when($request->status ?? null, fn($q, $status) => $q->where('status', $status))
            ->when($request->discount_type ?? null, fn($q, $type) => $q->where('discount_type', $type))
            ->when($request->payment_methods ?? null, fn($q, $type) => $q->where('payment_methods', $type));

        $promocodes = $query->paginate($records_per_page);

        return sendSuccess('Promocodes fetched successfully', compact('promocodes'));
    }

    public function create($request)
    {
        $validated = $this->validateRequest($request);

        try {
            DB::beginTransaction();

            $promocode = $this->promocodeModel::create($validated);

            if (! empty($request->venues)) {
                $promocode->venues()->sync($request->venues);
            }

            if (! empty($request->listings)) {
                $promocode->listings()->sync($request->listings);
            }

            DB::commit();

            $promocode->load(['venues', 'listings']);

            return sendSuccess('Promocode created successfully', compact('promocode'), 201);

        } catch (Exception $e) {
            DB::rollBack();
            return sendError('Failed to create promocode: ' . $e->getMessage());
        }
    }

    public function update($request, $id)
    {
        $promocode = $this->promocodeModel->findOrFail($id);
        $validated = $this->validateRequest($request, true, $promocode->id);

        try {
            DB::beginTransaction();

            $promocode->update($validated);

            if ($request->has('venues')) {
                $promocode->venues()->sync($request->venues ?? []);
            }

            if ($request->has('listings')) {
                $promocode->listings()->sync($request->listings ?? []);
            }

            DB::commit();

            $promocode->load(['venues', 'listings']);

            return sendSuccess('Promocode updated successfully', compact('promocode'));

        } catch (Exception $e) {
            DB::rollBack();
            return sendError('Failed to update promocode: ' . $e->getMessage());
        }
    }

    public function delete($id)
    {
        $promocode = $this->promocodeModel->findOrFail($id);
        $promocode->delete();
        return sendSuccess('Promocode deleted successfully');
    }

    public function validateForUse($code, $orderAmount, $userId = null, $paymentMethod = null, $venueId = null, $listingId = null)
    {
        $promocode = $this->promocodeModel::where('code', $code)->first();

        if (! $promocode || ! $promocode->isValidForOrder($orderAmount, $userId, $paymentMethod, $venueId, $listingId)) {
            throw ValidationException::withMessages([
                'code' => ['Invalid or expired promocode. Please check the code, validity dates, minimum order amount, or usage limits.'],
            ]);
        }

        return $promocode;
    }

    public function getApplicableVenues(Request $request)
    {
        $search = $request->search ?? '';
        $query  = Venue::query()
            ->when($search, fn($q) => $q->where('name', 'LIKE', "%{$search}%"))
            ->limit(10);

        return $query->get();
    }

    public function getApplicableListings(Request $request)
    {
        $search = $request->search ?? '';
        $query  = Listing::query()
            ->when($search, fn($q) => $q->where('name', 'LIKE', "%{$search}%"))
            ->limit(10);

        return $query->get();
    }

    protected function validateRequest($request, bool $isUpdate = false, $promocodeId = null): array
    {
        $rules = [
            'code' => $isUpdate
                ? "sometimes|required|string|max:20|unique:promocodes,code,{$promocodeId}"
                : 'required|string|max:20|unique:promocodes,code',
            'status'              => 'required|in:active,inactive',
            'description'         => 'required|string|max:500',
            'discount_type'       => 'required|in:fixed,percentage',
            'discount_value'      => [
                'required',
                'numeric',
                'min:0',
                function ($attribute, $value, $fail) use ($request) {
                    if ($request->discount_type === 'percentage' && $value > 100) {
                        $fail('Percentage discount cannot exceed 100%');
                    }
                    if ($request->discount_type === 'fixed' && $value < 0) {
                        $fail('Fixed discount cannot be negative');
                    }
                },
            ],
            'usage_limit'         => 'nullable|integer|min:1',
            'valid_from'          => 'nullable|date|after_or_equal:today',
            'expires_at'          => 'nullable|date|after_or_equal:valid_from',
            'min_order_amount'    => 'nullable|numeric|min:0',
            'max_discount_amount' => 'nullable|numeric|min:0',
            'per_user_limit'      => 'nullable|integer|min:1',
            'payment_methods'     => 'required|in:all,online_only',
            'venues'              => 'nullable|array',
            'venues.*'            => 'exists:venues,id',
            'listings'            => 'nullable|array',
            'listings.*'          => 'exists:listings,id',
        ];

        if ($isUpdate) {
            $rules['valid_from'] = 'sometimes|nullable|date';
            $rules['expires_at'] = 'sometimes|nullable|date|after_or_equal:valid_from';
        }

        return $request->validate($rules);
    }

    public function validatePromocode($request)
    {
        $validated = $request->validate([
            'code'           => 'required|string',
            'amount'         => 'required|numeric|min:0',
            'user_id'        => 'required|integer|exists:users,id',
            'venue_id'       => 'required|integer|exists:venues,id',
            'listing_id'     => 'sometimes|integer|exists:listings,id',
            'payment_method' => 'sometimes|string|in:cash_on_arrival,deposit_remaining_on_arrival,online_payment,paid_cash,paid_cheque,paid_wire_transfer',
        ]);

        try {

            $promocode = Promocode::where('code', $validated['code'])->first();

            if (! $promocode) {
                return $this->invalidResponse('Promocode not found');
            }

            $neverExpires = is_null($promocode->expires_at);
            $now          = Carbon::now();

            if (! $neverExpires) {
                if ($now->lt($promocode->valid_from) || $now->gt($promocode->expires_at)) {
                    return $this->invalidResponse('Promocode has expired');
                }
            } else {
                // For never-expiring, still check valid_from
                if ($now->lt($promocode->valid_from)) {
                    return $this->invalidResponse('Promocode is not yet active');
                }
            }

            // Step 3: Check usage limit (used_count vs usage_limit)
            if ($promocode->usage_limit && $promocode->used_count >= $promocode->usage_limit) {
                return $this->invalidResponse('Promocode usage limit reached');
            }

            // Step 4: Check per-user usage limit (from used_by_users JSON)
            if ($promocode->per_user_limit) {
                $userUsageCount = $promocode->used_by_users[$validated['user_id']] ?? 0;
                if ($userUsageCount >= $promocode->per_user_limit) {
                    $limitText = $promocode->per_user_limit > 1 ? 's' : '';
                    return $this->invalidResponse("You can only use this promocode {$promocode->per_user_limit} time{$limitText}");
                }
            }

            // Step 5: Check minimum order amount
            if ($promocode->min_order_amount && $validated['amount'] < $promocode->min_order_amount) {
                return $this->invalidResponse("Minimum amount required to use promocode is QAR {$promocode->min_order_amount}, your order is for QAR {$validated['amount']}");
            }

            $paymentMethod = $validated['payment_method'] ?? null; // Optional in request
            if ($paymentMethod) {                                  // Only check if provided
                $paymentMethodMapping = [
                    'cash_on_arrival'              => 'pending_payment',
                    'deposit_remaining_on_arrival' => 'pending_payment',
                    'online_payment'               => 'online_payment',
                    'paid_cash'                    => 'pending_payment',
                    'paid_cheque'                  => 'pending_payment',
                    'paid_wire_transfer'           => 'pending_payment',
                ];

                $mappedPaymentMethod = $paymentMethodMapping[$paymentMethod] ?? $paymentMethod;

                // Assume model's payment_methods is a comma-separated string (e.g., 'pending_payment,online_payment') or array-like
                // To match JS: Treat as array of allowed methods
                $validPaymentMethods = $this->parsePaymentMethods($promocode->payment_methods);

                if (! empty($validPaymentMethods) && ! in_array($mappedPaymentMethod, $validPaymentMethods)) {
                    if (in_array('online_payment', $validPaymentMethods) && ! in_array('pending_payment', $validPaymentMethods)) {
                        return $this->invalidResponse('This promocode is only valid for online payments');
                    } elseif (in_array('pending_payment', $validPaymentMethods) && ! in_array('online_payment', $validPaymentMethods)) {
                        return $this->invalidResponse('This promocode is only valid for cash/deposit payments');
                    } else {
                        return $this->invalidResponse('This promocode is not valid for the selected payment method');
                    }
                }
            }

            // Step 7: Check venue restriction (load relation and check contains)
            if ($validated['venue_id']) {
                $promocode->load('venues');
                $applicableVenues = $promocode->venues->pluck('id')->toArray();
                if (! empty($applicableVenues) && ! in_array($validated['venue_id'], $applicableVenues)) {
                    return $this->invalidResponse('Promocode is not valid for this venue');
                }
            }

            // Step 8: Check listing restriction (optional; load if provided)
            $listingId = $validated['listing_id'] ?? null;
            if ($listingId) {
                $promocode->load('listings');
                $applicableListings = $promocode->listings->pluck('id')->toArray();
                if (! empty($applicableListings) && ! in_array($listingId, $applicableListings)) {
                    return $this->invalidResponse('Promocode is not valid for this listing');
                }
            }

            // All checks passed: Return valid with promocode object (matches JS return { valid: true, promocode })
            return response()->json([
                'success' => true,
                'data'    => [
                    'valid'           => true,
                    'promocode'       => $promocode->toArray(),
                    'discount_amount' => $promocode->applyDiscount($validated['amount']),
                ],
            ], 200);

        } catch (Exception $e) {
            Log::error("Promocode validation failed: " . $e->getMessage(), ['request' => $request->all()]);
            return $this->invalidResponse('Something went wrong during validation');
        }
    }

    private function invalidResponse($error)
    {
        return response()->json([
            'success' => false,
            'data'    => [
                'valid' => false,
                'error' => $error,
            ],
        ], 422);
    }

    private function parsePaymentMethods($paymentMethods)
    {
        if ($paymentMethods === 'all') {
            return ['pending_payment', 'online_payment'];
        }
        return explode(',', $paymentMethods);
    }

    public function applyPromocode($request, $promocode)
    {
        $validated = $request->validate([
            'booking_id'        => 'required|exists:bookings,id',
            'discount_amount'   => 'required|numeric|min:0',
            'total_price'       => 'required|numeric|min:0',
            'promocode'         => 'sometimes|string',
            'applied_promocode' => 'sometimes|array',
            'payment_breakdown' => 'sometimes|array',
        ]);

        try {

            DB::beginTransaction();

            $booking = $this->bookingModel::findOrFail($validated['booking_id']);

            if ($booking->promocode_id) {
                return sendError('A promocode has already been applied to this booking');
            }

            $booking->update([
                'promocode_id'    => $promocode->id,
                'total_price'     => $validated['total_price'],
                'discount_amount' => $validated['discount_amount'],
            ]);

            $isDeposit = $booking->payment_method === 'deposit_remaining_on_arrival';
            if ($isDeposit && ($booking->payment_breakdown || isset($validated['payment_breakdown']))) {
                $breakdown                     = $validated['payment_breakdown'] ?? $booking->payment_breakdown;
                $currentRemaining              = $breakdown['remaining_amount'] ?? 0;
                $newRemaining                  = max(0, $currentRemaining - $validated['discount_amount']);
                $breakdown['remaining_amount'] = $newRemaining;

                $booking->payment_breakdown = $breakdown;
                $booking->save();
            }

            $promocode->incrementUsage($booking->user_id);

            $code = $request->input('promocode', $promocode->code);

            $booking->history()->create([
                'user_id'     => auth()->id(),
                'action'      => 'promocode_applied',
                'description' => "Applied promocode {$code} - Discount: QAR {$validated['discount_amount']}",
            ]);

            DB::commit();

            $booking->load([
                'user',
                'addOns',
                'history',
                'payments',
                'invoices',
                'promocode',
                'venue:id,name',
                'listing:id,name',
                'resourcesAllocated',
                'history.user:id,name',
            ]);

            return sendSuccess('Promocode applied successfully', compact('booking'));
        } catch (Exception $e) {
            DB::rollBack();
            Log::error("Promocode apply failed for booking #{$validated['booking_id']}: " . $e->getMessage(), [
                'request'    => $request->all(),
                'booking_id' => $validated['booking_id'] ?? null,
                'exception'  => $e,
            ]);

            return sendError('Failed to apply promocode: ' . $e->getMessage());
        }
    }

    public function removePromocodeFromBooking($bookingId)
    {

        $booking = $this->bookingModel::with('promocode')->whereHas('promocode')->findOrFail($bookingId);

        try {
            DB::beginTransaction();

            $oldPromocode = $booking->promocode;

            if ($oldPromocode) {

                $originalDiscount = $booking->discount_amount ?? 0;

                $oldPromocode->decrementUsage($booking->user_id);

                $booking->update([
                    'discount_amount' => 0,
                    'promocode_id'    => null,
                    'total_price'     => $booking->subtotal ?? $booking->total_price,
                ]);

                if ($booking->payment_method === 'deposit_remaining_on_arrival' && $booking->payment_breakdown) {
                    $updatedBreakdown                     = $booking->payment_breakdown;
                    $currentRemaining                     = $updatedBreakdown['remaining_amount'] ?? 0;
                    $updatedBreakdown['remaining_amount'] = $currentRemaining + $originalDiscount;
                    $booking->payment_breakdown           = $updatedBreakdown;
                    $booking->save();
                }

                $booking->history()->create([
                    'user_id'     => auth()->id(),
                    'action'      => 'promocode_removed',
                    'description' => "Promocode {$oldPromocode->code} removed. Discount (QAR {$originalDiscount}) restored.",
                ]);

                Log::info("Promocode removed from booking #{$booking->booking_id} by admin " . auth()->id());
            }

            $booking->load([
                'user',
                'addOns',
                'history',
                'payments',
                'invoices',
                'promocode',
                'venue:id,name',
                'listing:id,name',
                'resourcesAllocated',
                'history.user:id,name',
            ]);

            DB::commit();

            return sendSuccess('Booking updated successfully', compact('booking'));

        } catch (Exception $e) {
            DB::rollBack();
            Log::error("Failed to update booking #{$bookingId}: " . $e->getMessage(), [
                'booking_id' => $booking->id ?? $bookingId,
                'exception'  => $e,
            ]);

            return sendError('Failed to update booking: ' . $e->getMessage());
        }
    }
}
