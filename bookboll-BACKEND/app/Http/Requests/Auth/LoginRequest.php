<?php

namespace App\Http\Requests\Auth;

use App\Models\User;
use App\Support\TurnstileValidation;
use Illuminate\Auth\Events\Lockout;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class LoginRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        $rules = [
            'email' => ['required', 'string', 'email'],
            'password' => ['required', 'string'],
        ];

        if (TurnstileValidation::enabled()) {
            $rules = array_merge($rules, TurnstileValidation::rules($this));
        }

        return $rules;
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return TurnstileValidation::messages();
    }

    /**
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return TurnstileValidation::attributes();
    }

    /**
     * @throws ValidationException
     */
    public function authenticate(): void
    {
        $this->ensureIsNotRateLimited();

        $user = User::where('email', $this->string('email'))->first();
        $password = (string) $this->input('password');

        if (! $user || ! $user->passwordMatches($password)) {
            RateLimiter::hit($this->throttleKey());

            if ($user && ! $user->hasAnyRole(['super_admin', 'admin'])) {
                throw ValidationException::withMessages([
                    'email' => ['Access denied. Admin privileges required.'],
                ]);
            }

            throw ValidationException::withMessages([
                'email' => trans('auth.failed'),
            ]);
        }

        if (! $user->hasAnyRole(['super_admin', 'admin'])) {
            RateLimiter::hit($this->throttleKey());

            throw ValidationException::withMessages([
                'email' => ['Access denied. Admin privileges required.'],
            ]);
        }

        if ($user->status === User::PENDING) {
            throw ValidationException::withMessages([
                'email' => ['Your account is pending admin approval. Please wait for approval before logging in.'],
            ]);
        }

        if ($user->status === User::BLOCKED) {
            throw ValidationException::withMessages([
                'email' => ['Your account has been blocked.'],
            ]);
        }

        Auth::login($user, $this->boolean('remember'));

        RateLimiter::clear($this->throttleKey());
    }

    /**
     * @throws ValidationException
     */
    public function ensureIsNotRateLimited(): void
    {
        if (! RateLimiter::tooManyAttempts($this->throttleKey(), 5)) {
            return;
        }

        event(new Lockout($this));

        $seconds = RateLimiter::availableIn($this->throttleKey());

        throw ValidationException::withMessages([
            'email' => trans('auth.throttle', [
                'seconds' => $seconds,
                'minutes' => ceil($seconds / 60),
            ]),
        ]);
    }

    public function throttleKey(): string
    {
        return Str::transliterate(Str::lower($this->string('email')) . '|' . $this->ip());
    }
}
