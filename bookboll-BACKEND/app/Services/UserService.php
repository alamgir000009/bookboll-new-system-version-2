<?php
namespace App\Services;

use App\Mail\UserWelcomeMail;
use App\Models\User;
use App\Models\Venue;
use Exception;
use Illuminate\Support\Facades\Auth;
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

    public function updateProfile($request, $id)
    {
        $user = $this->findById($id);

        if (! $user) {
            return sendError("User not found");
        }

        if (Auth::id() !== $user->id && ! Auth::user()->hasRole(['super_admin', 'admin'])) {
            return sendError("Unauthorized to update this profile");
        }

        $formData = $request->validate([
            'name'             => 'sometimes|string|max:100',
            'email'            => 'sometimes|email|unique:users,email,' . $user->id,
            'password'         => 'sometimes|min:8|confirmed',
            'current_password' => 'required_with:password|current_password',
        ]);

        try {
            DB::beginTransaction();

            $updateData = [];

            if (isset($formData['name'])) {
                $updateData['name'] = $formData['name'];
            }

            if (isset($formData['email'])) {
                $updateData['email'] = $formData['email'];
            }

            if (isset($formData['password'])) {
                $updateData['password'] = $formData['password'];
            }

            if (! empty($updateData)) {
                $user->update($updateData);
            }

            DB::commit();

            $user->refresh();
            $user->load(['roles', 'venues']);
            $user->makeHidden(['roles']);
            $roles = $user->getRoleNames();

            return sendSuccess("Profile updated successfully", compact('user', 'roles'));

        } catch (Exception $e) {
            DB::rollBack();
            return sendError("Something went wrong: " . $e->getMessage());
        }
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

    public function list($request)
    {
        $search = $request->search ?? null;
        $users  = $this->userModel->latest()
            ->when($search, function ($query, $search) {
                $query->where("name", "LIKE", "%{$search}%")
                    ->orWhere("email", "LIKE", "%{$search}%")
                    ->orWhere("role", "LIKE", "%{$search}%")
                    ->orWhere("status", "LIKE", "%{$search}%")
                    ->orWhere("created_at", "LIKE", "%{$search}%");
            })->get();

        return sendSuccess("Users fetched successfully", compact('users', 'search'));
    }

    public function createUser($request)
    {
        $formData = $request->validate([
            'name'     => 'required|string|max:100',
            'email'    => 'required|email|unique:users,email',
            'role'     => 'required|numeric|exists:roles,id',
            'status'   => 'required|in:' . $this->userModel::ACTIVE . ',' . $this->userModel::INACTIVE,
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

            $user = $this->userModel->create([
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

    public function findById($id)
    {
        return $this->userModel->findOrFail($id);
    }

    public function updateUser($request, $id)
    {
        $user = $this->findById($id);

        if (! $user) {
            return sendError("User not found");
        }

        $updatableFields = [
            'name'     => 'sometimes|string|max:100',
            'email'    => 'sometimes|email|unique:users,email,' . $user->id,
            'role'     => 'sometimes|numeric|exists:roles,id',
            'status'   => 'sometimes|in:' . $this->userModel::ACTIVE . ',' . $this->userModel::INACTIVE,
            'password' => 'sometimes|min:5',
            'venues'   => 'sometimes|nullable|array',
            'venues.*' => 'numeric|exists:venues,id',
        ];

        $formData = $request->validate($updatableFields);

        try {

            DB::beginTransaction();

            $updateData = array_filter([
                'name'   => $formData['name'] ?? $user->name,
                'email'  => $formData['email'] ?? $user->email,
                'status' => $formData['status'] ?? $user->status,
            ], function ($value) {
                return $value != null;
            });

            if (isset($formData['password'])) {
                $updateData['password'] = $formData['password'];
            }

            $user->update($updateData);

            if (isset($formData['role'])) {
                $role = Role::find($formData['role']);

                if (! $role) {
                    throw new Exception("Role not found");
                }

                $user->syncRoles([]);
                $user->assignRole($role->name);
            }

            if (isset($formData['venues'])) {
                $isSuperAdminOrAdmin = in_array($formData['role'] ?? $user->roles->first()->id ?? 0, [1, 2]);

                if ($isSuperAdminOrAdmin) {
                    $allVenues          = Venue::pluck('id')->toArray();
                    $formData['venues'] = $allVenues;
                }

                $user->venues()->sync($formData['venues'] ?? []);
            }

            DB::commit();

            $user->load(['roles', 'venues']);
            $user->makeHidden(['roles']);
            $roles = $user->getRoleNames();

            return sendSuccess("User updated successfully", compact('user', 'roles'));

        } catch (Exception $e) {
            DB::rollBack();
            return sendError("Something went wrong: " . $e->getMessage());
        }
    }

    public function deleteUser($id)
    {
        $user = $this->findById($id);
        if ($user->id == auth()->id() || $user->id == 1) {
            return sendError("This user cannot be deleted");
        } elseif ($user) {
            $user->delete();
        }
        return sendSuccess('User deleted successfully');
    }

}
