<?php

namespace App\Support;

use App\Models\Setting;
use App\Models\SlotLock;
use App\Services\GoogleRecaptchaService;
use Illuminate\Support\Facades\Cache;

/**
 * The human check that stands between a booking page and a swept-clean calendar.
 *
 * Holding a slot is free and instant — every tap locks it for a few minutes so
 * nobody else can take it — which is exactly what makes it worth abusing. A script
 * can walk /lock-slot and put a venue's entire day beyond reach until the locks
 * expire, over and over, without ever booking anything.
 *
 * So past a handful of simultaneous holds, taking another one costs one challenge.
 * The first few are untouched (almost every real booking is 1-4 slots) and one
 * solved challenge clears the whole session for VERIFIED_MINUTES, so a customer
 * booking six slots across three days meets it once and never again.
 *
 * Two things are deliberately never challenged: staff surfaces, who book on behalf
 * of customers all day, and everyone at all when reCAPTCHA is not configured — a
 * challenge the page cannot render would lock people out of booking entirely.
 */
class SlotHoldVerification
{
    /** Simultaneous holds allowed before the first challenge. */
    public const DEFAULT_THRESHOLD = 5;

    /** How long one solved challenge keeps a session clear. */
    public const VERIFIED_MINUTES = 30;

    /** Admin-settable via the `slot_hold_verify_threshold` setting. */
    public static function threshold(): int
    {
        $setting = Setting::where('key', 'slot_hold_verify_threshold')->value('value');

        return is_numeric($setting) && (int) $setting > 0
            ? (int) $setting
            : self::DEFAULT_THRESHOLD;
    }

    public static function enabled($request = null): bool
    {
        // Staff hold slots for walk-ins and phone bookings constantly; challenging
        // the front desk every few taps would just make the console unusable.
        if (isRequestFromAdminSurface($request)) {
            return false;
        }

        return self::configured();
    }

    /**
     * reCAPTCHA is on when both keys are set, unless RECAPTCHA_ENABLED says
     * otherwise. With the flag unset it stays off locally, so a dev box without
     * keys never challenges anyone — the same rule the Turnstile login check uses.
     */
    public static function configured(): bool
    {
        $flag = config('services.recaptcha.enabled');

        if ($flag === false || $flag === 'false' || $flag === '0' || $flag === 0) {
            return false;
        }

        if ($flag === null && app()->environment('local')) {
            return false;
        }

        return filled(config('services.recaptcha.site_key'))
            && filled(config('services.recaptcha.secret_key'));
    }

    /**
     * Live holds this device is already sitting on — every listing, every date.
     * Counting per listing would miss the cheapest sweep there is: one slot each
     * across fifty courts.
     */
    public static function heldCount(string $sessionId): int
    {
        return SlotLock::where('session_id', $sessionId)->active()->count();
    }

    public static function verified(string $sessionId): bool
    {
        return (bool) Cache::get(self::key($sessionId));
    }

    /**
     * Take the token the client sent, if there is one. reCAPTCHA tokens are
     * single-use, so callers must check verified() first — re-verifying the same
     * token on the next slot in the same selection would fail and lock out a
     * customer who had just passed.
     */
    public static function accept($request, string $sessionId): bool
    {
        $token = trim((string) (
            $request->input('g-recaptcha-response')
            ?: $request->input('recaptcha_token')
            ?: ''
        ));

        if ($token === '') {
            return false;
        }

        if (! app(GoogleRecaptchaService::class)->verify($token, request()->ip())) {
            return false;
        }

        Cache::put(self::key($sessionId), true, now()->addMinutes(self::VERIFIED_MINUTES));

        return true;
    }

    /** Everything the client needs to put a challenge on screen and try again. */
    public static function challenge(int $held): array
    {
        return [
            'requires_verification' => true,
            'site_key'              => (string) config('services.recaptcha.site_key'),
            'slots_held'            => $held,
            'threshold'             => self::threshold(),
        ];
    }

    protected static function key(string $sessionId): string
    {
        return 'slot_hold_verified:'.$sessionId;
    }
}
