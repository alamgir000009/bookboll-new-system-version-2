<?php

namespace App\Support;

use App\Rules\VerifyTurnstileToken;
use Illuminate\Http\Request;

class TurnstileValidation
{
    public static function enabled(): bool
    {
        $enabled = config('services.turnstile.enabled');

        if ($enabled === false || $enabled === 'false' || $enabled === '0') {
            return false;
        }

        if ($enabled === null && app()->environment('local')) {
            return false;
        }

        return filled(config('services.turnstile.site_key'))
            && filled(config('services.turnstile.secret_key'));
    }

    public static function rules(Request $request): array
    {
        return [
            'cf-turnstile-response' => [
                'required',
                'string',
                new VerifyTurnstileToken($request->ip()),
            ],
        ];
    }

    public static function messages(): array
    {
        return [
            'cf-turnstile-response.required' => 'Please complete the security verification.',
        ];
    }

    public static function attributes(): array
    {
        return [
            'cf-turnstile-response' => 'security verification',
        ];
    }
}
