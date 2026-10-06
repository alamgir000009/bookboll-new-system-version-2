<?php

namespace App\Services;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Google reCAPTCHA v2 token verification.
 *
 * A token is single-use and short-lived (about two minutes), so a caller that
 * needs the result to stick has to remember the outcome itself — see
 * App\Support\SlotHoldVerification, which caches the pass for the session.
 */
class GoogleRecaptchaService
{
    protected const VERIFY_URL = 'https://www.google.com/recaptcha/api/siteverify';

    public function verify(string $token, ?string $remoteIp = null): bool
    {
        $secret = config('services.recaptcha.secret_key');

        if (empty($secret)) {
            Log::warning('Google reCAPTCHA secret key is not configured.');

            return false;
        }

        try {
            $response = Http::asForm()->timeout(10)->post(self::VERIFY_URL, array_filter([
                'secret'   => $secret,
                'response' => $token,
                'remoteip' => $remoteIp,
            ]));
        } catch (\Throwable $e) {
            // Google unreachable. Refusing is the safe answer: the whole point of
            // this check is that the caller has not yet proved anything.
            Log::warning('Google reCAPTCHA verification failed to reach Google.', ['error' => $e->getMessage()]);

            return false;
        }

        if (! $response->successful()) {
            return false;
        }

        if (! $response->json('success')) {
            Log::info('Google reCAPTCHA rejected a token.', ['errors' => $response->json('error-codes')]);

            return false;
        }

        return true;
    }
}
