<?php
namespace App\Services;

use App\Models\User;

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
                'loyalty_balance' => function ($q) {
                    $q->selectRaw('
                COALESCE(SUM(CASE WHEN type = "credit" THEN points ELSE 0 END), 0) -
                COALESCE(SUM(CASE WHEN type = "debit" THEN points ELSE 0 END), 0)
            ')
                        ->from('loyalty_points')
                        ->whereColumn('loyalty_points.user_id', 'users.id');
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
        $customer->load('tags');

        return sendSuccess('Customer status updated successfully', compact('customer'));
    }

}
