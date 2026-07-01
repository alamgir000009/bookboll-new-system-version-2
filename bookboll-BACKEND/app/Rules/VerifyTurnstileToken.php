<?php

namespace App\Rules;

use App\Services\TurnstileVerificationService;
use Closure;
use Illuminate\Contracts\Validation\ValidationRule;

class VerifyTurnstileToken implements ValidationRule
{
    public function __construct(protected ?string $remoteIp = null) {}

    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if (! is_string($value) || $value === '') {
            $fail('Please complete the security verification.');

            return;
        }

        if (! app(TurnstileVerificationService::class)->verify($value, $this->remoteIp)) {
            $fail('Security verification failed. Please try again.');
        }
    }
}
