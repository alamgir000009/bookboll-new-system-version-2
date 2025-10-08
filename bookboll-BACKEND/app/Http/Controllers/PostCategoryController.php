<?php
namespace App\Http\Controllers;

use App\Models\Post;
use App\Models\PostCategory;
use App\Services\PostCategoryService;
use Illuminate\Http\Request;

class PostCategoryController extends Controller
{
    public function __construct(protected PostCategoryService $postCategoryService){}

    public function index()
    {
        return $this->postCategoryService->all();
    }

    public function store(Request $request)
    {
        return $this->postCategoryService->create($request);
    }

    public function update(Request $request, $id)
    {
        return $this->postCategoryService->update($request, $id);
    }

    public function destroy($id)
    {
        return $this->postCategoryService->delete($id);
    }

    public function blogCategories()
    {
        return $this->postCategoryService->blogCategories();
    }
}
