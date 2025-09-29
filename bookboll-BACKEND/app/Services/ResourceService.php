<?php
namespace App\Services;

use App\Models\Resource;
use Exception;

class ResourceService
{
    public function __construct(protected Resource $resourceModel)
    {}

    public function all($request)
    {
        $search           = $request->search ?? null;
        $records_per_page = $request->records_per_page ?? 10;

        $resources = $this->resourceModel::latest('id')
            ->when($search, function ($query, $search) {
                $query->where('name', 'LIKE', "%{$search}%")
                    ->where('total_quantity', 'LIKE', "%{$search}%")
                    ->where('status', 'LIKE', "%{$search}%")
                    ->where('unit_type', 'LIKE', "%{$search}%");
            })->paginate($records_per_page);

        return sendSuccess('Resources retrieved successfully', compact('resources'));
    }

    public function create($request)
    {
        $formData = $request->validate([
            'name'           => 'required|string|max:255|unique:resources,name',
            'total_quantity' => 'required|integer|min:1',
            'unit_type'      => 'nullable|string|max:50',
            'status'         => 'required|in:active,inactive',
        ]);

        $resource = $this->resourceModel::create($formData);

        return sendSuccess('Resource created successfully', compact('resource'));
    }

    public function update($request, int $id)
    {
        $resource = $this->resourceModel::findOrFail($id);

        $formData = $request->validate([
            'name'           => 'required|string|max:255|unique:resources,name,' . $id,
            'total_quantity' => 'required|integer|min:1',
            'unit_type'      => 'nullable|string|max:50',
            'status'         => 'required|in:active,inactive',
        ]);

        $resource->update($formData);
        return sendSuccess('Resource updated successfully', compact('resource'));
    }

    public function delete(int $id)
    {
        $resource = $this->resourceModel::findOrFail($id);
        $resource->delete();
        return sendSuccess('Resource deleted successfully');
    }

    public function updateQuantity($request, int $id)
    {
        $formData = $request->validate([
            'quantity' => 'required|integer',
            'action'   => 'required|in:add,deduct',
        ]);

        $resource = $this->resourceModel::findOrFail($id);

        $quantity = abs($formData['quantity']);

        if ($formData['action'] === 'deduct') {
            if (! $resource->hasSufficientQuantity($quantity)) {
                throw new Exception("Insufficient quantity. Available: {$resource->total_quantity}");
            }
            $resource->deductQuantity($quantity);
        } else {
            $resource->addQuantity($quantity);
        }

        $resource->refresh();
        return sendSuccess("Resource quantity updated successfully", compact('resource'));

    }
}
