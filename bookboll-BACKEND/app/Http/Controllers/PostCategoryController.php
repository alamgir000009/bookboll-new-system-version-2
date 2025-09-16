<?php
namespace App\Http\Controllers;

use App\Models\Post;
use App\Models\PostCategory;
use Illuminate\Http\Request;

class PostCategoryController extends Controller
{
    public function index()
    {
        $categories = PostCategory::all();
        return sendSuccess("Category saved successfully", ["categories" => $categories]);
    }

    public function store(Request $request)
    {
        $formData = $request->validate([
            "name"        => "required|max:255",
            "slug"        => "required|max:255|unique:post_categories",
            "description" => "nullable|max:500",
        ]);

        $postCategory = PostCategory::create($formData);
        return sendSuccess("Category saved successfully", ["category" => $postCategory]);
    }

    public function destroy(PostCategory $postCategory)
    {
        $postCategory->delete();
        return sendSuccess('Category deleted successfully');
    }
}
