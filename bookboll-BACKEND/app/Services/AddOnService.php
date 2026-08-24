<?php
namespace App\Services;

use App\Models\AddOn;
use Exception;
use Illuminate\Support\Facades\Log;

class AddOnService
{
    public function __construct(protected AddOn $addOnModel)
    {}

    public function all($request)
    {
        $search = $request->search ?? null;

        $addOns = $this->addOnModel::orderBy('sort_order', 'ASC')
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
            'image'           => 'nullable|string|max:2048',
        ]);

        $formData['sort_order'] = ($this->addOnModel->max('sort_order') ?? 0) + 1;

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
            'status'          => 'required|in:active,inactive',
            'image'           => 'nullable',
        ]);

        if ($request->hasFile('image')) {
            $formData['image'] = $request->file('image')->store('addons', 'public');
        } elseif ($request->filled('image') && is_string($request->input('image'))) {
            $formData['image'] = $request->input('image');
        } else {
            unset($formData['image']);
        }

        $addOn->update($formData);

        return sendSuccess('Add-On updated successfully', compact('addOn'));
    }

    public function delete($id)
    {
        $addOn = $this->addOnModel->findOrFail($id);
        $addOn->delete();
        return sendSuccess('Add-On deleted successfully');
    }

    public function updateSortingOrder($request)
    {
        $request->validate([
            'order'   => 'required|array',
            'order.*' => 'exists:add_ons,id',
        ]);

        try {

            foreach ($request->order as $index => $id) {
                AddOn::where('id', $id)->update(['sort_order' => $index]);
            }

            return sendSuccess('Order updated successfully');
        } catch (Exception $e) {
            Log::error('Failed to update AddOn sort order: ' . $e->getMessage(), ['Exception' => $e]);
            return sendError('Failed to update order');
        }
    }
}
