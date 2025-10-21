<?php
namespace App\Services;

use App\Models\Listing;
use App\Models\Promocode;
use App\Models\Venue;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class PromocodeService
{
    public function __construct(protected Promocode $promocodeModel)
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

        } catch (\Exception $e) {
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

        } catch (\Exception $e) {
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

}
