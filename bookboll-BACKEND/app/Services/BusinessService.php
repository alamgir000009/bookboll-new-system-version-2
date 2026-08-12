<?php

namespace App\Services;

use App\Models\Business;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Nnjeim\World\World;

class BusinessService
{

    public function __construct(
        private User $user,
        private Business $business,
    ) {}

    /** Normalize a username slug: lowercase, a-z0-9- only, max 30. */
    public static function normalizeUsername(?string $username): string
    {
        $u = strtolower(trim((string) $username));
        $u = preg_replace('/[^a-z0-9-]/', '', $u) ?? '';
        $u = trim($u, '-');

        return substr($u, 0, 30);
    }

    public function usernameAvailable(Request $request)
    {
        $username = self::normalizeUsername($request->query('username'));

        if (strlen($username) < 3) {
            return sendSuccess('Username checked', [
                'available' => false,
                'username'  => $username,
                'reason'    => 'Username must be at least 3 characters.',
            ]);
        }

        if (! preg_match('/^[a-z0-9](?:[a-z0-9-]*[a-z0-9])?$/', $username)) {
            return sendSuccess('Username checked', [
                'available' => false,
                'username'  => $username,
                'reason'    => 'Invalid username format.',
            ]);
        }

        $taken = $this->business::where('username', $username)->orWhere('brand_subdomain', $username)->exists();

        return sendSuccess('Username checked', [
            'available' => ! $taken,
            'username'  => $username,
        ]);
    }

    public function countries(Request $request)
    {
        $search = $request->query('search');

        $action = World::countries([
            'fields' => 'id,name,iso2',
            'search' => $search,
        ]);

        if (! $action->success) {
            return sendSuccess('Countries fetched successfully', ['countries' => []]);
        }

        $countries = $action->data->map(fn($country) => [
            'id'   => data_get($country, 'id'),
            'name' => data_get($country, 'name'),
            'iso2' => data_get($country, 'iso2'),
        ])->values();

        return sendSuccess('Countries fetched successfully', compact('countries'));
    }

    public function register(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'name'                  => 'required|string|max:100',
            'business_name'         => 'required|string|max:150',
            'username'              => 'required|string|min:3|max:30',
            'business_types'        => 'required|array|min:1',
            'business_types.*'      => ['required', 'string', Rule::in($this->business::BUSINESS_TYPE_KEYS)],
            'email'                 => 'required|email|max:255|unique:users,email',
            'password'              => 'required|string|min:8|confirmed',
            'country_id'            => 'nullable|integer|exists:countries,id',
        ]);

        if ($validator->fails()) {
            throw new ValidationException($validator);
        }

        $data     = $validator->validated();
        $username = self::normalizeUsername($data['username']);

        if (strlen($username) < 3 || ! preg_match('/^[a-z0-9](?:[a-z0-9-]*[a-z0-9])?$/', $username)) {
            return sendError('Invalid username. Use 3–30 letters, numbers or hyphens.', [
                'username' => ['Invalid username format.'],
            ], 422);
        }

        if ($this->business::where('username', $username)->orWhere('brand_subdomain', $username)->exists()) {
            return sendError('That username is already taken.', [
                'username' => ['That username is already taken.'],
            ], 422);
        }

        try {
            DB::beginTransaction();

            $user = $this->user::create([
                'name'     => $data['name'],
                'email'    => $data['email'],
                'password' => $data['password'],
                'status'   => User::ACTIVE,
            ]);

            $user->assignRole('provider_admin');

            $business = $this->business::create([
                'owner_id'        => $user->id,
                'name'            => $data['business_name'],
                'username'        => $username,
                'business_types'  => array_values($data['business_types']),
                'country_id'      => $data['country_id'] ?? null,
                'status'          => $this->business::STATUS_PENDING,
                'brand_subdomain' => $username,
            ]);

            DB::commit();
        } catch (\Throwable $e) {
            DB::rollBack();
            report($e);

            return sendError('Could not create business account.', [], 500);
        }

        $token = $user->createToken('bookboll-business-api-token')->plainTextToken;

        return sendSuccess('Business account created successfully', [
            'user' => [
                'id'     => $user->id,
                'name'   => $user->name,
                'email'  => $user->email,
                'status' => $user->status,
            ],
            'business' => $business->fresh(),
            'roles'    => $user->getRoleNames()->values(),
            'token'    => $token,
        ], 201);
    }
}
