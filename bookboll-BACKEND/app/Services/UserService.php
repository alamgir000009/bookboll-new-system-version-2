<?php
namespace App\Services;

use App\Models\User;
use Exception;

class UserService
{
    public function __construct(protected User $userModel)
    {
    }
    public function login($request)
    {
        try {

            $user = $this->userModel->where("email", $request->email)->first();

            if (! $user || ! password_verify($request->password, $user->password)) {
                return sendError('Invalid credentials');
            }

            $token = $user->createToken('bookboll-api-token')->plainTextToken;
            $roles = $user->getRoleNames();

            $response = [
                'token' => $token,
                'user'  => $user->setHidden(['roles']),
                'roles' => $roles,
            ];

            return sendSuccess('Login successful', $response);
        } catch (Exception $e) {
            return sendError('Something went wrong during login');
        }
    }

    public function logout()
    {
        $user = auth()->user();

        if ($user && $user->currentAccessToken()) {
            $user->currentAccessToken()->delete();
        }

        return sendSuccess("Logout successfully");
    }

}
