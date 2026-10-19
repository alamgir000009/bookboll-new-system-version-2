<?php

namespace App\Support;

use App\Models\User;

/**
 * Which dashboard notifications a person wants.
 *
 * The Settings → Notifications switches changed local component state and
 * nothing else — they were explicitly labelled as not working. A preference
 * that is stored but not consulted is the same bug wearing a database, so
 * delivery asks this class before sending, and every switch here maps to an
 * event something actually raises.
 *
 * Personal, not per business: two people on one business choose separately.
 * Distinct from businesses.notification_templates, which is the copy sent to
 * customers on the Automated notifications page.
 */
class ProviderNotifications
{
    /**
     * Every switch, with the alert it governs and whether it starts on.
     *
     * The key is the preference; `kind` is the AdminAlert kind it gates. A new
     * switch belongs here and nowhere else — the API, the delivery check and
     * the audit all read this one list.
     */
    public const EVENTS = [
        'booking_new'       => ['kind' => 'booking', 'default' => true, 'label' => 'New booking received'],
        'booking_cancelled' => ['kind' => 'booking', 'default' => true, 'label' => 'Booking cancellation'],
        'payment_received'  => ['kind' => 'payment', 'default' => true, 'label' => 'Payment received'],
        'member_new'        => ['kind' => 'member',  'default' => true, 'label' => 'New membership'],
    ];

    /**
     * The dashboard also offered a "Low wallet balance warning" switch.
     *
     * Nothing in the system raises that alert — a provider wallet holds
     * earnings, and nothing watches it for a floor. A switch for an alert that
     * is never sent is the same empty promise as a switch that saves nowhere,
     * so it is not offered. The wallet alerts that DO exist are payout-method
     * problems and withdrawal outcomes, and those are always on below.
     */
    public const NOT_OFFERED = [
        'wallet_low' => 'No low-balance alert exists to switch off.',
    ];

    /**
     * Alerts nobody may switch off, and why.
     *
     * An approval decision about your own listing, or a payout method that
     * needs attention, is not a newsletter — muting it means money stops
     * moving and nobody is told. The API returns these so the dashboard can
     * show them as always-on rather than appearing to have lost a switch.
     */
    public const ALWAYS_ON = [
        'approval' => 'Decisions about your own listings and programs.',
        'wallet'   => 'A payout method or withdrawal that needs you to act.',
    ];

    /**
     * This person's effective preferences: what they chose, defaults elsewhere.
     *
     * @return array<string, bool>
     */
    public static function forUser(?User $user): array
    {
        $saved = is_array($user?->notification_preferences) ? $user->notification_preferences : [];

        $out = [];
        foreach (self::EVENTS as $event => $meta) {
            $out[$event] = array_key_exists($event, $saved)
                ? (bool) $saved[$event]
                : $meta['default'];
        }

        return $out;
    }

    /**
     * A patch reduced to known switches.
     *
     * Unknown keys are refused rather than stored: a client sending
     * `booking_new_` would otherwise be told "saved" and keep getting alerts.
     *
     * @return array{0: ?array<string, bool>, 1: ?array<string, array<int, string>>}
     */
    public static function normalize(?User $user, array $patch): array
    {
        $unknown = array_diff(array_keys($patch), array_keys(self::EVENTS));
        if ($unknown) {
            return [null, ['preferences' => [
                'Unknown notification setting: '.implode(', ', $unknown).'.',
            ]]];
        }

        $errors = [];
        foreach ($patch as $event => $value) {
            if (! is_bool($value) && ! in_array($value, [0, 1, '0', '1', 'true', 'false'], true)) {
                $errors[$event] = ['Choose on or off.'];
            }
        }
        if ($errors) {
            return [null, $errors];
        }

        // Merged over what is already chosen, so a patch naming one switch
        // cannot reset the others to their defaults.
        $merged = self::forUser($user);
        foreach ($patch as $event => $value) {
            $merged[$event] = filter_var($value, FILTER_VALIDATE_BOOLEAN);
        }

        return [$merged, null];
    }

    /**
     * May this alert be delivered to this person?
     *
     * An unrecognised event is delivered. Silence is the dangerous default: a
     * new alert type must not be invisible until somebody remembers to list it.
     */
    public static function allows(?User $user, ?string $event): bool
    {
        if ($event === null || ! array_key_exists($event, self::EVENTS)) {
            return true;
        }

        return self::forUser($user)[$event];
    }

    /** The switches as the dashboard renders them. */
    public static function catalogue(): array
    {
        $out = [];
        foreach (self::EVENTS as $event => $meta) {
            $out[] = [
                'key'     => $event,
                'label'   => $meta['label'],
                'default' => $meta['default'],
            ];
        }

        return $out;
    }
}
