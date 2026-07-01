<?php

namespace App\Services;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class TurnstileVerificationService
{
    public function verify(string $token, ?string $remoteIp = null): bool
    {
        $secret = config('services.turnstile.secret_key');

        if (empty($secret)) {
            Log::warning('Cloudflare Turnstile secret key is not configured.');

            return false;
        }

        $response = Http::asForm()->post(
            'https://challenges.cloudflare.com/turnstile/v0/siteverify',
            array_filter([
                'secret' => $secret,
                'response' => $token,
                'remoteip' => $remoteIp,
            ])
        );

        if (! $response->successful()) {
            return false;
        }

        return (bool) $response->json('success');
    }
}
