<?php
namespace App\Http\Controllers;

use App\Services\ResourceService;
use Illuminate\Http\Request;

class ResourceController extends Controller
{
    public function __construct(protected ResourceService $resourceService)
    {}

    public function index(Request $request)
    {
        return $this->resourceService->all($request);
    }

    public function store(Request $request)
    {
        return $this->resourceService->create($request);
    }

    public function update(Request $request, $id)
    {
        return $this->resourceService->update($request, $id);
    }

    public function destroy($id)
    {
        return $this->resourceService->delete($id);
    }
}
