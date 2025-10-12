<?php
namespace App\Http\Controllers;

use App\Services\UserService;
use Illuminate\Http\Request;

class UserController extends Controller
{
    public function __construct(protected UserService $userService)
    {}

    public function register(Request $request)
    {
        return $this->userService->register($request);
    }

    public function updateProfile(Request $request)
    {
        return $this->userService->updateProfile($request, auth()->id());
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

    public function updateCustomerProfile(Request $request)
    {
        return $this->userService->updateCustomerProfile($request, auth()->id());
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

    public function sendResetLinkEmail(Request $request)
    {
        return $this->userService->forgotPassword($request);
    }

    public function reset(Request $request)
    {
        return $this->userService->resetPassword($request);
    }

    public function changePassword(Request $request)
    {
        return $this->userService->changePassword($request, auth()->id());
    }

    public function customerLogin(Request $request)
    {
        return $this->userService->customerLogin($request);
    }

    public function customerPreferences()
    {
        return $this->userService->getPreferences();
    }

    public function updatePreferences(Request $request)
    {
        return $this->userService->updatePreferences($request);
    }

    public function updateProfileImage(Request $request)
    {

        return $this->userService->updateProfileImage($request);
    }
}
