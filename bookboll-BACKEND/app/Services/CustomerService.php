<?php
namespace App\Services;

use App\Mail\UserWelcomeEmail;
use App\Models\Booking;
use App\Models\CustomerLoyalty;
use App\Models\Listing;
use App\Models\LoyaltyPoint;
use App\Models\Preference;
use App\Models\User;
use App\Models\Venue;
use BookingPaymentStatus;
use BookingStatus;
use Nnjeim\World\Models\City;
use Nnjeim\World\Models\Country;
use Exception;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;
use PaymentStatus;

class CustomerService
{
    public function __construct(protected User $customerModel)
    {}

    public function list($request)
    {
        $records_per_page = $request->records_per_page ?? 10;
        $search           = $request->search ?? null;
        $status           = $request->status ?? null;
        $tags             = $request->tag ?? null;

        $customers = $this->customerModel::whereHas('roles', function ($query) {
            $query->where('Name', 'customer');
        })
            ->withCount(['bookings'])
            ->with(['tags'])
            ->when($search, function ($query, $search) {
                $query->where(function ($q) use ($search) {
                    $q->where('name', 'LIKE', "%{$search}%")
                        ->orWhere('email', 'LIKE', "%{$search}%")
                        ->orWhere('phone', 'LIKE', "%{$search}%");
                });
            })
            ->addSelect([
                'loyalty_balance'  => function ($q) {
                    $q->selectRaw('
                COALESCE(SUM(CASE WHEN type = "credit" THEN points ELSE 0 END), 0) -
                COALESCE(SUM(CASE WHEN type = "debit" THEN points ELSE 0 END), 0)
            ')
                        ->from('loyalty_points')
                        ->whereColumn('loyalty_points.user_id', 'users.id');
                },
                'total_due_amount' => function ($q) {
                    $q->selectRaw('COALESCE(SUM(amount), 0)')
                        ->from('payments')
                        ->whereColumn('payments.user_id', 'users.id')
                        ->where('payments.status', '!=', PaymentStatus::PAID);
                },

                'total_spent'      => function ($q) {
                    $q->selectRaw('COALESCE(SUM(amount), 0)')
                        ->from('payments')
                        ->whereColumn('payments.user_id', 'users.id')
                        ->where('payments.status', PaymentStatus::PAID);
                },
            ])
            ->when($status, function ($query, $status) {
                $query->where('status', 'LIKE', "%{$status}%");
            })
            ->latest('id')
            ->paginate($records_per_page);

        return sendSuccess('Customers fetched successfully', compact('customers'));
    }

    public function listOptimized($request)
    {
        $perPage = $request->records_per_page ?? 15;
        $search  = trim($request->search ?? '');

        $query = User::active()
            ->select('id', 'name', 'email', 'phone')
            ->whereHas('roles', function ($q) {
                $q->where('name', 'customer');
            });

        if (! empty($search)) {
            $query->where(function ($q) use ($search) {
                $q->where('name', 'like', "%{$search}%")
                    ->orWhere('email', 'like', "%{$search}%")
                    ->orWhere('phone', 'like', "%{$search}%");
            });
        }

        $customers = $query->latest()->paginate($perPage);

        return sendSuccess('Customers fetched successfully', compact('customers'));
    }

    public function updateTags($request, $id)
    {
        $customer = $this->customerModel::findOrFail($id);

        $request->validate([
            'tags'   => 'required|array',
            'tags.*' => 'string|max:50|regex:/^[a-z0-9\s\-]+$/i',
        ]);

        $customer->syncTags($request->input('tags', []));
        $customer->load('tags');

        return sendSuccess('Customer tags updated successfully', compact('customer'));

    }

    public function updateStatus($request, $id)
    {
        $customer = $this->customerModel::findOrFail($id);

        $request->validate(['status' => 'required|in:active,blocked']);

        $customer->update(['status' => $request->input('status')]);

        if ($customer->status == User::BLOCKED) {
            $customer->tokens()->where('name', 'bookboll-customer-login-api-token')->delete();
            $customer->blocking_reason = $request->reason;
            $customer->save();
        } else {
            $customer->blocking_reason = null;
            $customer->save();
        }

        $customer->load('tags');

        return sendSuccess('Customer status updated successfully', compact('customer'));
    }

    public function createCustomer($request)
    {
        $formData = $request->validate([
            'name'                         => 'nullable|string|max:100',
            'email'                        => 'required|email|unique:users,email',
            'phone'                        => 'nullable|string|min:6|max:20',
            'password'                     => 'nullable|min:8',
            'status'                       => 'sometimes|in:' . $this->customerModel::ACTIVE . ',' . $this->customerModel::INACTIVE . ',' . $this->customerModel::BLOCKED,
            'date_of_birth'                => 'nullable|date|before:today',
            'gender'                       => 'nullable|string|in:male,female,other',
            'nationality'                  => 'nullable|string|max:100',
            'address'                      => 'nullable|string|max:255',
            'language'                     => 'nullable|string|max:50',
            'currency'                     => 'nullable|string|max:10',
            'notifications'                => 'nullable|array',
            'notifications.booking_emails' => 'nullable|boolean',
            'notifications.sms_reminders'  => 'nullable|boolean',
            'notifications.marketing'      => 'nullable|boolean',
            'favorite_sports'              => 'nullable|array',
            'favorite_sports.*'            => 'required|string',
        ]);

        try {
            DB::beginTransaction();

            $existingUser = $this->customerModel->where('email', $formData['email'])->first();
            if ($existingUser) {
                if ($existingUser->hasRole('customer')) {
                    return sendError('Customer with this email already exists');
                } else {
                    $existingUser->assignRole('customer');
                    $user = $existingUser;

                    if (! $user->preferences) {
                        Preference::create([
                            'user_id'         => $user->id,
                            'notifications'   => [
                                'booking_emails'   => true,
                                'sms_reminders'    => false,
                                'marketing_emails' => true,
                            ],
                            'favorite_sports' => [],
                            'language'        => 'english',
                            'currency'        => 'QAR',
                        ]);
                    }
                }
            } else {
                if (empty($formData['password'])) {
                    $formData['password'] = Str::random(12);
                }

                $user = $this->customerModel->create([
                    'name'          => $formData['name'],
                    'email'         => $formData['email'],
                    'phone'         => $formData['phone'],
                    'password'      => $formData['password'],
                    'gender'        => $formData['gender'] ?? null,
                    'address'       => $formData['address'] ?? null,
                    'nationality'   => $formData['nationality'] ?? null,
                    'date_of_birth' => $formData['date_of_birth'] ?? null,
                    'status'        => $formData['status'] ?? $this->customerModel::ACTIVE,
                ]);

                $user->assignRole('customer');

                if (! $user->preferences) {
                    Preference::create([
                        'user_id'         => $user->id,
                        'notifications'   => [
                            'booking_emails'   => true,
                            'sms_reminders'    => false,
                            'marketing_emails' => true,
                        ],
                        'favorite_sports' => [],
                        'language'        => 'english',
                        'currency'        => 'QAR',
                    ]);
                }
            }

            if (isset($formData['language']) || isset($formData['currency']) || isset($formData['notifications']) || isset($formData['favorite_sports'])) {
                $preferences = $user->preferences ?? Preference::create(['user_id' => $user->id]);

                if (isset($formData['language'])) {
                    $preferences->language = $formData['language'];
                }

                if (isset($formData['currency'])) {
                    $preferences->currency = $formData['currency'];
                }

                if (isset($formData['notifications'])) {
                    $currentNotifications       = $preferences->notifications ?? [];
                    $preferences->notifications = array_merge($currentNotifications, $formData['notifications']);
                }

                if (isset($formData['favorite_sports'])) {
                    $preferences->favorite_sports = $formData['favorite_sports'];
                }

                $preferences->save();
            }

            DB::commit();

            try {
                Mail::to($user->email)->send(new UserWelcomeEmail($user, $formData['password']));
            } catch (Exception $e) {
                Log::error("Failed to send welcome email: " . $e->getMessage(), ["Exception" => $e]);
            }

            $user->load(['roles', 'preferences']);
            $user->makeHidden('roles');
            $roles = $user->getRoleNames();

            return sendSuccess("Customer registered successfully", compact('user', 'roles'));

        } catch (Exception $e) {
            DB::rollBack();
            Log::error("Customer registration error: " . $e->getMessage(), ["Exception" => $e]);
            return sendError("Something went wrong: " . $e->getMessage());
        }
    }

    public function getCustomersForExport($request)
    {
        $search    = $request->search ?? null;
        $status    = $request->status ?? null;
        $tags      = $request->tag ?? null;
        $exportAll = $request->export_all == 1 || $request->export_all === '1';

        $query = $this->customerModel::whereHas('roles', function ($query) {
            $query->where('Name', 'customer');
        })
            ->withCount(['bookings'])
            ->with(['tags'])
            ->when($search, function ($query, $search) {
                $query->where(function ($q) use ($search) {
                    $q->where('name', 'LIKE', "%{$search}%")
                        ->orWhere('email', 'LIKE', "%{$search}%")
                        ->orWhere('phone', 'LIKE', "%{$search}%");
                });
            })
            ->addSelect([
                'loyalty_balance'  => function ($q) {
                    $q->selectRaw('
                COALESCE(SUM(CASE WHEN type = "credit" THEN points ELSE 0 END), 0) -
                COALESCE(SUM(CASE WHEN type = "debit" THEN points ELSE 0 END), 0)
            ')
                        ->from('loyalty_points')
                        ->whereColumn('loyalty_points.user_id', 'users.id');
                },
                'total_due_amount' => function ($q) {
                    $q->selectRaw('COALESCE(SUM(amount), 0)')
                        ->from('payments')
                        ->whereColumn('payments.user_id', 'users.id')
                        ->where('payments.status', '!=', PaymentStatus::PAID);
                },
                'total_spent'      => function ($q) {
                    $q->selectRaw('COALESCE(SUM(amount), 0)')
                        ->from('payments')
                        ->whereColumn('payments.user_id', 'users.id')
                        ->where('payments.status', PaymentStatus::PAID);
                },
            ])
            ->when($status, function ($query, $status) {
                $query->where('status', 'LIKE', "%{$status}%");
            })
            ->when($tags, function ($query, $tags) {
                $query->whereHas('tags', function ($q) use ($tags) {
                    $q->where('name', $tags);
                });
            });

        if (! $exportAll) {
            $records_per_page = $request->records_per_page ?? 10;
            $page             = $request->page ?? 1;
            $query->skip(($page - 1) * $records_per_page)->take($records_per_page);
        }

        return $query->get();
    }

    /**
     * Admin app: full customer profile, loyalty summary, and booking counts by status.
     */
    public function getAdminProfile(string $id)
    {
        $customer = $this->customerModel::whereHas('roles', function ($query) {
                $query->where('name', 'customer');
            })
            ->with(['tags:id,name,color'])
            ->find($id);

        if (! $customer) {
            return sendError('Customer not found', [], 404);
        }

        $balance = (int) LoyaltyPoint::balance($customer->id);
        $tier    = $customer->loyalty_tier;

        $loyaltyTier = $tier ? [
            'id'         => $tier->id,
            'name'       => $tier->name,
            'slug'       => $tier->slug,
            'color'      => $tier->color,
            'min_points' => $tier->min_points,
            'max_points' => $tier->max_points,
        ] : null;

        $rawStats = Booking::where('user_id', $customer->id)
            ->selectRaw('status, COUNT(*) as c')
            ->groupBy('status')
            ->pluck('c', 'status')
            ->toArray();

        $bookingStats = [
            'pending'   => (int) ($rawStats[BookingStatus::PENDING] ?? 0),
            'confirmed' => (int) ($rawStats[BookingStatus::CONFIRMED] ?? 0),
            'completed' => (int) ($rawStats[BookingStatus::COMPLETED] ?? 0),
            'cancelled' => (int) ($rawStats[BookingStatus::CANCELLED] ?? 0),
            'total'     => array_sum(array_map('intval', $rawStats)),
        ];

        $totalSpent = (float) DB::table('payments')
            ->where('user_id', $customer->id)
            ->where('status', PaymentStatus::PAID)
            ->sum('amount');

        $totalDue = (float) DB::table('payments')
            ->where('user_id', $customer->id)
            ->where('status', '!=', PaymentStatus::PAID)
            ->sum('amount');

        $statusLabelMap = [
            User::ACTIVE   => 'Active',
            User::INACTIVE => 'Inactive',
            User::BLOCKED  => 'Blocked',
            User::PENDING  => 'Pending',
        ];

        $dob = $customer->date_of_birth;
        if ($dob instanceof \Carbon\CarbonInterface) {
            $dob = $dob->format('Y-m-d');
        } elseif (is_string($dob) && $dob !== '') {
            try {
                $dob = \Carbon\Carbon::parse($dob)->format('Y-m-d');
            } catch (\Throwable) {
                // keep raw string
            }
        }

        $customerPayload = [
            'id'                => $customer->id,
            'name'              => $customer->name,
            'email'             => $customer->email,
            'phone'             => $customer->phone,
            'image'             => $customer->image,
            'status'            => $customer->status,
            'status_label'      => $statusLabelMap[$customer->status] ?? ucfirst((string) $customer->status),
            'blocking_reason'   => $customer->blocking_reason,
            'gender'            => $customer->gender,
            'address'           => $customer->address,
            'nationality'       => $customer->nationality,
            'date_of_birth'     => $dob,
            'created_at'        => $customer->created_at?->toIso8601String(),
            'updated_at'        => $customer->updated_at?->toIso8601String(),
            'tags'              => $customer->tags->map(fn ($t) => [
                'id'    => $t->id,
                'name'  => $t->name,
                'color' => $t->color,
            ])->values(),
        ];

        return sendSuccess('Customer profile fetched', [
            'customer'        => $customerPayload,
            'loyalty'         => [
                'points_balance'      => $balance,
                'tier'                => $loyaltyTier,
                'points_to_next_tier' => (int) $customer->points_to_next_tier,
                'loyalty_progress'    => (int) $customer->loyalty_progress,
            ],
            'booking_stats'   => $bookingStats,
            'financial'       => [
                'total_spent' => round($totalSpent, 2),
                'total_due'   => round($totalDue, 2),
            ],
        ]);
    }

    /**
     * Admin app: paginated bookings for a customer, optional status filter.
     */
    public function getAdminBookings(Request $request, string $id)
    {
        $exists = $this->customerModel::whereHas('roles', function ($query) {
                $query->where('name', 'customer');
            })
            ->whereKey($id)
            ->exists();

        if (! $exists) {
            return sendError('Customer not found', [], 404);
        }

        $perPage = (int) ($request->input('records_per_page', 20));
        $perPage = min(max($perPage, 1), 100);

        $status = $request->input('status');

        $query = Booking::where('user_id', $id)
            ->with([
                'listing:id,name,calendar_color',
                'venue:id,name',
            ])
            ->orderByDesc('date')
            ->orderByDesc('id');

        if ($status && $status !== 'all' && in_array($status, BookingStatus::list(), true)) {
            $query->where('status', $status);
        }

        $bookings = $query->paginate($perPage);

        return sendSuccess('Customer bookings fetched', compact('bookings'));
    }

    public function delete($id)
    {
        try {
            DB::beginTransaction();

            $customer = $this->customerModel::whereHas('roles', function ($query) {
                $query->where('name', 'customer');
            })->findOrFail($id);

            $userRoles        = $customer->getRoleNames();
            $hasMultipleRoles = $userRoles->count() > 1;

            $activeBookings = $customer->bookings()
                ->whereIn('status', [BookingStatus::PENDING, BookingStatus::CONFIRMED])
                ->count();

            if ($activeBookings > 0) {
                DB::rollBack();
                return sendError("Cannot delete customer with {$activeBookings} active booking(s). Please cancel or complete the bookings first.", [], 422);
            }

            if ($hasMultipleRoles) {
                $customer->removeRole('customer');

                $customer->onesignalSubscriptions()->delete();
                $customer->tags()->detach();

                DB::commit();

                Log::info("Customer role removed from user", [
                    'user_id'         => $id,
                    'remaining_roles' => $customer->getRoleNames()->toArray(),
                    'deleted_by'      => auth()->id(),
                ]);

                return sendSuccess('Customer access removed successfully. User still has other roles: ' . $customer->getRoleNames()->implode(', '));

            } else {
                $customer->tokens()->delete();
                $customer->onesignalSubscriptions()->delete();
                $customer->tags()->detach();

                $customer->delete();

                DB::commit();

                Log::info("Customer deleted successfully", [
                    'customer_id' => $id,
                    'deleted_by'  => auth()->id(),
                ]);

                return sendSuccess('Customer deleted successfully');
            }

        } catch (Exception $e) {
            DB::rollBack();
            Log::error("Customer deletion error: " . $e->getMessage(), [
                'customer_id' => $id,
                'exception'   => $e,
            ]);
            return sendError('Failed to delete customer: ' . $e->getMessage());
        }
    }

    /**
     * Paginated roster for the apps/admin Customers page.
     * Old ADMIN still uses list() / GET /customers.
     */
    public function dashboard(Request $request)
    {
        $perPage = min(max((int) ($request->input('records_per_page') ?? $request->input('per_page') ?? 25), 1), 250);

        $query = $this->customerQuery()
            ->with(['tags', 'preferences', 'customerLoyalty', 'loyaltyTier']);

        $search = trim((string) ($request->input('search') ?? ''));
        if ($search !== '') {
            $query->where(function ($q) use ($search) {
                $q->where('name', 'LIKE', "%{$search}%")
                    ->orWhere('email', 'LIKE', "%{$search}%")
                    ->orWhere('phone', 'LIKE', "%{$search}%");
            });
        }

        $statuses = $request->input('status');
        if (is_string($statuses) && $statuses !== '') {
            $statuses = [$statuses];
        }
        if (is_array($statuses) && $statuses) {
            $query->whereIn('status', $statuses);
        }

        $sort = (string) $request->input('sort', 'created_at');
        $dir  = strtolower((string) $request->input('sort_dir', 'desc')) === 'asc' ? 'asc' : 'desc';
        if ($sort === 'full_name') {
            $query->orderBy('name', $dir);
        } else {
            $query->orderBy('users.created_at', $dir);
        }

        $paginator = $query->paginate($perPage);
        $pageUsers = $paginator->getCollection();
        $pageIds   = $pageUsers->pluck('id')->all();

        $mapped = $pageUsers->map(fn (User $u) => $this->mapDashboardCustomer($u))->values();

        $bookings = Booking::query()
            ->whereIn('user_id', $pageIds ?: [0])
            ->where('status', '!=', BookingStatus::CANCELLED)
            ->orderByDesc('date')
            ->get(['id', 'user_id', 'listing_id', 'venue_id', 'date', 'total_price', 'status', 'payment_status', 'start_time', 'end_time', 'created_at'])
            ->map(fn (Booking $b) => $this->mapDashboardBooking($b))
            ->values();

        $spentByCustomer = [];
        foreach ($bookings as $b) {
            if (! $b['customer_id']) {
                continue;
            }
            $spentByCustomer[$b['customer_id']] = ($spentByCustomer[$b['customer_id']] ?? 0) + (float) $b['total'];
        }
        $mapped = $mapped->map(function ($c) use ($spentByCustomer) {
            $c['total_spent'] = round($spentByCustomer[$c['id']] ?? 0, 2);

            return $c;
        })->values();

        $paginator->setCollection($mapped);

        $lookups = $request->boolean('include_lookups', true) ? $this->dashboardLookups() : [];
        $loyaltyKey = CustomerLoyalty::ownerColumn();

        $lookups['customers'] = $paginator->toArray();
        $lookups['bookings'] = $bookings;
        $lookups['customer_loyalty'] = CustomerLoyalty::query()
            ->whereIn($loyaltyKey, $pageIds ?: [0])
            ->get()
            ->map(fn (CustomerLoyalty $row) => $this->mapLoyaltyRow($row))
            ->values();
        $lookups['programs'] = $lookups['programs'] ?? [];
        $lookups['subscriptions'] = $lookups['subscriptions'] ?? [];
        $lookups['loyalty_programs'] = $lookups['loyalty_programs'] ?? [];
        $lookups['loyalty_tiers'] = $lookups['loyalty_tiers'] ?? [];
        $lookups['kpis'] = [
            'total'      => $this->customerQuery()->count(),
            'active'     => $this->customerQuery()->where('status', User::ACTIVE)->count(),
            'activeSubs' => 0,
            'avgSpend'   => 0,
            'vip'        => 0,
        ];

        return sendSuccess('Customers fetched', $lookups);
    }

    public function crm($id)
    {
        $customer = $this->customerQuery()
            ->with(['tags', 'preferences', 'customerLoyalty', 'loyaltyTier'])
            ->find($id);

        if (! $customer) {
            return sendError('Customer not found', [], 404);
        }

        $lookups = $this->dashboardLookups();

        $bookings = Booking::query()
            ->where('user_id', $customer->id)
            ->orderByDesc('date')
            ->orderByDesc('id')
            ->get()
            ->map(fn (Booking $b) => $this->mapDashboardBooking($b))
            ->values();

        $loyalty = $customer->customerLoyalty
            ->map(fn (CustomerLoyalty $row) => $this->mapLoyaltyRow($row))
            ->values();

        return sendSuccess('Customer fetched', array_merge($lookups, [
            'customer'          => $this->mapDashboardCustomer($customer),
            'bookings'          => $bookings,
            'customer_loyalty'  => $loyalty,
            'programs'          => [],
            'loyalty_programs'  => [],
            'loyalty_tiers'     => [],
        ]));
    }

    public function storeDashboard(Request $request)
    {
        $data = $this->validateDashboard($request);

        try {
            DB::beginTransaction();

            if (empty($data['password'])) {
                $data['password'] = Str::random(12);
            }

            $email = trim((string) ($data['email'] ?? ''));
            if ($email === '') {
                $email = 'c-' . Str::uuid() . '@placeholder.local';
            }

            $user = $this->customerModel->create([
                'name'               => $data['full_name'] ?? $data['name'] ?? null,
                'email'              => $email,
                'phone'              => $data['phone'] ?? null,
                'password'           => $data['password'],
                'gender'             => ($data['gender'] ?? '') ?: null,
                'country_id'         => ($data['country_id'] ?? '') ?: null,
                'status'             => $data['status'] ?? User::ACTIVE,
                'crm_tags'           => $data['tags'] ?? '',
                'marketing_email'    => (bool) ($data['marketing_email'] ?? false),
                'marketing_sms'      => (bool) ($data['marketing_sms'] ?? false),
                'marketing_whatsapp' => (bool) ($data['marketing_whatsapp'] ?? false),
            ]);

            $user->assignRole('customer');
            $this->syncCrmTags($user, $data['tags'] ?? '');
            $this->replaceLoyaltyRows($user->id, $data['loyalty'] ?? []);

            Preference::create([
                'user_id'         => $user->id,
                'notifications'   => [
                    'booking_emails'   => true,
                    'sms_reminders'    => false,
                    'marketing_emails' => (bool) ($data['marketing_email'] ?? false),
                ],
                'favorite_sports' => [],
                'language'        => 'english',
                'currency'        => 'AED',
            ]);

            DB::commit();

            $user->load(['tags', 'preferences', 'customerLoyalty', 'loyaltyTier']);

            return sendSuccess('Customer created', [
                'customer' => $this->mapDashboardCustomer($user),
            ], 201);
        } catch (Exception $e) {
            DB::rollBack();
            Log::error('Customer create error: ' . $e->getMessage(), ['Exception' => $e]);
            return sendError('Could not save customer.');
        }
    }

    public function updateDashboard(Request $request, $id)
    {
        $user = $this->customerQuery()->findOrFail($id);
        $data = $this->validateDashboard($request, $user->id, true);

        try {
            DB::beginTransaction();

            $payload = [];
            if (array_key_exists('full_name', $data) || array_key_exists('name', $data)) {
                $payload['name'] = $data['full_name'] ?? $data['name'] ?? $user->name;
            }
            foreach (['email', 'phone', 'gender', 'country_id', 'status'] as $key) {
                if (array_key_exists($key, $data)) {
                    if ($key === 'email') {
                        $email = trim((string) $data['email']);
                        if ($email !== '') {
                            $payload['email'] = $email;
                        }
                        continue;
                    }
                    $payload[$key] = $data[$key] === '' ? null : $data[$key];
                }
            }
            foreach (['marketing_email', 'marketing_sms', 'marketing_whatsapp'] as $key) {
                if (array_key_exists($key, $data)) {
                    $payload[$key] = (bool) $data[$key];
                }
            }
            if (array_key_exists('tags', $data)) {
                $payload['crm_tags'] = $data['tags'];
            }

            if ($payload) {
                $user->update($payload);
            }

            if (array_key_exists('tags', $data)) {
                $this->syncCrmTags($user, $data['tags']);
            }

            if (array_key_exists('loyalty', $data)) {
                $this->replaceLoyaltyRows($user->id, $data['loyalty'] ?? []);
            }

            DB::commit();

            $user->load(['tags', 'preferences', 'customerLoyalty', 'loyaltyTier']);

            return sendSuccess('Customer updated', [
                'customer' => $this->mapDashboardCustomer($user),
            ]);
        } catch (Exception $e) {
            DB::rollBack();
            Log::error('Customer update error: ' . $e->getMessage(), ['customer_id' => $id, 'Exception' => $e]);
            return sendError('Could not save customer.');
        }
    }

    public function replaceLoyalty(Request $request, $id)
    {
        $user = $this->customerQuery()->findOrFail($id);
        $data = $request->validate([
            'loyalty'                   => 'nullable|array',
            'loyalty.*.country_id'      => 'required',
            'loyalty.*.points'          => 'nullable|numeric|min:0',
            'loyalty.*.lifetime_points' => 'nullable|numeric|min:0',
        ]);

        $this->replaceLoyaltyRows($user->id, $data['loyalty'] ?? []);

        $rows = CustomerLoyalty::where(CustomerLoyalty::ownerColumn(), $user->id)
            ->get()
            ->map(fn (CustomerLoyalty $row) => $this->mapLoyaltyRow($row))
            ->values();

        return sendSuccess('Loyalty updated', ['customer_loyalty' => $rows]);
    }

    public function upsertLoyalty(Request $request, $id)
    {
        $user = $this->customerQuery()->findOrFail($id);
        $data = $request->validate([
            'country_id'      => 'required',
            'points'          => 'nullable|numeric|min:0',
            'lifetime_points' => 'nullable|numeric|min:0',
        ]);

        $pts  = max(0, (int) round((float) ($data['points'] ?? 0)));
        $life = max($pts, (int) round((float) ($data['lifetime_points'] ?? 0)));

        $row = CustomerLoyalty::updateOrCreate(
            [
                CustomerLoyalty::ownerColumn() => $user->id,
                'country_id'                   => (int) $data['country_id'],
            ],
            [
                'points'          => $pts,
                'lifetime_points' => $life,
            ]
        );

        return sendSuccess('Loyalty points updated', [
            'row' => $this->mapLoyaltyRow($row),
        ]);
    }

    public function deleteLoyalty($id, $countryId)
    {
        $user = $this->customerQuery()->findOrFail($id);

        CustomerLoyalty::where(CustomerLoyalty::ownerColumn(), $user->id)
            ->where('country_id', $countryId)
            ->delete();

        return sendSuccess('Loyalty removed');
    }

    protected function customerQuery()
    {
        return $this->customerModel::whereHas('roles', function ($query) {
            $query->where('name', 'customer');
        });
    }

    protected function validateDashboard(Request $request, $ignoreId = null, bool $partial = false)
    {
        $emailRule = $partial
            ? 'sometimes|nullable|email|unique:users,email' . ($ignoreId ? ',' . $ignoreId : '')
            : 'nullable|email|unique:users,email' . ($ignoreId ? ',' . $ignoreId : '');

        return $request->validate([
            'full_name'                 => ($partial ? 'sometimes|' : 'required|') . 'string|max:100',
            'name'                      => 'sometimes|nullable|string|max:100',
            'email'                     => $emailRule,
            'phone'                     => 'nullable|string|max:20',
            'password'                  => 'nullable|min:8',
            'gender'                    => 'nullable|string|in:male,female,other',
            'country_id'                => 'nullable',
            'status'                    => 'sometimes|in:' . User::ACTIVE . ',' . User::INACTIVE . ',' . User::BLOCKED,
            'tags'                      => 'nullable|string',
            'marketing_email'           => 'sometimes|boolean',
            'marketing_sms'             => 'sometimes|boolean',
            'marketing_whatsapp'        => 'sometimes|boolean',
            'loyalty'                   => 'nullable|array',
            'loyalty.*.country_id'      => 'required',
            'loyalty.*.points'          => 'nullable|numeric|min:0',
            'loyalty.*.lifetime_points' => 'nullable|numeric|min:0',
        ]);
    }

    protected function syncCrmTags(User $user, ?string $encoded): void
    {
        $encoded = (string) $encoded;
        $user->crm_tags = $encoded;
        $user->save();

        $names = [];
        foreach (explode(',', $encoded) as $entry) {
            $entry = trim($entry);
            if ($entry === '' || str_starts_with($entry, '⛔')) {
                continue;
            }
            $names[] = $entry;
        }

        $user->syncTags($names);
    }

    protected function replaceLoyaltyRows($customerId, array $rows): void
    {
        $ownerCol = CustomerLoyalty::ownerColumn();
        CustomerLoyalty::where($ownerCol, $customerId)->delete();

        $byCountry = [];
        foreach ($rows as $r) {
            $cid = $r['country_id'] ?? null;
            if ($cid === null || $cid === '') {
                continue;
            }
            $pts  = max(0, (int) round((float) ($r['points'] ?? 0)));
            $life = max($pts, (int) round((float) ($r['lifetime_points'] ?? 0)));
            $now = now();
            $byCountry[(int) $cid] = [
                $ownerCol         => $customerId,
                'country_id'      => (int) $cid,
                'points'          => $pts,
                'lifetime_points' => $life,
                'created_at'      => $now,
                'updated_at'      => $now,
            ];
        }

        if ($byCountry) {
            CustomerLoyalty::insert(array_values($byCountry));
        }
    }

    protected function dashboardLookups(): array
    {
        $venues = Venue::with('users')
            ->orderBy('name')
            ->get(['id', 'name', 'status', 'city_id', 'country_id', 'created_by']);

        $countryIds = $venues->pluck('country_id')->filter()->unique()->values();
        $currencyByCountry = $countryIds->isEmpty()
            ? collect()
            : Country::with('currency')->whereIn('id', $countryIds)->get()
                ->mapWithKeys(fn (Country $c) => [$c->id => $c->currency?->code ?: 'AED']);

        $locations = $venues->map(function (Venue $v) use ($currencyByCountry) {
            $ownerId = $v->users->first()?->id ?? $v->created_by;

            return [
                'id'                => (string) $v->id,
                'name'              => $v->name,
                'status'            => $v->status,
                'city_id'           => $v->city_id ? (string) $v->city_id : null,
                'owner_user_id'     => $ownerId ? (string) $ownerId : null,
                'currency_override' => $currencyByCountry[$v->country_id] ?? null,
            ];
        })->values();

        $listings = Listing::with('sportsCategory:id,name')
            ->orderBy('name')
            ->get(['id', 'name', 'venue_id', 'calendar_color', 'sports_category_id'])
            ->map(function (Listing $l) {
                return [
                    'id'             => (string) $l->id,
                    'name'           => $l->name,
                    'location_id'    => $l->venue_id ? (string) $l->venue_id : null,
                    'booking_color'  => $l->calendar_color ?: '#64748b',
                    'sport_category' => $l->sportsCategory?->name,
                ];
            })->values();

        $cityIds = $venues->pluck('city_id')->filter()->unique()->values();
        $cities = $cityIds->isEmpty()
            ? collect()
            : City::whereIn('id', $cityIds)
                ->orderBy('name')
                ->get(['id', 'name', 'country_id'])
                ->map(fn (City $c) => [
                    'id'         => (string) $c->id,
                    'name'       => $c->name,
                    'country_id' => $c->country_id ? (string) $c->country_id : null,
                    'status'     => 'active',
                ]);

        $countries = Country::with('currency')
            ->orderBy('name')
            ->get()
            ->filter(fn (Country $c) => (int) ($c->status ?? 1) === 1)
            ->map(function (Country $c) {
                return [
                    'id'            => (string) $c->id,
                    'name'          => $c->name,
                    'currency_code' => $c->currency?->code ?: 'AED',
                    'status'        => 'active',
                ];
            })->values();

        return compact('locations', 'listings', 'cities', 'countries');
    }

    protected function mapDashboardCustomer(User $u): array
    {
        $u->setAppends([]);

        $loyaltyRows = $u->relationLoaded('customerLoyalty')
            ? $u->customerLoyalty
            : $u->customerLoyalty()->get();

        $loyaltyPoints = (int) $loyaltyRows->sum('points');

        $prefs  = $u->preferences;
        $notify = is_array($prefs?->notifications) ? $prefs->notifications : [];

        $tags = $u->crm_tags;
        if ($tags === null || $tags === '') {
            $tagNames = $u->relationLoaded('tags')
                ? $u->tags->pluck('name')->all()
                : [];
            $tags = implode(', ', $tagNames);
        }

        return [
            'id'                 => (string) $u->id,
            'full_name'          => $u->name,
            'email'              => str_ends_with((string) $u->email, '@placeholder.local') ? '' : $u->email,
            'phone'              => $u->phone,
            'gender'             => $u->gender,
            'country_id'         => $u->country_id ? (string) $u->country_id : null,
            'status'             => $u->status ?: User::ACTIVE,
            'tags'               => (string) $tags,
            'loyalty_tier'       => $u->loyaltyTier?->name ?: 'Rookie',
            'loyalty_points'     => $loyaltyPoints,
            'marketing_email'    => (bool) ($u->marketing_email ?? $notify['marketing_emails'] ?? $notify['marketing'] ?? false),
            'marketing_sms'      => (bool) ($u->marketing_sms ?? $notify['sms_reminders'] ?? false),
            'marketing_whatsapp' => (bool) ($u->marketing_whatsapp ?? false),
            'unsubscribe_token'  => substr(hash('sha256', 'unsub:' . $u->id . config('app.key')), 0, 32),
            'created_at'         => $u->created_at?->toIso8601String(),
            'updated_at'         => $u->updated_at?->toIso8601String(),
        ];
    }

    protected function mapDashboardBooking(Booking $b): array
    {
        $payment = (string) $b->payment_status;
        if ($payment === BookingPaymentStatus::FULLY_PAID) {
            $payment = 'paid';
        } elseif ($payment === BookingPaymentStatus::PAYMENT_SKIPPED) {
            $payment = 'skipped';
        }

        $date = $b->date;
        if ($date instanceof \Carbon\CarbonInterface) {
            $date = $date->format('Y-m-d');
        }

        return [
            'id'             => (string) $b->id,
            'customer_id'    => $b->user_id ? (string) $b->user_id : null,
            'listing_id'     => $b->listing_id ? (string) $b->listing_id : null,
            'location_id'    => $b->venue_id ? (string) $b->venue_id : null,
            'booking_date'   => $date,
            'total'          => (float) ($b->total_price ?? 0),
            'booking_status' => $b->status,
            'payment_status' => $payment,
            'start_time'     => $b->start_time,
            'end_time'       => $b->end_time,
            'created_at'     => $b->created_at?->toIso8601String(),
        ];
    }

    protected function mapLoyaltyRow(CustomerLoyalty $row): array
    {
        return [
            'id'              => (string) $row->id,
            'customer_id'     => (string) ($row->{CustomerLoyalty::ownerColumn()} ?? $row->customer_id ?? $row->user_id),
            'country_id'      => (string) $row->country_id,
            'points'          => (int) $row->points,
            'lifetime_points' => (int) $row->lifetime_points,
        ];
    }

}
