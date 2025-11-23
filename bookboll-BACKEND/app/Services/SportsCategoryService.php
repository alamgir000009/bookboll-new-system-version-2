<?php
namespace App\Services;

use App\Models\SportsCategory;
use App\Models\SportsType;
use Exception;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;

class SportsCategoryService
{
    public function __construct(protected SportsCategory $sportCategoryModel, protected SportsType $sportsTypeModel)
    {}

    public function all()
    {
        $types      = $this->sportsTypeModel::orderBy('sort_order', 'ASC')->get();
        $categories = $this->sportCategoryModel::with(['sportsType:id,title,sub_title'])->orderBy('sort_order', 'ASC')->get();
        return sendSuccess('Sports categories retrieved successfully', compact('types', 'categories'));
    }

    public function create($request)
    {
        $formData = $request->validate([
            'name'           => 'required|string|max:255|unique:sports_categories,name',
            'description'    => 'nullable|string|max:500',
            'icon'           => 'nullable|string|max:10',
            'sports_type_id' => 'required|numeric|exists:sports_types,id',
            'image'          => 'nullable|image',
            'status'         => 'required|in:active,inactive',
        ]);

        try {
            $newIcon = $formData['icon'] ?? null;

            if ($request->hasFile('image')) {
                $file  = $request->file('image');
                $image = $file->store('sport-categories/icons', 'public');
                $icon  = null;
            } else {
                $icon  = $newIcon;
                $image = null;
            }

            $category = SportsCategory::create([
                'icon'           => $icon,
                'image'          => $image,
                'name'           => $formData['name'],
                'status'         => $formData['status'],
                'description'    => $formData['description'],
                'sports_type_id' => $formData['sports_type_id'],
                'sort_order'     => $this->sportCategoryModel->max('id') + 1,
            ]);

            $category->load('sportsType');

            return sendSuccess('Sports category created successfully', compact('category'));
        } catch (Exception $e) {
            Log::error('Failed to create sports category: ' . $e->getMessage());
            return sendError('Failed to create sports category: ' . $e->getMessage());
        }
    }

    public function update($request, $id)
    {
        $category = SportsCategory::findOrFail($id);

        $formData = $request->validate([
            'name'           => 'required|string|max:255|unique:sports_categories,name,' . $id,
            'description'    => 'nullable|string|max:500',
            'icon'           => 'nullable|string|max:10',
            'sports_type_id' => 'required|numeric|exists:sports_types,id',
            'image'          => 'nullable|image',
            'status'         => 'required|in:active,inactive',
        ]);

        try {
            $newIcon = $formData['icon'] ?? null;
            $image   = null;

            if ($request->hasFile('image')) {
                if ($category->image && str_starts_with($category->image, 'sport-categories/icons/')) {
                    Storage::delete($category->image);
                }
                $file  = $request->file('image');
                $image = $file->store('sport-categories/icons', 'public');
                $icon  = null;
            } else if ($newIcon !== null && $newIcon !== $category->icon) {
                if ($category->image && str_starts_with($category->image, 'sport-categories/icons/')) {
                    Storage::delete($category->image);
                }
                $image = null;
                $icon  = $newIcon;
            } else {
                $icon = $category->icon;
            }

            $data = [
                'icon'           => $icon,
                'name'           => $formData['name'],
                'status'         => $formData['status'],
                'description'    => $formData['description'],
                'sports_type_id' => $formData['sports_type_id'],
            ];

            if ($image) {
                $data['image'] = $image;
            }

            $category->update($data);
            $category->load('sportsType');

            return sendSuccess('Sports category updated successfully', compact('category'));
        } catch (Exception $e) {
            Log::error('Failed to update sports category: ' . $e->getMessage());
            return sendError('Failed to update sports category.');
        }
    }

    public function delete($id)
    {
        try {
            $category = $this->sportCategoryModel::findOrFail($id);

            if (str_starts_with($category->icon, 'sport-categories/icons/')) {
                Storage::delete($category->icon);
            }

            if (str_starts_with($category->image, 'sport-categories/images/')) {
                Storage::delete($category->icon);
            }

            $category->delete();
            return sendSuccess('Sports category deleted successfully');

        } catch (Exception $e) {
            Log::error('Failed to delete sports category: ' . $e->getMessage());
            return sendError('Failed to delete sports category');
        }
    }

    public function sportsCategories()
    {
        $sports_type = request()->get('sports_type', null);

        $sportsTypes = $this->sportsTypeModel::with(['sportsCategories:id,name,sports_type_id'])
            ->orderBy('sort_order', 'ASC')
            ->get(['id', 'icon', 'color', 'title', 'sub_title']);

        $sportsCategories = $this->sportCategoryModel::with('sportsType:id,title,sub_title')->active()
            ->when($sports_type, function ($query, $sports_type) {
                $query->whereRelation('sportsType', 'title', $sports_type);
            })
            ->orderBy('sort_order', 'ASC')
            ->get();

        return sendSuccess('Sports categories retrieved successfully', compact('sportsTypes', 'sportsCategories'));
    }

    public function updateSortOrder($request)
    {
        $request->validate([
            'updates'              => 'required|array|min:1',
            'updates.*.id'         => 'required|integer|exists:sports_categories,id',
            'updates.*.sort_order' => 'required|integer|min:1',
        ]);

        $updates     = $request->input('updates');
        $categoryIds = collect($updates)->pluck('id')->toArray();

        $categories = $this->sportCategoryModel::whereIn('id', $categoryIds)->get(['id', 'sports_type_id']);

        if ($categories->isEmpty()) {
            return sendError('No categories found.');
        }

        $sportsTypeId = $categories->first()->sports_type_id;
        if ($categories->pluck('sports_type_id')->unique()->count() > 1) {
            return sendError('Categories must belong to the same sports type.');
        }

        $sortOrders = collect($updates)->pluck('sort_order')->sort()->values()->toArray();
        if (count(array_unique($sortOrders)) !== count($sortOrders) || $sortOrders[0] !== 1 || end($sortOrders) !== count($sortOrders)) {
            return sendError('Sort orders must be unique, sequential integers starting from 1.');
        }

        DB::beginTransaction();

        try {

            foreach ($updates as $update) {
                $this->sportCategoryModel::where('id', $update['id'])
                    ->where('sports_type_id', $sportsTypeId)
                    ->update(['sort_order' => $update['sort_order']]);
            }

            DB::commit();

            return sendSuccess('Sort order updated successfully.', [
                'data' => ['updated_categories' => $categoryIds],
            ]);
        } catch (Exception $e) {
            DB::rollBack();
            Log::error('Failed to update sort order: ' . $e->getMessage());
            return sendError('Failed to update sort order.');
        }
    }
}
