<?php

namespace App\Http\Controllers;

use App\Models\SportsCategory;
use App\Services\SportCategoryService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

class SportsCategoryController extends Controller
{
    public function __construct(protected SportCategoryService $sportCategoryService){}
    public function index()
    {
        return $this->sportCategoryService->all();
    }

    public function store(Request $request)
    {
        return $this->sportCategoryService->create($request);
    }

    /**
     * Update sports category (PUT /api/sports-categories/{id})
     */
    public function update(Request $request, $id)
    {
        try {
            $category = SportsCategory::findOrFail($id);

            $validated = $request->validate([
                'name' => 'required|string|max:255|unique:sports_categories,name,' . $id,
                'description' => 'nullable|string|max:1000',
                'icon' => 'nullable|string|max:10',
                'custom_icon' => 'nullable|file|mimes:png,jpg,svg|max:1024|dimensions:min_width=64,min_height=64',
                'status' => 'required|in:active,inactive',
            ]);

            // Handle icon update
            $icon = $validated['icon'] ?? $category->icon;

            if ($request->hasFile('custom_icon')) {
                // Delete old icon if it was a file
                if (str_starts_with($category->icon, 'public/icons/sports/')) {
                    Storage::delete($category->icon);
                }

                $file = $request->file('custom_icon');
                $filename = Str::slug($validated['name']) . '.' . $file->getClientOriginalExtension();
                $path = $file->storeAs('public/icons/sports', $filename);
                $icon = $path;
            }

            $category->update([
                'name' => $validated['name'],
                'description' => $validated['description'],
                'icon' => $icon,
                'status' => $validated['status'],
            ]);

            return sendSuccess('Sports category updated successfully', $category->load('icon_url'));
        } catch (\Exception $e) {
            Log::error('Failed to update sports category: ' . $e->getMessage());
            return sendError('Failed to update sports category: ' . $e->getMessage());
        }
    }

    public function destroy($id)
    {
        return $this->sportCategoryService->delete($id);
    }

    public function popularEmojis()
    {
        return sendSuccess('Popular emojis retrieved successfully', SportsCategory::getPopularEmojis());
    }
}
