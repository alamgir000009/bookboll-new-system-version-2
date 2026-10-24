<?php

namespace App\Support;

/**
 * Which app a token belongs to.
 *
 * Sanctum's token "name" is the only thing the issuing code records about the
 * client, and this project uses a distinct name per entry point. Those names are
 * the contract — mapping them here turns the raw string an admin would otherwise
 * read ("bookboll-admin-mobile-app-api-token") into the app they know.
 *
 * Keep in step with the createToken() calls in UserService and BusinessService.
 */
final class ApiSessionClients
{
    /**
     * Token name => [label, the surface it was issued from].
     *
     * @var array<string, array{label: string, surface: string}>
     */
    private const CLIENTS = [
        'bookboll-admin-web-api-token' => ['label' => 'Admin Dashboard', 'surface' => 'web'],
        'bookboll-admin-mobile-app-api-token' => ['label' => 'Admin Mobile App', 'surface' => 'mobile'],
        'bookboll-customer-login-api-token' => ['label' => 'Customer App', 'surface' => 'mobile'],
        'bookboll-business-api-token' => ['label' => 'Provider Sign-up', 'surface' => 'web'],
        'bookboll-api-token' => ['label' => 'Account Registration', 'surface' => 'web'],
        'auth-token' => ['label' => 'Social Sign-in', 'surface' => 'mobile'],
    ];

    /** @return array<string, string> token name => label, for a filter dropdown */
    public static function options(): array
    {
        return array_map(fn (array $client) => $client['label'], self::CLIENTS);
    }

    public static function label(?string $tokenName): string
    {
        $name = trim((string) $tokenName);

        if ($name === '') {
            return 'Unknown client';
        }

        return self::CLIENTS[$name]['label'] ?? $name;
    }

    /** 'web', 'mobile', or 'unknown' for a token name this class does not know. */
    public static function surface(?string $tokenName): string
    {
        return self::CLIENTS[trim((string) $tokenName)]['surface'] ?? 'unknown';
    }

    public static function isKnown(?string $tokenName): bool
    {
        return array_key_exists(trim((string) $tokenName), self::CLIENTS);
    }
}
