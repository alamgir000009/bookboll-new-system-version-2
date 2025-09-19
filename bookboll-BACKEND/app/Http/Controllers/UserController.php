<?php
namespace App\Http\Controllers;

use App\Models\User;
use App\Models\Venue;
use App\Services\UserService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Spatie\Permission\Models\Role;

class UserController extends Controller
{
    public function __construct(protected UserService $userService)
    {

    }
    public function login(Request $request)
    {
        return $this->userService->login($request);
    }

    public function logout()
    {
        return $this->userService->logout();
    }

    public function me()
    {
        return $this->userService->loginUser();
    }

    public function updateProfile(Request $request)
    {
        return $this->userService->updateProfile($request, auth()->id());
    }

    public function requiredDataForUser()
    {
        return $this->userService->requiredDataForUser();
    }

    public function index(Request $request)
    {
        return $this->userService->list($request);
    }

    public function store(Request $request)
    {
        return $this->userService->createUser($request);
    }

    public function update(Request $request, $id)
    {
        return $this->userService->updateUser($request, $id);
    }

    public function destroy($id)
    {
        return $this->userService->deleteUser($id);
    }
}
