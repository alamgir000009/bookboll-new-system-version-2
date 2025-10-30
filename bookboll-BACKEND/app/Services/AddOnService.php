<?php
namespace App\Services;

use App\Models\AddOn;
use Exception;

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
            'status'          => 'required|in:active,inactive',
            'image'           => 'exclude_if:image,null|image|mimes:jpg,jpeg,png,webp|max:2048',
        ]);

        if ($request->hasFile('image')) {
            $formData['image'] = $request->file('image')->store('addons', 'public');
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

    public function reorder($request)
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
            return sendError('Failed to update order');
        }
    }
}
