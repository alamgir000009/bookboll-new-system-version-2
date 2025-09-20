<?php
namespace App\Services;

use App\Models\AddOn;
use App\Models\AuditLog;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

class AddOnService
{
    public function __construct(protected AddOn $addOnModel)
    {}

    public function all($request)
    {
        $search = $request->search ?? null;

        $addOns = $this->addOnModel::latest()
            ->when($search, function ($query, $search) {
                $query->where('name', 'LIKE', "%{$search}%")
                    ->orWhere('status', 'LIKE', "%{$search}%")
                    ->orWhere('price', 'LIKE', "%{$search}%")
                    ->orWhere('hours_extension', 'LIKE', "%{$search}%")
                    ->orWhere('description', 'LIKE', "%{$search}%");
            })->get();

        return sendSuccess('AddOn fetched successfully', compact('addOns'));
    }
    public function create($request)
    {
        $formData = $request->validate([
            'name'            => 'required|string|max:255',
            'description'     => 'nullable|string',
            'price'           => 'required|numeric|min:0',
            'hours_extension' => 'nullable|integer|min:0',
            'status'          => 'required|in:active,inactive',
        ]);

        $addOn = $this->addOnModel::create($formData);

        return sendSuccess('AddOn added successfully', compact('addOn'));
    }

    public function update($request, $id)
    {
        $addOn = $this->addOnModel->findOrFail($id);

        $formData = $request->validate([
            'name'            => 'sometimes|required|string|max:255',
            'description'     => 'nullable|string',
            'price'           => 'sometimes|required|numeric|min:0',
            'hours_extension' => 'nullable|integer|min:0',
            'status'          => 'sometimes|required|in:active,inactive',
        ]);

        $addOn->update($formData);

        return sendSuccess('Add-On updated successfully', compact('addOn'));
    }

    public function delete($id)
    {
        $addOn = $this->addOnModel->findOrFail($id);
        $addOn->delete();
        return sendSuccess('Add-On deleted successfully');
    }

    protected function logActivity(string $action, AddOn $addOn): void
    {
        $user = Auth::user();

        AuditLog::create([
            'timestamp'   => now(),
            'actor_email' => $user ? $user->email : 'system',
            'action'      => $action,
            'resource'    => 'add-on',
            'resource_id' => $addOn->id,
            'details'     => json_encode([
                'name'   => $addOn->name,
                'price'  => $addOn->price,
                'status' => $addOn->status,
                // Add more details if needed
            ]),
            'ip_address'  => request()->ip(),
            'device'      => request()->userAgent(),
        ]);
    }
}
