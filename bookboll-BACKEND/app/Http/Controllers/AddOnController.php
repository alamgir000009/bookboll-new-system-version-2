<?php

namespace App\Http\Controllers;

use App\Models\AddOn;
use Illuminate\Http\Request;
use App\Services\AddOnService;

class AddOnController extends Controller
{

    public function __construct(protected AddOnService $addOnService){}

    public function index(Request $request)
    {
        return $this->addOnService->all($request);
    }

    public function store(Request $request)
    {
        return $this->addOnService->create($request);
    }

    public function update(Request $request, $id)
    {
        return $this->addOnService->update($request, $id);
    }

    public function destroy($id)
    {
        return $this->addOnService->delete($id);
    }
}
