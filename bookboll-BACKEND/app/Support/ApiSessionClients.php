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
     * Token name => the entry point that issued it.
     *
     * This says how the session was CREATED. Which app is holding it is a
     * separate question, answered by the `from` header — see ApiClientSurfaces.
     *
     * @var array<string, string>
     */
    private const CLIENTS = [
        'bookboll-admin-web-api-token' => 'Admin Dashboard',
        'bookboll-admin-mobile-app-api-token' => 'Admin Mobile App',
        'bookboll-customer-login-api-token' => 'Customer Login',
        'bookboll-business-api-token' => 'Provider Sign-up',
        'bookboll-api-token' => 'Account Registration',
        'auth-token' => 'Social Sign-in',
    ];

    /** @return array<string, string> token name => label, for a filter dropdown */
    public static function options(): array
    {
        return self::CLIENTS;
    }

    public static function label(?string $tokenName): string
    {
        $name = trim((string) $tokenName);

        if ($name === '') {
            return 'Unknown client';
        }

        return self::CLIENTS[$name] ?? $name;
    }

    public static function isKnown(?string $tokenName): bool
    {
        return array_key_exists(trim((string) $tokenName), self::CLIENTS);
    }
}
