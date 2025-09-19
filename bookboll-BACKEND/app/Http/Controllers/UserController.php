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

    public function requiredDataForUser()
    {
        return $this->userService->requiredDataForUser();
    }

    public function store(Request $request)
    {
        return $this->userService->createUser($request);
    }
}
