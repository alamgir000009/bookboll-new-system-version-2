<?php
namespace App\Services;

use App\Mail\UserWelcomeEmail;
use App\Models\Preference;
use App\Models\User;
use BookingStatus;
use Exception;
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
            'name'          => 'nullable|string|max:100',
            'email'         => 'required|email|unique:users,email',
            'phone'         => 'nullable|string|min:6|max:20',
            'password'      => 'nullable|min:8',
            'status'        => 'sometimes|in:' . $this->customerModel::ACTIVE . ',' . $this->customerModel::INACTIVE . ',' . $this->customerModel::BLOCKED,
            'date_of_birth' => 'nullable|date|before:today',
            'gender'        => 'nullable|string|in:male,female,other',
            'nationality'   => 'nullable|string|max:100',
            'address'       => 'nullable|string|max:255',
            'language'      => 'nullable|string|max:50',
            'currency'      => 'nullable|string|max:10',
            'notifications' => 'nullable|array',
            'notifications.email_bookings' => 'nullable|boolean',
            'notifications.sms_reminders'  => 'nullable|boolean',
            'notifications.marketing'     => 'nullable|boolean',
            'favorite_sports'             => 'nullable|array',
            'favorite_sports.*'           => 'required|string',
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
            }

            // Create or update preferences if language or currency is provided
            if (isset($formData['language']) || isset($formData['currency']) || isset($formData['notifications']) || isset($formData['favorite_sports'])) {
                $preferences = $user->preferences ?? Preference::create(['user_id' => $user->id]);

                if (isset($formData['language'])) {
                    $preferences->language = $formData['language'];
                }

                if (isset($formData['currency'])) {
                    $preferences->currency = $formData['currency'];
                }

                if (isset($formData['notifications'])) {
                    $currentNotifications = $preferences->notifications ?? [];
                    $preferences->notifications = array_merge($currentNotifications, $formData['notifications']);
                }

                if (isset($formData['favorite_sports'])) {
                    $preferences->favorite_sports = $formData['favorite_sports'];
                }

                $preferences->save();
            }

            DB::commit();

            try {
                Mail::to($user->email)->queue(new UserWelcomeEmail($user, $formData['password']));
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
        $search = $request->search ?? null;
        $status = $request->status ?? null;
        $tags   = $request->tag ?? null;
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

        // If not exporting all, apply pagination
        if (!$exportAll) {
            $records_per_page = $request->records_per_page ?? 10;
            $page = $request->page ?? 1;
            $query->skip(($page - 1) * $records_per_page)->take($records_per_page);
        }

        return $query->get();
    }

    public function delete($id)
    {
        try {
            DB::beginTransaction();

            $customer = $this->customerModel::whereHas('roles', function ($query) {
                $query->where('name', 'customer');
            })->findOrFail($id);

            // Get all user roles
            $userRoles = $customer->getRoleNames();
            $hasMultipleRoles = $userRoles->count() > 1;

            // Check for active bookings
            $activeBookings = $customer->bookings()
                ->whereIn('status', [BookingStatus::PENDING, BookingStatus::CONFIRMED])
                ->count();

            if ($activeBookings > 0) {
                DB::rollBack();
                return sendError("Cannot delete customer with {$activeBookings} active booking(s). Please cancel or complete the bookings first.", [], 422);
            }

            if ($hasMultipleRoles) {
                // User has multiple roles - only remove customer role
                $customer->removeRole('customer');

                // Clean up customer-specific data
                // Note: We keep the user record and other roles intact
                // Only remove customer-specific subscriptions and tags
                $customer->onesignalSubscriptions()->delete();
                $customer->tags()->detach();

                DB::commit();

                Log::info("Customer role removed from user", [
                    'user_id' => $id,
                    'remaining_roles' => $customer->getRoleNames()->toArray(),
                    'deleted_by' => auth()->id(),
                ]);

                return sendSuccess('Customer access removed successfully. User still has other roles: ' . $customer->getRoleNames()->implode(', '));

            } else {
                // User has only customer role - delete the entire user
                // Delete related records
                $customer->tokens()->delete();
                $customer->onesignalSubscriptions()->delete();
                $customer->tags()->detach();

                // Delete the customer
                $customer->delete();

                DB::commit();

                Log::info("Customer deleted successfully", [
                    'customer_id' => $id,
                    'deleted_by' => auth()->id(),
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
