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

    public function update(Request $request, $id)
    {
        return $this->sportCategoryService->update($request, $id);
    }

    public function destroy($id)
    {
        return $this->sportCategoryService->delete($id);
    }

    public function popularEmojis()
    {
        return sendSuccess('Popular emojis retrieved successfully', SportsCategory::getPopularEmojis());
    }

    public function sportsCategories()
    {
        return $this->sportCategoryService->sportsCategories();
    }

    public function sort(Request $request)
    {
        return $this->sportCategoryService->updateSortOrder($request);
    }
}
