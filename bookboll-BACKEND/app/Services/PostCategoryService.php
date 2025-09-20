<?php
namespace App\Services;

use App\Models\PostCategory;

class PostCategoryService
{
    public function __construct(protected PostCategory $postCategoryModel)
    {}

    public function all()
    {
        $categories = $this->postCategoryModel::all();
        return sendSuccess("Categories fetched successfully", ["categories" => $categories]);
    }

    public function create($request)
    {
        $formData = $request->validate([
            "name"        => "required|max:255",
            "slug"        => "required|max:255|unique:post_categories",
            "description" => "nullable|max:500",
        ]);

        $category = $this->postCategoryModel::create($formData);
        return sendSuccess("Category added successfully", compact('category'));
    }

    public function delete($id)
    {
        $postCategory = $this->postCategoryModel->findOrFail($id);
        $postCategory->delete();
        return sendSuccess('Category deleted successfully');
    }
}
