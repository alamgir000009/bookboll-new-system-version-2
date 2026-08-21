<?php

namespace App\Services;

use Firebase\JWT\JWK;
use Firebase\JWT\JWT;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Throwable;
use UnexpectedValueException;

class AppleTokenVerifier
{
    public function verify(string $identityToken): object
    {
        $audiences = collect(explode(',', (string) (config('services.apple.client_id') ?: 'com.bookboll.app')))
            ->map(fn ($id) => trim($id))
            ->filter()
            ->values()
            ->all();

        if ($audiences === []) {
            throw new UnexpectedValueException('Apple Sign-In is not configured.');
        }

        $payload = $this->decode($identityToken);

        if (($payload->iss ?? null) !== 'https://appleid.apple.com') {
            throw new UnexpectedValueException('Invalid Apple token issuer.');
        }

        if (! in_array($payload->aud ?? null, $audiences, true)) {
            throw new UnexpectedValueException('Invalid Apple token audience.');
        }

        if (empty($payload->sub)) {
            throw new UnexpectedValueException('Invalid Apple token subject.');
        }

        return $payload;
    }

    protected function decode(string $identityToken): object
    {
        JWT::$leeway = 300;

        try {
            return JWT::decode($identityToken, JWK::parseKeySet($this->jwks(), 'RS256'));
        } catch (Throwable $e) {
            Log::warning('Apple JWT decode failed, retrying with fresh keys: '.$e->getMessage());
            Cache::forget('apple_sign_in_jwks');

            return JWT::decode($identityToken, JWK::parseKeySet($this->jwks(true), 'RS256'));
        }
    }

    protected function jwks(bool $forceRefresh = false): array
    {
        if ($forceRefresh) {
            Cache::forget('apple_sign_in_jwks');
        }

        return Cache::remember('apple_sign_in_jwks', 3600, function () {
            $response = Http::timeout(15)
                ->retry(2, 200)
                ->get('https://appleid.apple.com/auth/keys');

            if (! $response->successful() || ! is_array($response->json()) || empty($response->json()['keys'])) {
                Log::error('Unable to fetch Apple JWKS', [
                    'status' => $response->status(),
                    'body'   => $response->body(),
                ]);

                throw new UnexpectedValueException('Unable to fetch Apple public keys.');
            }

            return $response->json();
        });
    }
}
