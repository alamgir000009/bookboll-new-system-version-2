<?php
namespace App\Services;

use App\Mail\UserWelcomeEmail;
use App\Models\User;
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
            'name'          => 'required|string|max:100',
            'email'         => 'required|email|unique:users,email',
            'phone'         => 'required|string|min:6|max:20',
            'password'      => 'nullable|min:8',
            'status'        => 'sometimes|in:' . $this->customerModel::ACTIVE . ',' . $this->customerModel::INACTIVE . ',' . $this->customerModel::BLOCKED,
            'date_of_birth' => 'nullable|date|before:today',
            'gender'        => 'nullable|string|in:male,female,other',
            'nationality'   => 'nullable|string|max:100',
            'address'       => 'nullable|string|max:255',
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

            DB::commit();

            try {
                Mail::to($user->email)->queue(new UserWelcomeEmail($user, $formData['password']));
            } catch (Exception $e) {
                Log::error("Failed to send welcome email: " . $e->getMessage(), ["Exception" => $e]);
            }

            $user->load(['roles']);
            $user->makeHidden('roles');
            $roles = $user->getRoleNames();

            return sendSuccess("Customer registered successfully", compact('user', 'roles'));

        } catch (Exception $e) {
            DB::rollBack();
            Log::error("Customer registration error: " . $e->getMessage(), ["Exception" => $e]);
            return sendError("Something went wrong: " . $e->getMessage());
        }
    }

}
