<?php

namespace App\Http\Controllers;

use App\Models\SportsCategory;
use App\Services\SportsCategoryService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

class SportsCategoryController extends Controller
{
    public function __construct(protected SportsCategoryService $sportsCategoryService){}

    public function index()
    {
        return $this->sportsCategoryService->all();
    }

    public function store(Request $request)
    {
        return $this->sportsCategoryService->create($request);
    }

    public function update(Request $request, $id)
    {
        return $this->sportsCategoryService->update($request, $id);
    }

    public function destroy($id)
    {
        return $this->sportsCategoryService->delete($id);
    }

    public function popularEmojis()
    {
        return sendSuccess('Popular emojis retrieved successfully', SportsCategory::getPopularEmojis());
    }

    public function sportsCategories()
    {
        return $this->sportsCategoryService->sportsCategories();
    }

    public function sort(Request $request)
    {
        return $this->sportsCategoryService->updateSortOrder($request);
    }
}
