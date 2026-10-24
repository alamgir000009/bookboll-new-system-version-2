<?php

namespace App\Support;

/**
 * What can be said about a session's address without asking anyone else.
 *
 * Geo-location is deliberately absent: resolving an address to a city means
 * either shipping a MaxMind database or handing every user's address to a third
 * party, and neither is wanted here. What is left is still worth showing — the
 * address itself, whether it is routable, and whether the address that last
 * used a token differs from the one it was issued to, which is the signal that
 * actually matters when hunting a stolen session.
 *
 * To add geo-location later, implement a resolver and call it from
 * locationLabel(); nothing outside this class reads the address directly.
 */
final class IpAddressDetails
{
    public const SCOPE_PUBLIC = 'public';

    public const SCOPE_PRIVATE = 'private';

    public const SCOPE_LOOPBACK = 'loopback';

    public const SCOPE_UNKNOWN = 'unknown';

    private function __construct(
        public readonly ?string $address,
        public readonly ?int $version,
        public readonly string $scope,
    ) {}

    public static function parse(?string $address): self
    {
        $address = is_string($address) ? trim($address) : null;

        if ($address === null || $address === '' || filter_var($address, FILTER_VALIDATE_IP) === false) {
            return new self(null, null, self::SCOPE_UNKNOWN);
        }

        $version = filter_var($address, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6) !== false ? 6 : 4;

        return new self($address, $version, self::detectScope($address));
    }

    public function isKnown(): bool
    {
        return $this->address !== null;
    }

    /** True for an address that could only have come from this network. */
    public function isInternal(): bool
    {
        return in_array($this->scope, [self::SCOPE_PRIVATE, self::SCOPE_LOOPBACK], true);
    }

    public function label(): string
    {
        return $this->address ?? 'Unknown address';
    }

    /**
     * The best answer available for "where from". Today that is the network the
     * address belongs to; the geo resolver, when there is one, goes here.
     */
    public function locationLabel(): string
    {
        return match ($this->scope) {
            self::SCOPE_LOOPBACK => 'This server',
            self::SCOPE_PRIVATE => 'Local network',
            self::SCOPE_PUBLIC => 'IPv'.$this->version.' · internet',
            default => 'Unknown',
        };
    }

    private static function detectScope(string $address): string
    {
        // FILTER_FLAG_NO_PRIV_RANGE and _NO_RES_RANGE between them cover
        // 10/8, 172.16/12, 192.168/16, 127/8, 169.254/16, ::1 and fc00::/7.
        if (filter_var($address, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE) !== false) {
            return self::SCOPE_PUBLIC;
        }

        if ($address === '::1' || str_starts_with($address, '127.')) {
            return self::SCOPE_LOOPBACK;
        }

        return self::SCOPE_PRIVATE;
    }
}
