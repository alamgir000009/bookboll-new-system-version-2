<?php
namespace App\Services;

use App\Mail\CustomerForgotPasswordOtpEmail;
use App\Mail\ForgetPasswordEmail;
use App\Mail\UserRejectionEmail;
use App\Mail\UserWelcomeEmail;
use App\Models\LoyaltyPoint;
use App\Models\OnesignalSubscription;
use App\Models\Preference;
use App\Models\RegistrationRequest;
use App\Models\User;
use App\Models\Venue;
use Carbon\Carbon;
use Exception;
use Google_Client;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Maatwebsite\Excel\Facades\Excel;
use MobileApps;
use Spatie\Permission\Models\Role;

class UserService
{
    public function __construct(protected User $userModel)
    {
    }

    public function register($request)
    {
        $formData = $request->validate([
            'name'     => 'required|string|max:100',
            'email'    => 'required|email',
            'phone'    => 'required|min:6|max:20',
            'password' => 'required|min:8|confirmed',
        ]);

        try {
            DB::beginTransaction();

            $user = $this->userModel->where("email", $formData['email'])->first();

            if ($user) {
                if (! $user->hasRole('customer')) {
                    $user->assignRole('customer');

                    // Create default preferences if user doesn't have them
                    if (! $user->preferences) {
                        Preference::create([
                            'user_id'         => $user->id,
                            'notifications'   => [
                                'booking_emails'   => true,
                                'sms_reminders'    => false,
                                'marketing_emails' => true,
                            ],
                            'favorite_sports' => [],
                            'language'        => 'english',
                            'currency'        => 'QAR',
                        ]);
                    }
                } else {
                    return sendError("Customer with this email already exists");
                }

            } else {

                $user = $this->userModel->create([
                    'name'     => $formData['name'],
                    'email'    => $formData['email'],
                    'phone'    => $formData['phone'],
                    'password' => $formData['password'],
                    'status'   => $this->userModel::ACTIVE,
                ]);

                $user->assignRole('customer');

                // Create default preferences for new customer
                if (! $user->preferences) {
                    Preference::create([
                        'user_id'         => $user->id,
                        'notifications'   => [
                            'booking_emails'   => true,
                            'sms_reminders'    => false,
                            'marketing_emails' => true,
                        ],
                        'favorite_sports' => [],
                        'language'        => 'english',
                        'currency'        => 'QAR',
                    ]);
                }
            }

            DB::commit();

            try {
                Mail::to($user->email)->send(new UserWelcomeEmail($user, $formData['password']));
            } catch (Exception $e) {
                Log::error("Failed to send welcome email: " . $e->getMessage(), ["Exception" => $e]);
            }

            $user->load(['roles', 'venues']);
            $user->makeHidden('roles');
            $token = $user->createToken('bookboll-api-token')->plainTextToken;
            $roles = $user->getRoleNames();

            return sendSuccess("User registered successfully", compact('user', 'roles', 'token'));

        } catch (Exception $e) {
            DB::rollBack();
            Log::error("User registration error: " . $e->getMessage(), ["Exception" => $e]);
            return sendError("Something went wrong please try again later");
        }
    }

    public function getCustomerProfile()
    {

        $user = auth()->user();

        $user->load(['roles', 'venues', 'loyaltyTier']);
        $user->makeHidden(['roles']);
        $roles = $user->getRoleNames();

        $response = [
            'roles'           => $roles,
            'assigned_venues' => $user->venues,
            'user'            => $user,
            'loyalty_points'  => LoyaltyPoint::balance($user->id) ?? 0,
            'loyalty_tier'    => [
                'name'           => $user->loyaltyTier->name ?? 'Bronze',
                'slug'           => $user->loyaltyTier->slug ?? 'bronze',
                'progress'       => $user->loyalty_progress ?? 0,
                'description'    => $user->loyaltyTier->description ?? '',
                'points_to_next' => $user->points_to_next_tier ?? 0,
                'next_tier'      => $user->next_tier?->name ?? 'Max Level',
            ],
        ];

        return sendSuccess("Profile fetched successfully", $response);
    }

    public function updateCustomerProfile($request, $id)
    {
        $user = $this->findById($id);

        if (! $user) {
            return sendError("User not found");
        }

        if (Auth::id() !== $user->id && ! Auth::user()->hasRole(['super_admin', 'admin'])) {
            return sendError("Unauthorized to update this profile");
        }

        $formData = $request->validate([
            'name'          => 'sometimes|string|max:100',
            'date_of_birth' => 'nullable|date|before:today',
            'gender'        => 'nullable',
            'nationality'   => 'nullable|string',
            'address'       => 'nullable|max:255',
            'phone'         => 'nullable|max:20',
        ]);

        try {
            DB::beginTransaction();

            $updateData = [];

            if (isset($formData['name'])) {
                $updateData['name'] = $formData['name'];
            }

            if (isset($formData['date_of_birth'])) {
                $updateData['date_of_birth'] = $formData['date_of_birth'];
            }

            if (isset($formData['gender'])) {
                $updateData['gender'] = $formData['gender'];
            }

            if (isset($formData['nationality'])) {
                $updateData['nationality'] = $formData['nationality'];
            }

            if (isset($formData['address'])) {
                $updateData['address'] = $formData['address'];
            }

            if (isset($formData['phone'])) {
                $updateData['phone'] = $formData['phone'];
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

    public function login($request)
    {
        $requestFrom = $request->header('from') ?? null;

        try {

            $user = $this->userModel->where("email", $request->email)->first();

            if (! $user || ! password_verify($request->password, $user->password)) {
                return sendError('Invalid credentials');
            }

            if ($user->status === $this->userModel::PENDING) {
                return sendError('Your account is pending admin approval. Please wait for approval before logging in.');
            }

            if ($requestFrom == MobileApps::ADMIN) {
                $token = $user->createToken('bookboll-admin-mobile-app-api-token')->plainTextToken;
            } else {
                $token = $user->createToken('bookboll-admin-web-api-token')->plainTextToken;
            }

            $roles = $user->getRoleNames();

            $permissions = $user->getAllPermissions()->pluck('name');

            $user->makeHidden(['roles']);

            $response = [
                'user'            => $user,
                'token'           => $token,
                'roles'           => $roles,
                'permissions'     => $permissions,
                'assigned_venues' => $user->venues,
            ];

            return sendSuccess('Login successful', $response);

        } catch (Exception $e) {
            return sendError('Something went wrong during login');
        }
    }

    public function loginWithGoogle($request)
    {
        $request->validate([
            'id_token' => 'required|string',
        ]);

        $client  = new Google_Client(['client_id' => env('GOOGLE_CLIENT_ID')]);
        $payload = $client->verifyIdToken($request->id_token);

        if (! $payload) {
            throw ValidationException::withMessages([
                'id_token' => ['Invalid Google token.'],
            ]);
        }

        try {

            DB::beginTransaction();

            $googleId = $payload['sub'];
            $email    = $payload['email'];
            $name     = $payload['name'];
            $picture  = $payload['picture'] ?? null;

            $user = User::where('provider_id', $googleId)->orWhere('email', $email)->first();

            if ($user && $user->status == User::BLOCKED) {
                return sendError('Your account is block by admin please contact for further details');
            }

            if (! $user) {
                $user = $this->userModel->create([
                    'name'              => $name,
                    'email_verified_at' => now(),
                    'email'             => $email,
                    'image'             => $picture,
                    'provider'          => "google",
                    'provider_id'       => $googleId,
                    'password'          => "bookboll-000009",
                    'status'            => $this->userModel::ACTIVE,
                ]);
            }

            if (! $user->hasRole('customer')) {
                $user->assignRole('customer');
            }

            // Create default preferences for new customer
            if (! $user->preferences) {
                Preference::create([
                    'user_id'         => $user->id,
                    'notifications'   => [
                        'booking_emails'   => true,
                        'sms_reminders'    => false,
                        'marketing_emails' => true,
                    ],
                    'favorite_sports' => [],
                    'language'        => 'english',
                    'currency'        => 'QAR',
                ]);
            }

            $token = $user->createToken('auth-token')->plainTextToken;

            DB::commit();

            $user->makeHidden(['roles']);

            return sendSuccess("Logged in with google", compact('token', 'user'));

        } catch (Exception $e) {
            DB::rollBack();
            Log::error("Customer Google Login Failed: " . $e->getMessage(), ["exception" => $e]);
            return sendError("Something went wrong try again later");
        }
    }

    public function customerLogin($request)
    {
        try {
            $user = $this->userModel->whereRelation('roles', 'name', 'customer')->whereEmail($request->email)->first();

            if ($user && ! $user->password) {
                $otp = str_pad((string) random_int(0, 999999), 6, '0', STR_PAD_LEFT);

                DB::table('password_reset_tokens')->updateOrInsert(
                    ['email' => $request->email],
                    ['token' => Hash::make($otp), 'created_at' => Carbon::now()]
                );

                try {
                    Mail::to($user->email)->send(new CustomerForgotPasswordOtpEmail($otp, $user));
                } catch (Exception $e) {
                    Log::error("Failed to send OTP email for customer login: " . $e->getMessage(), ["Exception" => $e]);
                    return sendError('Failed to send OTP email. Please try again later.');
                }

                return sendSuccess('Your pasword was reset please change your password', ['reset_password' => true]);
            }

            if (! $user || ! password_verify($request->password, $user->password)) {
                return sendError('Invalid credentials');
            }

            if ($user->status == User::BLOCKED) {
                return sendError('Your account is blocked by admin. Please contact for further details.');
            }

            $token = $user->createToken('bookboll-customer-login-api-token')->plainTextToken;

            $user->makeHidden(['roles']);

            $response = [
                'token' => $token,
                'user'  => $user,
            ];

            return sendSuccess('Login successful', $response);
        } catch (Exception $e) {
            Log::error("Customer Login Error: " . $e->getMessage(), ["Exception" => $e]);
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
        $user = auth()->user();
        $user->makeHidden(['roles']);
        $roles    = $user->getRoleNames();
        $response = [
            'user'  => $user,
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
        $search           = $request->search ?? null;
        $records_per_page = $request->records_per_page ?? 10;

        $users = $this->userModel->latest()
            ->whereHas('roles', function ($query) {
                $query->whereIn('name', ['admin', 'senior_employee', 'junior_employee', 'guard']);
            })
            ->with(['venues:id,name'])
            ->when($search, function ($query, $search) {
                $query->where("name", "LIKE", "%{$search}%")
                    ->orWhere("email", "LIKE", "%{$search}%")
                    ->orWhere("status", "LIKE", "%{$search}%")
                    ->orWhere("created_at", "LIKE", "%{$search}%");
            })
            ->paginate($records_per_page);

        $roles  = Role::get(['id', 'name']);
        $venues = Venue::get(['id', 'name']);

        return sendSuccess("Users fetched successfully", compact('users', 'roles', 'venues', 'search'));
    }

    public function createUser($request)
    {
        $formData = $request->validate([
            'name'     => 'required|string|max:100',
            'email'    => 'required|email|unique:users,email',
            'role'     => 'required|exists:roles,name',
            'status'   => 'required|in:' . $this->userModel::ACTIVE . ',' . $this->userModel::INACTIVE,
            'password' => 'nullable|min:3',
            'venues'   => 'nullable|array',
            'venues.*' => 'numeric|exists:venues,id',
        ]);

        try {

            DB::beginTransaction();

            $role = Role::where('name', $formData['role'])->firstOrFail();

            $isSuperAdminOrAdmin = in_array($role->id, [1, 2]);

            if ($isSuperAdminOrAdmin) {
                $allVenues          = Venue::pluck('id')->toArray();
                $formData['venues'] = $allVenues;
            }

            if (empty($formData['password'])) {
                $formData['password'] = '12345678';
            }

            $plainPassword = $formData['password'];

            $user = $this->userModel->create([
                'name'     => $formData['name'],
                'email'    => $formData['email'],
                'status'   => $formData['status'],
                'password' => $formData['password'],
            ]);

            if (! $role) {
                throw new Exception("Role not found");
            }

            $user->assignRole($role['name']);

            // Create default preferences if user is a customer
            if ($role['name'] === 'customer' && ! $user->preferences) {
                Preference::create([
                    'user_id'         => $user->id,
                    'notifications'   => [
                        'booking_emails'   => true,
                        'sms_reminders'    => false,
                        'marketing_emails' => true,
                    ],
                    'favorite_sports' => [],
                    'language'        => 'english',
                    'currency'        => 'QAR',
                ]);
            }

            if (! empty($formData['venues'])) {
                $user->venues()->sync($formData['venues']);
            }

            DB::commit();

            try {
                Mail::to($user->email)->send(new UserWelcomeEmail($user, $plainPassword));
            } catch (Exception $e) {
                Log::error("Failed to send welcome email: " . $e->getMessage(), ["Exception" => $e]);
            }

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
            'role'     => 'sometimes|exists:roles,name',
            'status'   => 'sometimes|in:' . $this->userModel::ACTIVE . ',' . $this->userModel::INACTIVE,
            'password' => 'sometimes|min:3',
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

                $role = Role::where('name', $formData['role'])->firstOrFail();

                if (! $role) {
                    throw new Exception("Role not found");
                }

                $roleName = $role->name;

                $user->syncRoles([]);
                $user->assignRole($roleName);
            }

            if (isset($formData['venues'])) {
                $currentRole         = $user->roles->first();
                $roleName            = $currentRole ? $currentRole->name : null;
                $isSuperAdminOrAdmin = in_array($roleName, ['super_admin', 'admin']);

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

    public function forgotPassword($request)
    {
        $request->validate(['email' => 'required|email']);

        $token = Str::random(64);

        DB::table('password_reset_tokens')->updateOrInsert(
            ['email' => $request->email],
            ['token' => Hash::make($token), 'created_at' => Carbon::now()]
        );

        $domain = $request->domain ?? config('app.frontend_url');

        $url = $domain . '/reset-password?token=' . $token . '&email=' . urlencode($request->email);

        Mail::to($request->email)->send(new ForgetPasswordEmail($url));

        return response()->json(['message' => 'Password reset link sent']);
    }

    public function resetPassword($request)
    {
        $request->validate([
            'email'    => 'required|email',
            'token'    => 'required|string',
            'password' => 'required|confirmed|min:3',
        ]);

        $tokenRecord = DB::table('password_reset_tokens')->where('email', $request->email)->first();

        if (! $tokenRecord || ! Hash::check($request->token, $tokenRecord->token)) {
            return sendError('Invalid link please try again');
        }

        if (Carbon::parse($tokenRecord->created_at)->addMinutes(30)->isPast()) {
            return sendError('Link expired please try again');
        }

        $user = User::where('email', $request->email)->first();
        if (! $user) {
            return sendError('User not found');
        }

        $user->password = $request->password;
        $user->save();

        return sendSuccess('Password reset successfully');
    }

    public function customerForgotPassword($request)
    {
        $request->validate(['email' => 'required|email']);

        try {
            $user = $this->userModel->whereRelation('roles', 'name', 'customer')
                ->where('email', $request->email)
                ->first();

            if (! $user) {
                return sendError('No customer account found with this email address');
            }

            $otp = str_pad((string) random_int(0, 999999), 6, '0', STR_PAD_LEFT);

            DB::table('password_reset_tokens')->updateOrInsert(
                ['email' => $request->email],
                ['token' => Hash::make($otp), 'created_at' => Carbon::now()]
            );

            try {
                Mail::to($user->email)->send(new CustomerForgotPasswordOtpEmail($otp, $user));
            } catch (Exception $e) {
                Log::error("Failed to send OTP email: " . $e->getMessage(), ["Exception" => $e]);
                return sendError('Failed to send OTP email. Please try again later.');
            }

            return sendSuccess('OTP has been sent to your email address. Please check your inbox.');

        } catch (Exception $e) {
            Log::error("Customer forgot password error: " . $e->getMessage(), ["Exception" => $e]);
            return sendError('Something went wrong. Please try again later.');
        }
    }

    public function customerResetPassword($request)
    {
        $request->validate([
            'email'    => 'required|email',
            'otp'      => 'required|string|size:6',
            'password' => 'required|min:8|confirmed',
        ]);

        try {
            $user = $this->userModel->whereRelation('roles', 'name', 'customer')
                ->where('email', $request->email)
                ->first();

            if (! $user) {
                return sendError('No customer account found with this email address');
            }

            $otpRecord = DB::table('password_reset_tokens')
                ->where('email', $request->email)
                ->first();

            if (! $otpRecord) {
                return sendError('Invalid or expired OTP. Please request a new one.');
            }

            if (Carbon::parse($otpRecord->created_at)->addMinutes(10)->isPast()) {
                DB::table('password_reset_tokens')->where('email', $request->email)->delete();
                return sendError('OTP has expired. Please request a new one.');
            }

            if (! Hash::check($request->otp, $otpRecord->token)) {
                return sendError('Invalid OTP. Please check and try again.');
            }

            DB::beginTransaction();

            $user->password = $request->password;
            $user->save();

            DB::table('password_reset_tokens')->where('email', $request->email)->delete();

            DB::commit();

            return sendSuccess('Password has been reset successfully. You can now login with your new password.');

        } catch (Exception $e) {
            DB::rollBack();
            Log::error("Customer reset password error: " . $e->getMessage(), ["Exception" => $e]);
            return sendError('Something went wrong. Please try again later.');
        }
    }

    public function changePassword($request, $id)
    {
        $user = $this->findById($id);

        if (! $user) {
            return sendError("User not found");
        }

        $formData = $request->validate([
            'current_password' => 'required|current_password',
            'password'         => 'required|min:8|confirmed|different:current_password',
        ]);

        try {
            DB::beginTransaction();

            $user->password = $formData['password'];
            $user->save();

            DB::commit();

            return sendSuccess("Password changed successfully");

        } catch (Exception $e) {
            DB::rollBack();
            return sendError("Something went wrong: " . $e->getMessage());
        }
    }

    public function getPreferences()
    {
        $preferences = Preference::where('user_id', auth()->id())->first();
        return sendSuccess('Preferences fetched successfully', compact('preferences'));
    }

    public function updatePreferences($request)
    {
        $user = auth()->user();

        $formData = $request->validate([
            'notifications.booking_emails'   => 'nullable|boolean',
            'notifications.sms_reminders'    => 'nullable|boolean',
            'notifications.marketing_emails' => 'nullable|boolean',
            'favorite_sports'                => 'nullable|array',
            'favorite_sports.*'              => 'required|string',
            'language'                       => 'nullable|string',
            'currency'                       => 'nullable|string',
        ]);

        $preferences = $user->preferences ?? Preference::create(['user_id' => $user->id]);

        // Update notifications - merge with existing to preserve other notification settings
        if (isset($formData['notifications'])) {
            $currentNotifications       = $preferences->notifications ?? [];
            $preferences->notifications = array_merge($currentNotifications, $formData['notifications']);
        }

        // Update favorite_sports if provided
        if (isset($formData['favorite_sports'])) {
            $preferences->favorite_sports = $formData['favorite_sports'];
        }

        // Update language if provided
        if (isset($formData['language'])) {
            $preferences->language = $formData['language'];
        }

        // Update currency if provided
        if (isset($formData['currency'])) {
            $preferences->currency = $formData['currency'];
        }

        $preferences->save();

        $user->load('preferences');

        return sendSuccess('Preferences updated successfully', compact('user', 'preferences'));
    }

    public function updateProfileImage($request)
    {
        $request->validate(["image" => "required|image"]);
        $image       = $request->image->store("profile-images", "public");
        $user        = auth()->user();
        $user->image = $image;
        $user->save();

        $image = $user->image;
        return sendSuccess("Profile image updated successfully", compact('image'));
    }

    public function subscribeOneSignal($request)
    {
        $user = auth()->user();

        if (! $user) {
            return sendError('User not authenticated', [], 401);
        }

        $formData = $request->validate([
            'onesignal_id' => 'required|string|max:255',
            'device_type'  => 'nullable|string|in:ios,android,web',
            'device_info'  => 'nullable|string|max:255',
        ]);

        try {
            DB::beginTransaction();

            $existingSubscription = OnesignalSubscription::where('onesignal_id', $formData['onesignal_id'])
                ->where('user_id', $user->id)
                ->first();

            if ($existingSubscription) {
                $existingSubscription->update([
                    'is_active'   => true,
                    'device_type' => $formData['device_type'] ?? $existingSubscription->device_type,
                    'device_info' => $formData['device_info'] ?? $existingSubscription->device_info,
                ]);
                $subscription = $existingSubscription;
            } else {
                $otherUserSubscription = OnesignalSubscription::where('onesignal_id', $formData['onesignal_id'])
                    ->where('user_id', '!=', $user->id)
                    ->first();

                if ($otherUserSubscription) {
                    $otherUserSubscription->update(['user_id' => $user->id, 'is_active' => true]);
                    $subscription = $otherUserSubscription;
                } else {
                    $subscription = OnesignalSubscription::create([
                        'user_id'      => $user->id,
                        'onesignal_id' => $formData['onesignal_id'],
                        'device_type'  => $formData['device_type'] ?? null,
                        'device_info'  => $formData['device_info'] ?? null,
                        'is_active'    => true,
                    ]);
                }
            }

            DB::commit();

            return sendSuccess('Successfully subscribed to push notifications', [
                'subscription'  => $subscription,
                'total_devices' => $user->activeOnesignalSubscriptions()->count(),
            ]);

        } catch (Exception $e) {
            DB::rollBack();
            Log::error("OneSignal subscription error: " . $e->getMessage(), ["Exception" => $e]);
            return sendError('Something went wrong while subscribing to push notifications');
        }
    }

    public function importUsersFromExcel($filename = 'bookboll-users.xlsx')
    {
        try {
            DB::beginTransaction();

            $filePath = public_path($filename);

            if (! file_exists($filePath)) {
                return sendError("Excel file not found: {$filename}. Please place the file in the public folder.");
            }

            $data = Excel::toArray(new \stdClass(), $filePath);

            if (empty($data) || empty($data[0])) {
                return sendError("Excel file is empty or invalid.");
            }

            $rows      = $data[0];
            $headerRow = array_shift($rows);

            $imported = 0;
            $updated  = 0;
            $skipped  = 0;
            $errors   = [];

            foreach ($rows as $index => $row) {
                $rowNumber = $index + 2;

                if (empty(array_filter($row))) {
                    continue;
                }

                $name          = trim($row[0] ?? '');
                $email         = trim($row[1] ?? '');
                $phone         = trim($row[2] ?? '');
                $nationality   = trim($row[3] ?? '');
                $dateOfBirth   = trim($row[4] ?? '');
                $gender        = trim($row[5] ?? '');
                $loyaltyPoints = trim($row[6] ?? '0');
                $address       = trim($row[7] ?? '');

                if (empty($email)) {
                    $skipped++;
                    $errors[] = "Row {$rowNumber}: Skipped - Email is required";
                    continue;
                }

                if (! filter_var($email, FILTER_VALIDATE_EMAIL)) {
                    $skipped++;
                    $errors[] = "Row {$rowNumber}: Skipped - Invalid email format: {$email}";
                    continue;
                }

                if (! empty($phone)) {

                    if (is_numeric($phone) && (stripos($phone, 'E') !== false || stripos($phone, 'e') !== false)) {
                        $phone = number_format((float) $phone, 0, '', '');
                    }

                    $phone = preg_replace('/[^0-9+]/', '', $phone);

                    if (! empty($phone) && ! str_starts_with($phone, '+')) {
                        if (str_starts_with($phone, '974')) {
                            $phone = '+' . $phone;
                        } elseif (strlen($phone) >= 8) {
                            $phone = '+974' . $phone;
                        }
                    }
                }

                $parsedDateOfBirth = null;
                if (! empty($dateOfBirth)) {
                    try {
                        $dateParts = explode('-', $dateOfBirth);
                        if (count($dateParts) === 3 && strlen($dateParts[0]) <= 2 && strlen($dateParts[1]) <= 2) {
                            $parsedDateOfBirth = Carbon::createFromFormat('d-m-Y', $dateOfBirth)->format('Y-m-d');
                        } else {
                            $parsedDateOfBirth = Carbon::parse($dateOfBirth)->format('Y-m-d');
                        }
                    } catch (Exception $e) {
                        Log::warning("Could not parse date of birth for row {$rowNumber}: {$dateOfBirth} - " . $e->getMessage());
                    }
                }

                $normalizedGender = null;
                if (! empty($gender)) {
                    $genderLower = strtolower($gender);
                    if (in_array($genderLower, ['male', 'm', 'man'])) {
                        $normalizedGender = 'male';
                    } elseif (in_array($genderLower, ['female', 'f', 'woman'])) {
                        $normalizedGender = 'female';
                    } else {
                        $normalizedGender = $gender;
                    }
                }

                $loyaltyPointsInt = 0;
                if (! empty($loyaltyPoints)) {
                    $loyaltyPointsInt = (int) filter_var($loyaltyPoints, FILTER_SANITIZE_NUMBER_INT);
                }

                $user = $this->userModel->where('email', $email)->first();

                if ($user) {
                    $updateData = [];

                    if (! empty($name)) {
                        $updateData['name'] = $name;
                    }
                    if (! empty($phone)) {
                        $updateData['phone'] = $phone;
                    }
                    if (! empty($nationality)) {
                        $updateData['nationality'] = $nationality;
                    }
                    if ($parsedDateOfBirth) {
                        $updateData['date_of_birth'] = $parsedDateOfBirth;
                    }
                    if ($normalizedGender) {
                        $updateData['gender'] = $normalizedGender;
                    }
                    if (! empty($address)) {
                        $updateData['address'] = $address;
                    }

                    if (! $user->hasRole('customer')) {
                        $user->assignRole('customer');
                    }

                    if (empty($user->status)) {
                        $updateData['status'] = $this->userModel::ACTIVE;
                    }

                    if (! empty($updateData)) {
                        $user->update($updateData);
                    }

                    if ($loyaltyPointsInt > 0) {
                        $currentBalance = LoyaltyPoint::balance($user->id) ?? 0;
                        if ($currentBalance < $loyaltyPointsInt) {
                            $pointsToAdd = $loyaltyPointsInt - $currentBalance;
                            $user->addLoyaltyPoints($pointsToAdd, null, "Imported from Excel - Row {$rowNumber}");
                        }
                    }

                    $updated++;
                } else {
                    $user = $this->userModel->create([
                        'name'          => $name ?: 'User',
                        'email'         => $email,
                        'phone'         => $phone,
                        'nationality'   => $nationality,
                        'date_of_birth' => $parsedDateOfBirth,
                        'gender'        => $normalizedGender,
                        'address'       => $address,
                        'status'        => $this->userModel::ACTIVE,
                    ]);

                    $user->assignRole('customer');

                    if ($loyaltyPointsInt > 0) {
                        $user->addLoyaltyPoints($loyaltyPointsInt, null, "Imported from Excel - Row {$rowNumber}");
                    }

                    $imported++;
                }
            }

            DB::commit();

            $summary = [
                'imported'        => $imported,
                'updated'         => $updated,
                'skipped'         => $skipped,
                'total_processed' => $imported + $updated + $skipped,
            ];

            if (! empty($errors)) {
                $summary['errors'] = $errors;
            }

            return sendSuccess("Users imported successfully", $summary);

        } catch (Exception $e) {
            DB::rollBack();
            Log::error("User import error: " . $e->getMessage(), [
                'file'      => $filename,
                'exception' => $e,
            ]);
            return sendError("Failed to import users: " . $e->getMessage());
        }
    }

    /**
     * Register user from admin mobile app (creates registration request)
     */
    public function registerAdminAppUser($request)
    {
        $formData = $request->validate([
            'name'     => 'required|string|max:100',
            'email'    => 'required|email|unique:registration_requests,email',
            'phone'    => 'required|min:6|max:20',
            'password' => 'required|min:8|confirmed',
        ]);

        try {
            DB::beginTransaction();

            // Check if user already exists in users table
            $existingUser = $this->userModel->where('email', $formData['email'])->first();
            if ($existingUser) {
                // Check if there's already a pending registration request
                $existingRequest = RegistrationRequest::where('email', $formData['email'])
                    ->where('status', RegistrationRequest::PENDING)
                    ->first();

                if ($existingRequest) {
                    return sendError("A registration request with this email is already pending approval.");
                }
            }

            $registrationRequest = RegistrationRequest::create([
                'name'     => $formData['name'],
                'email'    => $formData['email'],
                'phone'    => $formData['phone'],
                'password' => $formData['password'],
                'status'   => RegistrationRequest::PENDING,
            ]);

            DB::commit();

            return sendSuccess("Registration request submitted successfully. Your request is pending admin approval.", [
                'registration_request' => [
                    'id'         => $registrationRequest->id,
                    'name'       => $registrationRequest->name,
                    'email'      => $registrationRequest->email,
                    'phone'      => $registrationRequest->phone,
                    'status'     => $registrationRequest->status,
                    'created_at' => $registrationRequest->created_at,
                ],
            ]);

        } catch (Exception $e) {
            DB::rollBack();
            Log::error("Admin app user registration error: " . $e->getMessage(), ["Exception" => $e]);
            return sendError("Something went wrong please try again later");
        }
    }
    public function listPendingUsers($request)
    {
        $search           = $request->search ?? null;
        $records_per_page = $request->records_per_page ?? 10;

        $registrationRequests = RegistrationRequest::where('status', RegistrationRequest::PENDING)
            ->when($search, function ($query, $search) {
                $query->where("name", "LIKE", "%{$search}%")
                    ->orWhere("email", "LIKE", "%{$search}%")
                    ->orWhere("phone", "LIKE", "%{$search}%");
            })
            ->latest()
            ->paginate($records_per_page);

        $roles  = Role::select(['id', 'name'])->get();
        $venues = Venue::select(['id', 'name'])->active()->get();

        return sendSuccess("Pending registration requests fetched successfully", [
            'registration_requests' => $registrationRequests,
            'roles'                 => $roles,
            'venues'                => $venues,
            'search'                => $search,
        ]);
    }

    public function approveUser($request, $id)
    {
        $registrationRequest = RegistrationRequest::find($id);

        if (! $registrationRequest) {
            return sendError("Registration request not found");
        }

        if ($registrationRequest->status !== RegistrationRequest::PENDING) {
            return sendError("Registration request is not in pending status");
        }

        $formData = $request->validate([
            'role'     => 'required|exists:roles,name',
            'venues'   => 'nullable|array',
            'venues.*' => 'numeric|exists:venues,id',
        ]);

        try {
            DB::beginTransaction();

            $role     = Role::where('name', $formData['role'])->firstOrFail();
            $roleName = $role->name;

            $isSuperAdminOrAdmin = in_array($roleName, ['super_admin', 'admin']);

            $existingUser = $this->userModel->where('email', $registrationRequest->email)->first();

            if ($existingUser) {
                $existingUser->syncRoles([]);
                $existingUser->assignRole($roleName);

                if (! empty($formData['venues'])) {
                    $existingUser->venues()->sync($formData['venues']);
                }

                if ($existingUser->status !== $this->userModel::ACTIVE) {
                    $existingUser->status = $this->userModel::ACTIVE;
                    $existingUser->save();
                }

                $user = $existingUser;
            } else {
                // Create user without password first to avoid double hashing
                // (password is already hashed in RegistrationRequest)
                $user = $this->userModel->create([
                    'status' => $this->userModel::ACTIVE,
                    'name'   => $registrationRequest->name,
                    'email'  => $registrationRequest->email,
                    'phone'  => $registrationRequest->phone,
                ]);

                // Update password directly via DB to bypass the 'hashed' cast
                // since password is already hashed in RegistrationRequest
                DB::table('users')
                    ->where('id', $user->id)
                    ->update(['password' => $registrationRequest->password]);

                // Refresh the user model to get the updated password
                $user->refresh();

                $user->assignRole($roleName);

                if (! empty($formData['venues'])) {
                    $user->venues()->sync($formData['venues']);
                }

                Preference::create([
                    'user_id'         => $user->id,
                    'notifications'   => [
                        'booking_emails'   => true,
                        'sms_reminders'    => false,
                        'marketing_emails' => true,
                    ],
                    'favorite_sports' => [],
                    'language'        => 'english',
                    'currency'        => 'QAR',
                ]);
            }

            $registrationRequest->delete();

            DB::commit();

            try {
                Mail::to($user->email)->send(new UserWelcomeEmail($user, null));
            } catch (Exception $e) {
                Log::error("Failed to send welcome email after approval: " . $e->getMessage(), ["Exception" => $e]);
            }

            $user->load(['roles', 'venues']);
            $user->makeHidden(['roles']);
            $roles = $user->getRoleNames();

            $message = $existingUser
                ? "Registration request approved. User already existed - roles and venues have been updated."
                : "Registration request approved. New user created successfully.";

            return sendSuccess($message, compact('user', 'roles'));

        } catch (Exception $e) {
            DB::rollBack();
            Log::error("Registration request approval error: " . $e->getMessage(), ["Exception" => $e]);
            return sendError("Something went wrong: " . $e->getMessage());
        }
    }

    public function rejectUser($request, $id)
    {
        $registrationRequest = RegistrationRequest::find($id);

        if (! $registrationRequest) {
            return sendError("Registration request not found");
        }

        if ($registrationRequest->status !== RegistrationRequest::PENDING) {
            return sendError("Registration request is not in pending status");
        }

        $formData = $request->validate(['reason' => 'nullable|string|max:500']);

        try {
            $rejectionReason = $formData['reason'] ?? null;

            try {
                $userForEmail = new User([
                    'name'  => $registrationRequest->name,
                    'email' => $registrationRequest->email,
                ]);

                Mail::to($registrationRequest->email)->send(new UserRejectionEmail($userForEmail, $rejectionReason));
            } catch (Exception $e) {
                Log::error("Failed to send rejection email: " . $e->getMessage(), ["Exception" => $e]);
            }

            $registrationRequest->delete();

            return sendSuccess("Registration request rejected and deleted successfully. Rejection email has been sent.");

        } catch (Exception $e) {
            Log::error("Registration request rejection error: " . $e->getMessage(), ["Exception" => $e]);
            return sendError("Something went wrong: " . $e->getMessage());
        }
    }
}
