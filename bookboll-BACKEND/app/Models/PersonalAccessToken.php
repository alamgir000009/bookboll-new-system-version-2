<?php

namespace App\Models;

use App\Support\ApiClientSurfaces;
use App\Support\ApiSessionClients;
use App\Support\IpAddressDetails;
use App\Support\UserAgentDetails;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Request;
use Illuminate\Support\Str;
use Laravel\Sanctum\PersonalAccessToken as SanctumPersonalAccessToken;

/**
 * A Sanctum token, plus who is holding it.
 *
 * Sanctum's own model records a name and a last-used timestamp. Because
 * config('sanctum.expiration') is null, every token this app has ever issued is
 * still valid — so the admin panel needs to be able to see them all and end any
 * of them, and that means knowing the address and device behind each one.
 *
 * Both captures are free of extra queries. The issue metadata is written in the
 * INSERT that creates the token, and the last-used metadata rides the UPDATE
 * that Sanctum's guard already performs on every authenticated request.
 *
 * Registered over the vendor model in AppServiceProvider.
 */
class PersonalAccessToken extends SanctumPersonalAccessToken
{
    /** A user agent long past this is being padded; nothing legible lives out there. */
    private const MAX_USER_AGENT_LENGTH = 1024;

    public const STATUS_ACTIVE = 'active';

    public const STATUS_IDLE = 'idle';

    public const STATUS_UNUSED = 'unused';

    public const STATUS_EXPIRED = 'expired';

    protected $fillable = [
        'name',
        'token',
        'abilities',
        'expires_at',
        'ip_address',
        'user_agent',
        'last_used_ip',
        'client_surface',
        'last_used_surface',
        'last_used_user_agent',
    ];

    protected static function booted(): void
    {
        // Stamp the session with where it was opened from. createToken() runs
        // inside the login request, so the current request IS the client.
        static::creating(function (self $token) {
            $token->ip_address ??= self::currentIp();
            $token->user_agent ??= self::currentUserAgent();
            $token->client_surface ??= self::currentSurface();
        });

        // Sanctum's guard calls forceFill(['last_used_at' => now()])->save() on
        // every authenticated request. Eloquent fires `updating` before it
        // computes the dirty set, so attributes added here join that same UPDATE
        // — and when the address and agent have not changed, the dirty check
        // drops them and the statement is exactly what it was before.
        static::updating(function (self $token) {
            if (! $token->isDirty('last_used_at')) {
                return;
            }

            if ($ip = self::currentIp()) {
                $token->last_used_ip = $ip;
            }

            if ($agent = self::currentUserAgent()) {
                $token->last_used_user_agent = $agent;
            }

            if ($surface = self::currentSurface()) {
                $token->last_used_surface = $surface;
            }
        });
    }

    /**
     * Every token in this app belongs to a User. Typed as a relation rather
     * than read through tokenable() so the session list can eager-load roles.
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class, 'tokenable_id');
    }

    /* ---------------------------------------------------------------------
     | Interpretation — what the stored metadata means
     --------------------------------------------------------------------- */

    public function clientLabel(): string
    {
        return ApiSessionClients::label($this->name);
    }

    /**
     * Which app is holding this session, per its own `from` header. Falls back
     * to the surface it signed in on when the latest request sent no header.
     */
    public function surfaceLabel(): string
    {
        return ApiClientSurfaces::label($this->last_used_surface ?? $this->client_surface);
    }

    public function issuedSurfaceLabel(): string
    {
        return ApiClientSurfaces::label($this->client_surface);
    }

    /**
     * The session is being used from a different app than it signed in on.
     * A token is issued to one app and should stay there, so unlike a changed
     * address this has no innocent explanation worth assuming.
     */
    public function hasChangedSurface(): bool
    {
        return $this->client_surface !== null
            && $this->last_used_surface !== null
            && $this->client_surface !== $this->last_used_surface;
    }

    /** The device that last presented this token, falling back to the one it was issued to. */
    public function device(): UserAgentDetails
    {
        return UserAgentDetails::parse($this->last_used_user_agent ?? $this->user_agent);
    }

    public function issuedDevice(): UserAgentDetails
    {
        return UserAgentDetails::parse($this->user_agent);
    }

    /** The address this token is currently being used from. */
    public function address(): IpAddressDetails
    {
        return IpAddressDetails::parse($this->last_used_ip ?? $this->ip_address);
    }

    public function issuedAddress(): IpAddressDetails
    {
        return IpAddressDetails::parse($this->ip_address);
    }

    /**
     * True when the token is being used from somewhere other than where it was
     * issued. Mobile networks reassign addresses constantly, so this is a
     * prompt to look rather than proof of anything.
     */
    public function hasMovedAddress(): bool
    {
        return $this->ip_address !== null
            && $this->last_used_ip !== null
            && $this->ip_address !== $this->last_used_ip;
    }

    public function hasChangedDevice(): bool
    {
        return $this->user_agent !== null
            && $this->last_used_user_agent !== null
            && $this->user_agent !== $this->last_used_user_agent;
    }

    /** Nothing at all was recorded — a token issued before metadata was captured. */
    public function isUntracked(): bool
    {
        return $this->ip_address === null
            && $this->user_agent === null
            && $this->last_used_ip === null
            && $this->last_used_user_agent === null;
    }

    public function status(): string
    {
        if ($this->isExpired()) {
            return self::STATUS_EXPIRED;
        }

        if ($this->last_used_at === null) {
            return self::STATUS_UNUSED;
        }

        return $this->last_used_at->greaterThanOrEqualTo(self::activeSince())
            ? self::STATUS_ACTIVE
            : self::STATUS_IDLE;
    }

    public function isExpired(): bool
    {
        return $this->expires_at !== null && $this->expires_at->isPast();
    }

    /** True for the token making the request that is currently being handled. */
    public function isCurrent(): bool
    {
        $current = Request::user()?->currentAccessToken();

        return $current instanceof self && $current->getKey() === $this->getKey();
    }

    /* ---------------------------------------------------------------------
     | Query scopes
     --------------------------------------------------------------------- */

    public function scopeExpired(Builder $query): Builder
    {
        return $query->whereNotNull('expires_at')->where('expires_at', '<=', now());
    }

    public function scopeNotExpired(Builder $query): Builder
    {
        return $query->where(fn (Builder $q) => $q->whereNull('expires_at')->orWhere('expires_at', '>', now()));
    }

    public function scopeActive(Builder $query): Builder
    {
        return $query->notExpired()->where('last_used_at', '>=', self::activeSince());
    }

    public function scopeIdle(Builder $query): Builder
    {
        return $query->notExpired()
            ->whereNotNull('last_used_at')
            ->where('last_used_at', '<', self::activeSince());
    }

    public function scopeNeverUsed(Builder $query): Builder
    {
        return $query->notExpired()->whereNull('last_used_at');
    }

    /**
     * Filter on the reported surface. ApiClientSurfaces::NOT_SENT selects the
     * rows that reported nothing, which is every token issued before this was
     * recorded — so it is a real choice, not an absence of one.
     */
    public function scopeFromSurface(Builder $query, string $surface): Builder
    {
        if ($surface === ApiClientSurfaces::NOT_SENT) {
            return $query->whereNull('client_surface')->whereNull('last_used_surface');
        }

        return $query->where(fn (Builder $q) => $q->where('last_used_surface', $surface)
            ->orWhere(fn (Builder $inner) => $inner->whereNull('last_used_surface')->where('client_surface', $surface)));
    }

    public function scopeWithStatus(Builder $query, string $status): Builder
    {
        return match ($status) {
            self::STATUS_ACTIVE => $query->active(),
            self::STATUS_IDLE => $query->idle(),
            self::STATUS_EXPIRED => $query->expired(),
            self::STATUS_UNUSED => $query->neverUsed(),
            default => $query,
        };
    }

    /** @return array<string, string> status => label, for a filter dropdown */
    public static function statusOptions(): array
    {
        $window = self::activeWindowLabel();

        return [
            self::STATUS_ACTIVE => "Active (used in the last {$window})",
            self::STATUS_IDLE => 'Idle (not used recently)',
            self::STATUS_UNUSED => 'Never used',
            self::STATUS_EXPIRED => 'Expired',
        ];
    }

    /* ---------------------------------------------------------------------
     | The active window
     --------------------------------------------------------------------- */

    public static function activeWindowMinutes(): int
    {
        return max(1, (int) config('sanctum.active_session_window', 1440));
    }

    public static function activeSince(): Carbon
    {
        return now()->subMinutes(self::activeWindowMinutes());
    }

    public static function activeWindowLabel(): string
    {
        return now()->subMinutes(self::activeWindowMinutes())->diffForHumans(now(), syntax: true);
    }

    /* ---------------------------------------------------------------------
     | Request capture
     --------------------------------------------------------------------- */

    /**
     * Resolved through Laravel, so it honours trusted proxies — behind a proxy
     * or CDN that is not trusted this records the proxy, not the client.
     */
    private static function currentIp(): ?string
    {
        // A console or queue context has no REMOTE_ADDR, so this returns null
        // there on its own — no need to ask whether we are in one.
        $ip = Request::ip();

        return is_string($ip) && filter_var($ip, FILTER_VALIDATE_IP) !== false ? $ip : null;
    }

    /** The app's own `from` header — client-supplied, so stored as given and capped. */
    private static function currentSurface(): ?string
    {
        return ApiClientSurfaces::normalise(Request::header('from'));
    }

    private static function currentUserAgent(): ?string
    {
        $agent = Request::userAgent();

        if (! is_string($agent) || trim($agent) === '') {
            return null;
        }

        return Str::limit(trim($agent), self::MAX_USER_AGENT_LENGTH, '');
    }
}
