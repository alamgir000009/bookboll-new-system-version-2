<?php
namespace App\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Models\SportsType;
use Exception;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

class SportsTypeController extends Controller
{
    public function __construct(protected SportsType $sportsTypeModel)
    {}

    public function store(Request $request)
    {
        $formData = $request->validate([
            'icon'      => 'nullable|string',
            'color'     => 'required|string',
            'sub_title' => 'required|max:255',
            'title'     => 'required|max:255|unique:sports_types',
            'image'     => 'nullable|image',
        ]);

        try {
            $newIcon = $formData['icon'] ?? null;

            if ($request->hasFile('image')) {
                $file  = $request->file('image');
                $image = $file->store('sport-types/icons', 'public');
                $icon  = null;
            } else {
                $icon  = $newIcon;
                $image = null;
            }

            $type = $this->sportsTypeModel::create([
                'icon'      => $icon,
                'image'     => $image,
                'color'     => $formData['color'],
                'sub_title' => $formData['sub_title'],
                'title'     => $formData['title'],
                'slug'      => Str::slug($formData['title']),
            ]);

            return sendSuccess('Sports type added successfully', compact('type'), 201);
        } catch (Exception $e) {
            Log::error('Failed to create sports type: ' . $e->getMessage(), ["Exception" => $e]);
            return sendError('Failed to create sports type: ' . $e->getMessage());
        }
    }

    public function update(Request $request, $id)
    {
        $type = $this->sportsTypeModel::findOrFail($id);

        $formData = $request->validate([
            'icon'      => 'nullable|string',
            'color'     => 'required|string',
            'sub_title' => 'required|max:255',
            'title'     => 'required|string|max:255|unique:sports_types,title,' . $id,
            'image'     => 'nullable|image',
        ]);

        try {
            $newIcon = $formData['icon'] ?? null;
            $image   = null;

            if ($request->hasFile('image')) {
                if ($type->image && str_starts_with($type->image, 'sport-types/icons/')) {
                    Storage::delete($type->image);
                }
                $file  = $request->file('image');
                $image = $file->store('sport-types/icons', 'public');
                $icon  = null;
            } else if ($newIcon !== null && $newIcon !== $type->icon) {
                if ($type->image && str_starts_with($type->image, 'sport-types/icons/')) {
                    Storage::delete($type->image);
                }
                $image = null;
                $icon  = $newIcon;
            } else {
                $icon = $type->icon;
            }

            $data = [
                'icon'      => $icon,
                'color'     => $formData['color'],
                'sub_title' => $formData['sub_title'],
                'title'     => $formData['title'],
                'slug'      => Str::slug($formData['title']),
            ];

            if ($image) {
                $data['image'] = $image;
            }

            $type->update($data);

            return sendSuccess('Sports type updated successfully', compact('type'));
        } catch (Exception $e) {
            Log::error('Failed to update sports type: ' . $e->getMessage(), ["Exception" => $e]);
            return sendError('Failed to update sports type: ' . $e->getMessage());
        }
    }

    public function destroy($id)
    {
        try {
            $type = $this->sportsTypeModel::findOrFail($id);

            if ($type->image && str_starts_with($type->image, 'sport-types/icons/')) {
                Storage::delete($type->image);
            }

            $type->delete();
            return sendSuccess('Sports type deleted successfully');
        } catch (Exception $e) {
            Log::error('Failed to delete sports type: ' . $e->getMessage(), ["Exception" => $e]);
            return sendError('Failed to delete sports type: ' . $e->getMessage());
        }
    }

    public function sort(Request $request)
    {
        $request->validate([
            'updates'              => 'required|array|min:1',
            'updates.*.id'         => 'required|integer|exists:sports_types,id',
            'updates.*.sort_order' => 'required|integer|min:1',
        ]);

        $updates = $request->input('updates');
        $typeIds = collect($updates)->pluck('id')->toArray();

        $sportsTypes = $this->sportsTypeModel::whereIn('id', $typeIds)->get(['id']);

        if ($sportsTypes->isEmpty()) {
            return sendError('No sports types found.');
        }

        if ($sportsTypes->count() !== count($typeIds)) {
            return sendError('Some sports types not found.');
        }

        $sortOrders = collect($updates)->pluck('sort_order')->sort()->values()->toArray();
        if (count(array_unique($sortOrders)) !== count($sortOrders) || $sortOrders[0] !== 1 || end($sortOrders) !== count($sortOrders)) {
            return sendError('Sort orders must be unique, sequential integers starting from 1.');
        }

        DB::beginTransaction();

        try {
            foreach ($updates as $update) {
                $this->sportsTypeModel::where('id', $update['id'])
                    ->update(['sort_order' => $update['sort_order']]);
            }

            DB::commit();

            return sendSuccess('Sort order updated successfully.', [
                'data' => ['updated_sports_types' => $typeIds],
            ]);
        } catch (Exception $e) {
            DB::rollBack();
            Log::error('Failed to update sort order: ' . $e->getMessage(), ["Exception" => $e]);
            return sendError('Failed to update sort order.');
        }
    }
}
