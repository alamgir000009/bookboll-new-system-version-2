<?php
namespace App\Services;

use App\Mail\UserWelcomeEmail;
use App\Models\Booking;
use App\Models\LoyaltyPoint;
use App\Models\Preference;
use App\Models\User;
use BookingStatus;
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

        $query = User::query()
            ->active()
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
        $customer = $this->customerModel::query()
            ->whereHas('roles', function ($query) {
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

        $rawStats = Booking::query()
            ->where('user_id', $customer->id)
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
            'customer'       => $customerPayload,
            'loyalty'        => [
                'points_balance'      => $balance,
                'tier'                => $loyaltyTier,
                'points_to_next_tier' => (int) $customer->points_to_next_tier,
                'loyalty_progress'    => (int) $customer->loyalty_progress,
            ],
            'booking_stats' => $bookingStats,
        ]);
    }

    /**
     * Admin app: paginated bookings for a customer, optional status filter.
     */
    public function getAdminBookings(Request $request, string $id)
    {
        $exists = $this->customerModel::query()
            ->whereHas('roles', function ($query) {
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

        $query = Booking::query()
            ->where('user_id', $id)
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

}
