<?php
namespace App\Services;

use App\Mail\UserWelcomeMail;
use App\Models\User;
use App\Models\Venue;
use Exception;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Spatie\Permission\Models\Role;

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

    public function loginUser()
    {
        $user     = auth()->user();
        $roles    = $user->getRoleNames();
        $response = [
            'user'  => $user->setHidden(['roles']),
            'roles' => $roles,
        ];

        return sendSuccess("Logged in user fetched susccessfully", $response);
    }

    public function requiredDataForUser()
    {
        $roles = Role::select(['id', 'name'])
            ->get()
            ->map(function ($role) {
                \Log::info("Original role name: " . $role->name);
                $transformedName = ucwords(str_replace('_', ' ', $role->name));
                \Log::info("Transformed role name: " . $transformedName);

                return (object) [
                    'id'   => $role->id,
                    'name' => $transformedName,
                ];
            });

        $venues = Venue::select(['id', 'name'])->get();

        $response = [
            'roles'  => $roles,
            'venues' => $venues,
        ];

        return sendSuccess("User data fetched successfully", $response);
    }

    public function createUser($request)
    {
        $formData = $request->validate([
            'name'     => 'required|string|max:100',
            'email'    => 'required|email|unique:users,email',
            'role'     => 'required|numeric|exists:roles,id',
            'status'   => 'required|in:' . User::ACTIVE . ',' . User::INACTIVE,
            'password' => 'required|min:8',
            'venues'   => 'nullable|array',
            'venues.*' => 'numeric|exists:venues,id',
        ]);

        try {

            DB::beginTransaction();

            $isSuperAdminOrAdmin = in_array($formData['role'], [1, 2]);

            if ($isSuperAdminOrAdmin) {
                $allVenues          = Venue::pluck('id')->toArray();
                $formData['venues'] = $allVenues;
            }

            $user = User::create([
                'name'     => $formData['name'],
                'email'    => $formData['email'],
                'status'   => $formData['status'],
                'password' => $formData['password'],
            ]);

            $role = Role::find($formData['role']);

            if (! $role) {
                throw new Exception("Role not found");
            }

            $user->assignRole($role['name']);

            if (! empty($formData['venues'])) {
                $user->venues()->sync($formData['venues']);
            }

            DB::commit();

            Mail::to($user->email)->queue(new UserWelcomeMail($user, $formData['password']));

            $user->load(['roles', 'venues']);
            $user->makeHidden('roles');
            $roles = $user->getRoleNames();

            return sendSuccess("User created successfully", compact('user', 'roles'));

        } catch (Exception $e) {
            DB::rollBack();
            return sendError("Something went wrong " . $e->getMessage());
        }
    }

}
