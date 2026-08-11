<?php

namespace App\Http\Controllers;

use App\Services\RolePermissionService;
use Illuminate\Http\Request;

class RolePermissionController extends Controller
{
    public function __construct(protected RolePermissionService $rolePermissionService) {}

    public function catalog()
    {
        return $this->rolePermissionService->catalog();
    }

    public function index(Request $request)
    {
        return $this->rolePermissionService->index($request);
    }

    public function show(string $role)
    {
        return $this->rolePermissionService->show($role);
    }

    public function store(Request $request)
    {
        return $this->rolePermissionService->store($request);
    }

    public function updatePermissions(Request $request, string $role)
    {
        return $this->rolePermissionService->updatePermissions($request, $role);
    }

    public function destroy(string $role)
    {
        return $this->rolePermissionService->destroy($role);
    }
}
