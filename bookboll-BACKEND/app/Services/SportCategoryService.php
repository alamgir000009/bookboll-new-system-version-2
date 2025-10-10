<?php
namespace App\Services;

use App\Models\SportsCategory;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

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
        $validated = $request->validate([
            'name'        => 'required|string|max:255|unique:sports_categories,name',
            'description' => 'nullable|string|max:1000',
            'icon'        => 'nullable|string|max:10',
            'image'       => 'nullable|image|max:4096',
            'custom_icon' => 'nullable|file|mimes:png,jpg,svg|max:1024|dimensions:min_width=64,min_height=64',
            'status'      => 'required|in:active,inactive',
        ]);

        try {

            $icon  = $validated['icon'] ?? '';
            $image = $validated['image'] ?? '';

            if ($request->hasFile('custom_icon')) {
                $file     = $request->file('custom_icon');
                $filename = Str::slug($validated['name']) . '.' . $file->getClientOriginalExtension();
                // $icon     = $file->storeAs('public/icons/sports', $filename);
                $icon     = $file->store('public/icons/sports', 'public');
            }

            if ($request->hasFile('image')) {
                $file     = $request->file('image');
                $filename = Str::slug($validated['name']) . '.' . $file->getClientOriginalExtension();
                // $image    = $file->storeAs('public/images/sports', $filename);
                $image     = $file->store('public/images/sports', 'public');
            }

            $category = SportsCategory::create([
                'icon'        => !$image ? $icon : null,
                'image'       => $image,
                'name'        => $validated['name'],
                'status'      => $validated['status'],
                'description' => $validated['description'],
            ]);

            return sendSuccess('Sports category created successfully', compact('category'));
        } catch (\Exception $e) {
            Log::error('Failed to create sports category: ' . $e->getMessage());
            return sendError('Failed to create sports category: ' . $e->getMessage());
        }
    }

    public function update($request, $id)
    {
        $category = SportsCategory::findOrFail($id);

        $validated = $request->validate([
            'name'        => 'required|string|max:255|unique:sports_categories,name,' . $id,
            'description' => 'nullable|string|max:1000',
            'icon'        => 'nullable|string|max:10',
            'custom_icon' => 'nullable|file|mimes:png,jpg,svg|max:1024|dimensions:min_width=64,min_height=64',
            'status'      => 'required|in:active,inactive',
        ]);

        try {

            $icon  = $validated['icon'] ?? $category->icon;
            $image = $validated['image'] ?? $category->image;

            if ($request->hasFile('custom_icon')) {
                if (str_starts_with($category->icon, 'public/icons/sports/')) {
                    Storage::delete($category->icon);
                }

                $file     = $request->file('custom_icon');
                $filename = Str::slug($validated['name']) . '.' . $file->getClientOriginalExtension();
                // $icon     = $file->storeAs('public/icons/sports', $filename);
                $icon     = $file->store('public/icons/sports', 'public');
            }

            if ($request->hasFile('image')) {
                if (str_starts_with($category->icon, 'public/images/sports/')) {
                    Storage::delete($category->icon);
                }

                $file     = $request->file('image');
                $filename = Str::slug($validated['name']) . '.' . $file->getClientOriginalExtension();
                // $image     = $file->storeAs('public/images/sports', $filename);
                $image     = $file->store('public/images/sports', 'public');
            }

            $category->update([
                'icon'        => !$image ? $icon : null,
                'image'       => $image,
                'name'        => $validated['name'],
                'status'      => $validated['status'],
                'description' => $validated['description'],
            ]);

            return sendSuccess('Sports category updated successfully', compact('category'));
        } catch (\Exception $e) {
            Log::error('Failed to update sports category: ' . $e->getMessage());
            return sendError('Failed to update sports category: ' . $e->getMessage());
        }
    }

    public function delete($id)
    {
        try {
            $category = $this->sportCategoryModel::findOrFail($id);

            if (str_starts_with($category->icon, 'public/icons/sports/')) {
                Storage::delete($category->icon);
            }

            if (str_starts_with($category->image, 'public/images/sports/')) {
                Storage::delete($category->icon);
            }

            $category->delete();
            return sendSuccess('Sports category deleted successfully');

        } catch (\Exception $e) {
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
