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

    public function googleLogin(Request $request)
    {
        return $this->userService->loginWithGoogle($request);
    }

    public function appleLogin(Request $request)
    {
        return $this->userService->loginWithApple($request);
    }

    public function logout()
    {
        return $this->userService->logout();
    }

    public function me()
    {
        return $this->userService->loginUser();
    }

    public function getCustomerProfile()
    {
        return $this->userService->getCustomerProfile();
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

    public function customerForgotPassword(Request $request)
    {
        return $this->userService->customerForgotPassword($request);
    }

    public function customerResetPassword(Request $request)
    {
        return $this->userService->customerResetPassword($request);
    }

    public function subscribeOneSignal(Request $request)
    {
        return $this->userService->subscribeOneSignal($request);
    }

    public function registerAdminAppUser(Request $request)
    {
        return $this->userService->registerAdminAppUser($request);
    }

    public function adminAppForgotPassword(Request $request)
    {
        return $this->userService->adminAppForgotPassword($request);
    }

    public function adminAppResetPassword(Request $request)
    {
        return $this->userService->adminAppResetPassword($request);
    }

    public function deleteAdminAppAccount(Request $request)
    {
        return $this->userService->deleteAdminAppAccount($request);
    }

    public function deleteCustomerAccount(Request $request)
    {
        return $this->userService->deleteCustomerAccount($request);
    }

    public function listPendingUsers(Request $request)
    {
        return $this->userService->listPendingUsers($request);
    }

    public function approveUser(Request $request, $id)
    {
        return $this->userService->approveUser($request, $id);
    }

    public function rejectUser(Request $request, $id)
    {
        return $this->userService->rejectUser($request, $id);
    }
}
