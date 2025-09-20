<?php
namespace App\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Services\ListingService;
use Illuminate\Http\Request;

class ListingController extends Controller
{
    public function __construct(protected ListingService $listingService)
    {}

    public function index(Request $request)
    {
        return $this->listingService->all($request);
    }

    public function store(Request $request)
    {
        return $this->listingService->create($request);
    }

    public function update(Request $request, $id)
    {
        return $this->listingService->update($request, $id);
    }

    public function destroy($id)
    {
        return $this->listingService->delete($id);
    }

}
