<?php
namespace App\Services;

use App\Models\SportsCategory;
use Exception;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;

class SportCategoryService
{
    public function __construct(protected SportsCategory $sportCategoryModel)
    {}

    public function all()
    {
        $categories = $this->sportCategoryModel::orderBy('name')->get();
        return sendSuccess('Sports categories retrieved successfully', compact('categories'));
    }

    public function create($request)
    {
        $formData = $request->validate([
            'name'        => 'required|string|max:255|unique:sports_categories,name',
            'description' => 'nullable|string|max:500',
            'icon'        => 'nullable|string|max:10',
            'type'        => 'required|string|max:100',
            'image'       => 'nullable|image',
            'status'      => 'required|in:active,inactive',
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
                'image'       => $image,
                'icon'        => $icon,
                'name'        => $formData['name'],
                'type'        => $formData['type'],
                'status'      => $formData['status'],
                'description' => $formData['description'],
            ]);

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
            'name'        => 'required|string|max:255|unique:sports_categories,name,' . $id,
            'description' => 'nullable|string|max:500',
            'icon'        => 'nullable|string|max:10',
            'type'        => 'required|string|max:100',
            'image'       => 'nullable|image',
            'status'      => 'required|in:active,inactive',
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
                $icon  = $category->icon;
            }

            $data = [
                'icon'        => $icon,
                'name'        => $formData['name'],
                'type'        => $formData['type'],
                'status'      => $formData['status'],
                'description' => $formData['description'],
            ];

            if ($image) {
                $data['image'] = $image;
            }

            $category->update($data);

            return sendSuccess('Sports category updated successfully', compact('category'));
        } catch (Exception $e) {
            Log::error('Failed to update sports category: ' . $e->getMessage());
            return sendError('Failed to update sports category: ' . $e->getMessage());
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
            return sendError('Failed to delete sports category: ' . $e->getMessage());
        }
    }

    public function sportsCategories()
    {
        $sportsCategories = $this->sportCategoryModel::active()->orderBy('name')->get();
        return sendSuccess('Sports categories retrieved successfully', compact('sportsCategories'));
    }
}
