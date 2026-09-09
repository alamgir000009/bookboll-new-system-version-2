<?php

namespace App\Services;

use App\Models\Booking;
use App\Models\Business;
use App\Models\Listing;
use App\Models\Promocode;
use App\Models\PromocodeRedemption;
use App\Models\User;
use App\Models\Venue;
use App\Support\PremiumPlan;
use BookingPaymentMethod;
use BookingPaymentStatus;
use Carbon\Carbon;
use Exception;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\ValidationException;
use PaymentStatus;
use PaymentType;

class PromocodeService
{
    public function __construct(protected Promocode $promocodeModel, protected Booking $bookingModel) {}

    public function all($request)
    {
        $recordsPerPage = (int) ($request->records_per_page ?? $request->query('records_per_page', 25));
        if ($recordsPerPage < 1) {
            $recordsPerPage = 25;
        }
        if ($recordsPerPage > 250) {
            $recordsPerPage = 250;
        }

        $search    = $request->search ?? $request->query('search');
        $recipient = $request->recipient ?? $request->query('recipient');
        $status    = $request->status ?? $request->query('status');

        $query = $this->promocodeModel::with(['venues', 'listings', 'users'])
            ->nonReminderPromocodes()
            ->where('is_gift_card_promocode', false)
            ->latest();

        if ($search) {
            $query->where(function ($q) use ($search) {
                $q->where('code', 'LIKE', "%{$search}%")
                    ->orWhere('description', 'LIKE', "%{$search}%");
            });
        }

        if ($recipient && in_array($recipient, ['bookboll', 'provider'], true)) {
            $query->where('recipient', $recipient);
        }

        if ($status) {
            $query->where('status', $status);
        }

        $this->applyActorScope($query, $request->user());
        $this->applyGeoFilter($query, $request);

        $paginator = $query->paginate($recordsPerPage);

        // apps/admin sends dashboard=1. Old ADMIN expects a Laravel paginator on promocodes.
        if ($request->boolean('dashboard') || (string) $request->query('dashboard') === '1') {
            $codes = collect($paginator->items())->map(fn (Promocode $p) => $this->mapDashboard($p))->values();

            return sendSuccess('Promocodes fetched successfully', [
                'promocodes' => $codes,
                'pagination' => [
                    'current_page'     => $paginator->currentPage(),
                    'last_page'        => $paginator->lastPage(),
                    'records_per_page' => $paginator->perPage(),
                    'total'            => $paginator->total(),
                    'from'             => $paginator->firstItem(),
                    'to'               => $paginator->lastItem(),
                ],
            ]);
        }

        return sendSuccess('Promocodes fetched successfully', [
            'promocodes' => $paginator,
        ]);
    }

    public function options(Request $request)
    {
        $venueCols = $this->existingColumns('venues', ['id', 'name', 'status', 'city_id', 'country_id', 'created_by']);
        $venues = Venue::with('users')
            ->orderBy('name')
            ->get($venueCols);

        $locations = $venues->map(function (Venue $v) {
            return [
                'id'            => (string) $v->id,
                'name'          => $v->name,
                'status'        => $v->status,
                'city_id'       => $v->city_id ? (string) $v->city_id : null,
                'owner_user_id' => ($v->users->first()?->id ?? $v->created_by)
                    ? (string) ($v->users->first()?->id ?? $v->created_by)
                    : null,
            ];
        });

        $ownerByVenue = $locations->pluck('owner_user_id', 'id');

        $listingCols = $this->existingColumns('listings', ['id', 'name', 'venue_id', 'created_by']);
        $listings = Listing::orderBy('name')
            ->get($listingCols)
            ->map(function (Listing $l) use ($ownerByVenue) {
                $locationId = $l->venue_id ? (string) $l->venue_id : null;

                return [
                    'id'            => (string) $l->id,
                    'name'          => $l->name,
                    'location_id'   => $locationId,
                    'owner_user_id' => $ownerByVenue[$locationId] ?? ($l->created_by ? (string) $l->created_by : null),
                ];
            });

        $customers = User::role('customer')
            ->orderBy('name')
            ->get(['id', 'name', 'email'])
            ->map(fn (User $u) => [
                'id'        => (string) $u->id,
                'full_name' => $u->name,
                'email'     => $u->email,
            ]);

        $cityIds = $venues->pluck('city_id')->filter()->unique()->values();
        $cities = $cityIds->isEmpty()
            ? collect()
            : \Nnjeim\World\Models\City::whereIn('id', $cityIds)
            ->orderBy('name')
            ->get(['id', 'name', 'country_id']);

        $countryIds = $venues->pluck('country_id')
            ->merge($cities->pluck('country_id'))
            ->filter()
            ->unique()
            ->values();

        $countries = $countryIds->isEmpty()
            ? \Nnjeim\World\Models\Country::orderBy('name')->get(['id', 'name'])
            : \Nnjeim\World\Models\Country::whereIn('id', $countryIds)
            ->orderBy('name')
            ->get(['id', 'name']);

        $businesses = Business::with('owner:id,name,email')
            ->orderBy('name')
            ->get()
            ->map(fn(Business $b) => [
                'id'            => $b->owner_id,
                'full_name'     => $b->owner?->name ?: $b->name,
                'username'      => $b->username,
                'email'         => $b->owner?->email,
                'country_id'    => $b->country_id,
                'role'          => 'provider_admin',
            ]);

        return sendSuccess('Promocode options fetched', [
            'locations'  => $locations,
            'listings'   => $listings,
            'programs'   => [],
            'customers'  => $customers,
            'countries'  => $countries,
            'cities'     => $cities,
            'businesses' => $businesses,
        ]);
    }

    public function show($id)
    {
        $promocode = $this->promocodeModel::with(['venues', 'listings', 'users'])->find($id);
        if (! $promocode) {
            return sendError('Promocode not found', [], 404);
        }

        return sendSuccess('Promocode fetched', [
            'promocode' => $this->mapDashboard($promocode),
        ]);
    }

    public function redemptions($id)
    {
        $rows = PromocodeRedemption::where('promocode_id', $id)
            ->orderByDesc('redeemed_at')
            ->orderByDesc('id')
            ->get();

        return sendSuccess('Redemptions fetched', [
            'redemptions' => $rows,
        ]);
    }

    public function storeRedemption(Request $request, $id)
    {
        $promocode = $this->promocodeModel->find($id);
        $data = $request->validate([
            'customer_email'  => 'nullable|string|max:255',
            'customer_name'   => 'nullable|string|max:255',
            'source_type'     => 'nullable|string|max:40',
            'source_id'       => 'nullable|string|max:64',
            'order_amount'    => 'nullable|numeric|min:0',
            'discount_amount' => 'nullable|numeric|min:0',
            'currency'        => 'nullable|string|max:10',
            'recipient'       => 'nullable|string|max:20',
            'owner_user_id'   => 'nullable|integer',
            'country_id'      => 'nullable|integer',
        ]);

        $row = PromocodeRedemption::create([
            'promocode_id'    => $promocode?->id,
            'code'            => $promocode?->code ?? '',
            'customer_email'  => strtolower(trim((string) ($data['customer_email'] ?? ''))),
            'customer_name'   => $data['customer_name'] ?? '',
            'source_type'     => $data['source_type'] ?? '',
            'source_id'       => $data['source_id'] ?? null,
            'order_amount'    => $data['order_amount'] ?? 0,
            'discount_amount' => $data['discount_amount'] ?? 0,
            'currency'        => $data['currency'] ?? 'AED',
            'recipient'       => $data['recipient'] ?? ($promocode?->recipient ?? ''),
            'owner_user_id'   => $data['owner_user_id'] ?? $promocode?->owner_user_id,
            'country_id'      => $data['country_id'] ?? null,
            'redeemed_at'     => now(),
        ]);

        if ($promocode) {
            $promocode->increment('used_count');
        }

        return sendSuccess('Redemption recorded', ['redemption' => $row], 201);
    }

    public function customerPromocodes($request)
    {
        $user             = auth()->user();
        $search           = $request->search ?? null;
        $records_per_page = $request->records_per_page ?? 10;

        $query = $this->promocodeModel::with(['venues', 'listings'])
            ->active()
            ->where('is_gift_card_promocode', false)
            ->where(function ($q) use ($user) {
                $q->where(function ($subQ) {
                    $subQ->whereDoesntHave('users')
                        ->where('is_reminder_promocode', false);
                })
                    ->orWhereHas('users', function ($subQ) use ($user) {
                        $subQ->where('user_id', $user->id);
                    });
            })
            ->latest()
            ->when($search, function ($query, $search) {
                $query->where(function ($q) use ($search) {
                    $q->where('code', 'LIKE', "%{$search}%")
                        ->orWhere('description', 'LIKE', "%{$search}%");
                });
            });

        $promocodes = $query->paginate($records_per_page);

        return sendSuccess('Promocodes fetched successfully', compact('promocodes'));
    }

    public function create($request)
    {
        // Promo codes are a Premium feature for providers (Promocodes.vue gates the
        // "New Promocode" button); platform roles are never gated.
        if (! PremiumPlan::entitled($request->user())) {
            return PremiumPlan::denied('promocodes');
        }

        // (There was a call to a normalizeDashboardInput() that does not exist, which
        // made every promo-code creation fatal. validateRequest() already maps the
        // dashboard's field names and stamps recipient / creator_role / owner_user_id —
        // which is why update() has always worked without it.)
        $validated = $this->validateRequest($request);

        try {
            DB::beginTransaction();

            $promocode = $this->promocodeModel::create($validated);

            $venueIds = $this->scopeIds($request->location_ids ?? $request->venues ?? []);
            $listingIds = $this->scopeIds($request->listing_ids ?? $request->listings ?? []);
            $promocode->venues()->sync($venueIds);
            $promocode->listings()->sync($listingIds);

            if ($request->has('users')) {
                $users = $request->users ?? [];
                if (empty($users)) {
                    $promocode->users()->detach();
                } else {
                    $syncData = [];
                    foreach ($users as $userId) {
                        $syncData[$userId] = ['usage_count' => 0];
                    }
                    $promocode->users()->sync($syncData);
                }
            }

            DB::commit();

            $promocode->load(['venues', 'listings', 'users']);

            return sendSuccess('Promocode created successfully', [
                'promocode' => $this->mapDashboard($promocode),
            ], 201);
        } catch (Exception $e) {
            DB::rollBack();
            Log::error('Failed to create promocode: ' . $e->getMessage(), ['Exception' => $e]);
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

            if ($request->has('venues') || $request->has('location_ids')) {
                $promocode->venues()->sync($this->scopeIds($request->location_ids ?? $request->venues ?? []));
            }

            if ($request->has('listings') || $request->has('listing_ids')) {
                $promocode->listings()->sync($this->scopeIds($request->listing_ids ?? $request->listings ?? []));
            }

            if ($request->has('users')) {
                $users = $request->users ?? [];
                if (empty($users)) {
                    $promocode->users()->detach();
                } else {
                    $syncData      = [];
                    foreach ($users as $userId) {
                        $existingPivot     = $promocode->users()->where('user_id', $userId)->first();
                        $syncData[$userId] = [
                            'usage_count' => $existingPivot?->pivot->usage_count ?? 0,
                        ];
                    }
                    $promocode->users()->sync($syncData);
                }
            }

            DB::commit();

            $promocode->load(['venues', 'listings', 'users']);

            return sendSuccess('Promocode updated successfully', [
                'promocode' => $this->mapDashboard($promocode),
            ]);
        } catch (Exception $e) {
            DB::rollBack();
            Log::error('Failed to update promocode: ' . $e->getMessage(), ['promocode_id' => $id, 'Exception' => $e]);
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
        $promocode = $this->promocodeModel::with('users')->where('code', $code)->first();

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
        return Venue::when($search, fn($q) => $q->where('name', 'LIKE', "%{$search}%"))->limit(10)->get();
    }

    public function getApplicableListings(Request $request)
    {
        $search = $request->search ?? '';
        return Listing::when($search, fn($q) => $q->where('name', 'LIKE', "%{$search}%"))->limit(10)->get();
    }

    protected function validateRequest($request, bool $isUpdate = false, $promocodeId = null): array
    {
        if ($request->filled('code')) {
            $request->merge(['code' => strtoupper(trim((string) $request->code))]);
        }

        $rules = [
            'code' => $isUpdate
                ? "sometimes|required|string|max:50|unique:promocodes,code,{$promocodeId}"
                : 'required|string|max:50|unique:promocodes,code',
            'status'              => 'required|in:active,inactive',
            'description'         => 'nullable|string|max:500',
            'discount_type'       => 'required|in:fixed,percentage',
            'discount_value'      => [
                'required',
                'numeric',
                'min:0',
                function ($attribute, $value, $fail) use ($request) {
                    if ($request->discount_type === 'percentage' && $value > 100) {
                        $fail('Percentage discount cannot exceed 100%');
                    }
                },
            ],
            'usage_limit'         => 'nullable|integer|min:0',
            'valid_from'          => 'nullable|date',
            'expires_at'          => 'nullable|date',
            'valid_to'            => 'nullable|date',
            'min_order_amount'    => 'nullable|numeric|min:0',
            'min_order'           => 'nullable|numeric|min:0',
            'max_discount_amount' => 'nullable|numeric|min:0',
            'per_user_limit'      => 'nullable|integer|min:0',
            'per_customer_limit'  => 'nullable|integer|min:0',
            'payment_methods'     => 'nullable|in:all,online_only',
            'venues'              => 'nullable|array',
            'venues.*'            => 'integer',
            'listings'            => 'nullable|array',
            'listings.*'          => 'integer',
            'users'               => 'nullable|array',
            'users.*'             => 'integer',
            'recipient'           => 'nullable|in:bookboll,provider',
            'creator_role'        => 'nullable|string|max:40',
            'owner_user_id'       => 'nullable|integer',
            'applicable_to'       => 'nullable|in:all,facility,program,provider_plan',
            'location_id'         => 'nullable|integer',
            'owner_user_ids'      => 'nullable|array',
            'owner_user_ids.*'    => 'integer',
            'location_ids'        => 'nullable|array',
            'location_ids.*'      => 'integer',
            'listing_ids'         => 'nullable|array',
            'listing_ids.*'       => 'integer',
            'program_ids'         => 'nullable|array',
            'audience'            => 'nullable|in:all,new,specific',
            'target_emails'       => 'nullable|array',
            'target_emails.*'     => 'nullable|string',
        ];

        $data = $request->validate($rules);

        $data['description'] = $data['description'] ?? '';
        $data['payment_methods'] = $data['payment_methods'] ?? 'all';

        if (array_key_exists('valid_to', $data) && ! array_key_exists('expires_at', $data)) {
            $data['expires_at'] = $data['valid_to'] ?: null;
        }
        unset($data['valid_to']);

        if (array_key_exists('min_order', $data)) {
            $data['min_order_amount'] = $data['min_order'];
            unset($data['min_order']);
        }

        if (isset($data['usage_limit']) && (int) $data['usage_limit'] === 0) {
            $data['usage_limit'] = null;
        }

        $perCustomer = (int) ($data['per_customer_limit'] ?? 0);
        $data['per_customer_limit'] = $perCustomer;
        if (! isset($data['per_user_limit'])) {
            $data['per_user_limit'] = $perCustomer > 0 ? $perCustomer : null;
        }

        $locationIds = $this->scopeIds($data['location_ids'] ?? $request->location_ids ?? []);
        $listingIds  = $this->scopeIds($data['listing_ids'] ?? $request->listing_ids ?? []);
        $ownerIds    = $this->scopeIds($data['owner_user_ids'] ?? $request->owner_user_ids ?? []);

        $data['location_ids']  = $locationIds;
        $data['listing_ids']   = $listingIds;
        $data['owner_user_ids'] = $ownerIds;
        $data['program_ids']   = $this->scopeIds($data['program_ids'] ?? []);
        $data['target_emails'] = array_values(array_unique(array_filter(array_map(
            fn($e) => strtolower(trim((string) $e)),
            $data['target_emails'] ?? []
        ))));
        $data['audience']      = $data['audience'] ?? 'all';
        $data['recipient']     = $data['recipient'] ?? 'bookboll';
        $data['creator_role']  = $data['creator_role'] ?? 'super_admin';
        $data['applicable_to'] = $data['applicable_to'] ?? 'all';
        $data['location_id']   = $data['location_id'] ?? ($locationIds[0] ?? null);
        $data['owner_user_id'] = $data['owner_user_id'] ?? ($ownerIds[0] ?? null);

        unset($data['venues'], $data['listings'], $data['users']);

        return $data;
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

            $promocode = Promocode::with('users')->where('code', $validated['code'])->first();

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

                if ($now->lt($promocode->valid_from)) {
                    return $this->invalidResponse('Promocode is not yet active');
                }
            }

            if ($promocode->usage_limit && $promocode->used_count >= $promocode->usage_limit) {
                return $this->invalidResponse('Promocode usage limit reached');
            }

            if ($promocode->per_user_limit) {
                $userUsageCount = $promocode->used_by_users[$validated['user_id']] ?? 0;
                if ($userUsageCount >= $promocode->per_user_limit) {
                    $limitText = $promocode->per_user_limit > 1 ? 's' : '';
                    return $this->invalidResponse("You can only use this promocode {$promocode->per_user_limit} time{$limitText}");
                }
            }

            if ($promocode->min_order_amount && $validated['amount'] < $promocode->min_order_amount) {
                return $this->invalidResponse("Minimum amount required to use promocode is QAR {$promocode->min_order_amount}, your order is for QAR {$validated['amount']}");
            }

            $paymentMethod = $validated['payment_method'] ?? null;
            if ($paymentMethod) {
                $paymentMethodMapping = [
                    'cash_on_arrival'              => 'pending_payment',
                    'deposit_remaining_on_arrival' => 'pending_payment',
                    'online_payment'               => 'online_payment',
                    'paid_cash'                    => 'pending_payment',
                    'paid_cheque'                  => 'pending_payment',
                    'paid_wire_transfer'           => 'pending_payment',
                ];

                $mappedPaymentMethod = $paymentMethodMapping[$paymentMethod] ?? $paymentMethod;

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

            if ($validated['venue_id']) {
                $promocode->load('venues');
                $applicableVenues = $promocode->venues->pluck('id')->toArray();
                if (! empty($applicableVenues) && ! in_array($validated['venue_id'], $applicableVenues)) {
                    return $this->invalidResponse('Promocode is not valid for this venue');
                }
            }

            $listingId = $validated['listing_id'] ?? null;
            if ($listingId) {
                $promocode->load('listings');
                $applicableListings = $promocode->listings->pluck('id')->toArray();
                if (! empty($applicableListings) && ! in_array($listingId, $applicableListings)) {
                    return $this->invalidResponse('Promocode is not valid for this listing');
                }
            }

            $promocode->load('users');
            $assignedUserIds = $promocode->users->pluck('id')->toArray();
            if (! empty($assignedUserIds) && ! in_array($validated['user_id'], $assignedUserIds)) {
                return $this->invalidResponse('This promocode is not available for your account');
            }

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

            $booking = $this->bookingModel::findOrFail($validated['booking_id']);

            if ($booking->payment_status === BookingPaymentStatus::FULLY_PAID) {
                return sendError('Cannot apply promocode to a fully paid booking');
            }

            DB::beginTransaction();

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

                $remainingPayment = $booking->payments()
                    ->where('type', PaymentType::REMAINING)
                    ->where('status', PaymentStatus::PENDING)
                    ->first();

                if ($remainingPayment) {
                    $remainingPayment->update(['amount' => $newRemaining]);
                }
            } else {
                $pendingFull = $booking->payments()
                    ->where('type', PaymentType::FULL)
                    ->where('status', PaymentStatus::PENDING)
                    ->first();

                if ($pendingFull) {
                    $pendingFull->update(['amount' => $booking->total_price]);
                }
            }

            $promocode->incrementUsage($booking->user_id);

            $code = $request->input('promocode', $promocode->code);

            $booking->history()->create([
                'user_id'     => auth()->id(),
                'action'      => 'promocode_applied',
                'description' => "Applied promocode {$code} - Discount: QAR {$validated['discount_amount']}",
            ]);

            DB::commit();

            $this->regenerateBookingInvoice($booking);

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

        $booking = $this->bookingModel::with(['promocode', 'payments', 'user'])->whereHas('promocode')->findOrFail($bookingId);

        if ($booking->payment_status === BookingPaymentStatus::FULLY_PAID) {
            return sendError('Cannot remove promocode from a fully paid booking');
        }

        try {
            $oldPromocode = $booking->promocode;
            DB::beginTransaction();

            if ($oldPromocode) {

                $originalDiscount = $booking->discount_amount ?? 0;

                $oldPromocode->decrementUsage($booking->user_id);

                $booking->update([
                    'discount_amount' => 0,
                    'promocode_id'    => null,
                    'total_price'     => $booking->total_price ? $booking->total_price - $booking->discount_amount : $booking->subtotal,
                ]);

                if ($booking->payment_method === BookingPaymentMethod::DEPOSIT_REMAINING_ON_ARRIVAL && $booking->payment_breakdown) {
                    $updatedBreakdown                     = $booking->payment_breakdown;
                    $currentRemaining                     = $updatedBreakdown['remaining_amount'] ?? 0;
                    $updatedBreakdown['remaining_amount'] = $currentRemaining + $originalDiscount;
                    $booking->payment_breakdown           = $updatedBreakdown;
                    $booking->save();

                    $remainingPayment = $booking->payments()
                        ->where('type', PaymentType::REMAINING)
                        ->where('status', PaymentStatus::PENDING)
                        ->first();

                    if ($remainingPayment) {
                        $remainingPayment->update(['amount' => $updatedBreakdown['remaining_amount']]);
                    }
                } else {
                    $pendingFull = $booking->payments()
                        ->where('type', PaymentType::FULL)
                        ->where('status', PaymentStatus::PENDING)
                        ->first();

                    if ($pendingFull) {
                        $pendingFull->update(['amount' => $booking->total_price]);
                    }
                }

                $booking->history()->create([
                    'user_id'     => auth()->id(),
                    'action'      => 'promocode_removed',
                    'description' => "Promocode {$oldPromocode->code} removed. Discount (QAR {$originalDiscount}) restored.",
                ]);

                Log::info("Promocode removed from booking #{$booking->booking_id} by admin " . auth()->id());
            }

            DB::commit();

            $this->regenerateBookingInvoice($booking);

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

    protected function regenerateBookingInvoice(Booking $booking): void
    {
        try {
            $booking->loadMissing('user');
            $user = $booking->user;

            if (! $user) {
                return;
            }

            $existingInvoices = $user->invoices()->where('booking_id', $booking->id)->get();
            foreach ($existingInvoices as $invoice) {
                $invoice->status = 're_generated';
                $invoice->save();
            }

            $invoiceNumber = generateInvoiceId();
            $invoicePath   = generateAndStoreInvoicePdf($booking, $invoiceNumber);

            $user->invoices()->create([
                'user_id'        => $user->id,
                'status'         => 'generated',
                'path'           => $invoicePath,
                'booking_id'     => $booking->id,
                'invoice_number' => $invoiceNumber,
            ]);
        } catch (Exception $e) {
            Log::error("Failed to regenerate invoice for booking #{$booking->id}: " . $e->getMessage(), [
                'booking_id' => $booking->id,
                'exception'  => $e,
            ]);
        }
    }

    protected function mapDashboard(Promocode $p): array
    {
        $locationIds = $p->relationLoaded('venues') && $p->venues->isNotEmpty()
            ? $p->venues->pluck('id')->map(fn($id) => (int) $id)->values()->all()
            : $this->scopeIds($p->location_ids ?? []);
        $listingIds = $p->relationLoaded('listings') && $p->listings->isNotEmpty()
            ? $p->listings->pluck('id')->map(fn($id) => (int) $id)->values()->all()
            : $this->scopeIds($p->listing_ids ?? []);
        $ownerIds = $this->scopeIds($p->owner_user_ids ?? []);

        return [
            'id'                 => $p->id,
            'code'               => $p->code,
            'description'        => $p->description,
            'discount_type'      => $p->discount_type,
            'discount_value'     => (float) $p->discount_value,
            'min_order'          => (float) ($p->min_order_amount ?? 0),
            'usage_limit'        => (int) ($p->usage_limit ?? 0),
            'usage_count'        => (int) ($p->used_count ?? 0),
            'status'             => $p->status,
            'valid_from'         => optional($p->valid_from)->toDateString(),
            'valid_to'           => optional($p->expires_at)->toDateString(),
            'recipient'          => $p->recipient ?: 'bookboll',
            'creator_role'       => $p->creator_role ?: 'super_admin',
            'owner_user_id'      => $p->owner_user_id,
            'applicable_to'      => $p->applicable_to ?: 'all',
            'owner_user_ids'     => $ownerIds,
            'location_ids'       => $locationIds,
            'listing_ids'        => $listingIds,
            'program_ids'        => $this->scopeIds($p->program_ids ?? []),
            'location_id'        => $p->location_id ?: ($locationIds[0] ?? null),
            'audience'           => $p->audience ?: 'all',
            'target_emails'      => $p->target_emails ?? [],
            'per_customer_limit' => (int) ($p->per_customer_limit ?? 0),
            'created_at'         => optional($p->created_at)?->toIso8601String(),
        ];
    }

    protected function scopeIds($value): array
    {
        if (! is_array($value)) {
            return $value ? [(int) $value] : [];
        }

        return array_values(array_unique(array_filter(array_map('intval', $value))));
    }

    protected function applyActorScope($query, $actor): void
    {
        if (! $actor) {
            return;
        }

        if ($actor->hasRole('super_admin')) {
            return;
        }

        if ($actor->hasRole('provider_admin') || $actor->owner_user_id) {
            $ownerId = $actor->hasRole('provider_admin') ? $actor->id : (int) $actor->owner_user_id;
            $query->where(function ($q) use ($ownerId) {
                $q->where('owner_user_id', $ownerId)
                    ->orWhereJsonContains('owner_user_ids', $ownerId)
                    ->orWhereJsonContains('owner_user_ids', (string) $ownerId);
            });

            return;
        }

        if ($actor->hasRole('country_admin')) {
            $allowed = collect($actor->assigned_country_ids ?? [])->filter()->map(fn($id) => (int) $id)->all();
            if ($actor->country_id) {
                $allowed[] = (int) $actor->country_id;
            }
            $allowed = array_values(array_unique($allowed));
            if (! $allowed) {
                $query->whereRaw('1 = 0');

                return;
            }

            $ownerIds = Business::whereIn('country_id', $allowed)->pluck('owner_id')->filter()->all();
            $venueIds = Venue::whereIn('country_id', $allowed)->pluck('id')->all();

            $query->where(function ($q) use ($ownerIds, $venueIds) {
                $q->where(function ($inner) {
                    $inner->whereNull('owner_user_id')
                        ->where(function ($empty) {
                            $empty->whereNull('owner_user_ids')
                                ->orWhereJsonLength('owner_user_ids', 0);
                        })
                        ->whereNull('location_id')
                        ->where(function ($emptyLoc) {
                            $emptyLoc->whereNull('location_ids')
                                ->orWhereJsonLength('location_ids', 0);
                        });
                });
                if ($ownerIds) {
                    $q->orWhereIn('owner_user_id', $ownerIds);
                    foreach ($ownerIds as $oid) {
                        $q->orWhereJsonContains('owner_user_ids', (int) $oid);
                    }
                }
                if ($venueIds) {
                    $q->orWhereIn('location_id', $venueIds)
                        ->orWhereHas('venues', fn($vq) => $vq->whereIn('venues.id', $venueIds));
                }
            });
        }
    }

    protected function applyGeoFilter($query, Request $request): void
    {
        $countryIds = $this->scopeIds($request->input('country_ids', $request->query('country_ids')));
        $cityIds    = $this->scopeIds($request->input('city_ids', $request->query('city_ids')));
        if (! $countryIds && ! $cityIds) {
            return;
        }

        $venueQuery = Venue::query();
        if ($countryIds) {
            $venueQuery->whereIn('country_id', $countryIds);
        }
        if ($cityIds) {
            $venueQuery->whereIn('city_id', $cityIds);
        }
        $venueIds = $venueQuery->pluck('id')->all();

        $query->where(function ($q) use ($venueIds) {
            $q->where(function ($global) {
                $global->whereNull('location_id')
                    ->where(function ($empty) {
                        $empty->whereNull('location_ids')
                            ->orWhereJsonLength('location_ids', 0);
                    })
                    ->whereDoesntHave('venues');
            });
            if ($venueIds) {
                $q->orWhereIn('location_id', $venueIds)
                    ->orWhereHas('venues', fn($vq) => $vq->whereIn('venues.id', $venueIds));
            }
        });
    }

    protected function existingColumns(string $table, array $columns): array
    {
        return array_values(array_filter($columns, fn ($col) => Schema::hasColumn($table, $col)));
    }
}
