<?php

namespace App\Support;

/**
 * What each row in `settings` means, and how the admin panel should edit it.
 *
 * The table itself carries only a key, a loose `type` string and a text value,
 * which is enough to store a setting but not enough to present one: nothing
 * there says that `cancellation_hours` is a number of hours, that `brand_primary`
 * is a colour, or that editing `default_bookings_slot_lock_time` by hand is
 * pointless because SettingService overwrites it.
 *
 * A key missing from this registry is NOT hidden — the settings page renders it
 * in an "Other" group using the table's own `type` column. That way a key added
 * by code is always editable, and adding it here is an upgrade in presentation
 * rather than a prerequisite for it working at all.
 */
final class SettingsRegistry
{
    /**
     * Groups in the order they appear on the page.
     *
     * @var array<string, array{label: string, description: string}>
     */
    private const GROUPS = [
        'branding' => [
            'label' => 'Branding & identity',
            'description' => 'Names, colours and logos for the marketplace and the admin console.',
        ],
        'booking' => [
            'label' => 'Booking rules',
            'description' => 'Timing and limits applied when a customer books a slot.',
        ],
        'payments' => [
            'label' => 'Payments & policies',
            'description' => 'Payment windows and the policy text customers are shown.',
        ],
        'notifications' => [
            'label' => 'Admin notifications',
            'description' => 'Which events raise an alert for platform staff.',
        ],
        'loyalty' => [
            'label' => 'Loyalty & reminders',
            'description' => 'Win-back emails and the loyalty programme.',
        ],
        'features' => [
            'label' => 'Feature switches',
            'description' => 'Optional modules. Turning one off hides it across the platform.',
        ],
        'other' => [
            'label' => 'Other',
            'description' => 'Keys not yet described in SettingsRegistry. Edited using the type recorded in the database.',
        ],
    ];

    /**
     * key => definition. `input` drives the control; `rules` is applied on save.
     *
     * @var array<string, array<string, mixed>>
     */
    private const SETTINGS = [
        // ---- Branding -----------------------------------------------------
        'platform_name' => [
            'group' => 'branding', 'input' => 'text', 'label' => 'Platform name',
            'help' => 'Used in emails and page titles.', 'rules' => ['nullable', 'string', 'max:120'],
        ],
        'platform_tagline' => [
            'group' => 'branding', 'input' => 'text', 'label' => 'Platform tagline',
            'rules' => ['nullable', 'string', 'max:200'],
        ],
        'brand_name' => [
            'group' => 'branding', 'input' => 'text', 'label' => 'Brand name',
            'help' => 'Shown on the customer-facing marketplace.', 'rules' => ['nullable', 'string', 'max:120'],
        ],
        'brand_tagline' => [
            'group' => 'branding', 'input' => 'text', 'label' => 'Brand tagline',
            'rules' => ['nullable', 'string', 'max:200'],
        ],
        'brand_primary' => [
            'group' => 'branding', 'input' => 'color', 'label' => 'Brand primary colour',
            'rules' => ['nullable', 'string', 'regex:/^#[0-9A-Fa-f]{6}$/'],
        ],
        'brand_accent' => [
            'group' => 'branding', 'input' => 'color', 'label' => 'Brand accent colour',
            'rules' => ['nullable', 'string', 'regex:/^#[0-9A-Fa-f]{6}$/'],
        ],
        'brand_logo_url' => [
            'group' => 'branding', 'input' => 'url', 'label' => 'Brand logo URL',
            'rules' => ['nullable', 'url', 'max:2048'],
        ],
        'admin_primary' => [
            'group' => 'branding', 'input' => 'color', 'label' => 'Admin console colour',
            'rules' => ['nullable', 'string', 'regex:/^#[0-9A-Fa-f]{6}$/'],
        ],
        'admin_logo_url' => [
            'group' => 'branding', 'input' => 'url', 'label' => 'Admin logo URL',
            'rules' => ['nullable', 'url', 'max:2048'],
        ],
        'brand_meta_title' => [
            'group' => 'branding', 'input' => 'text', 'label' => 'SEO meta title',
            'rules' => ['nullable', 'string', 'max:70'],
            'help' => 'Search engines truncate past about 60 characters.',
        ],
        'brand_meta_description' => [
            'group' => 'branding', 'input' => 'textarea', 'label' => 'SEO meta description',
            'rules' => ['nullable', 'string', 'max:320'],
            'help' => 'Search engines truncate past about 160 characters.',
        ],
        'platform_default_currency' => [
            'group' => 'branding', 'input' => 'text', 'label' => 'Default currency',
            'help' => 'Three-letter ISO code, e.g. AED or QAR. Applied where a country has none of its own.',
            'rules' => ['nullable', 'string', 'size:3', 'regex:/^[A-Za-z]{3}$/'],
            'transform' => 'upper',
        ],

        // ---- Booking rules ------------------------------------------------
        'auto_confirm' => [
            'group' => 'booking', 'input' => 'boolean', 'label' => 'Auto-confirm paid bookings',
            'help' => 'Off means every booking waits for manual confirmation.',
        ],
        'booking_hold_minutes' => [
            'group' => 'booking', 'input' => 'number', 'label' => 'Checkout hold', 'unit' => 'minutes',
            'help' => 'How long a slot is reserved while the customer pays. Also sets the slot lock time below.',
            'rules' => ['required', 'integer', 'min:1', 'max:120'],
        ],
        'default_bookings_slot_lock_time' => [
            'group' => 'booking', 'input' => 'number', 'label' => 'Slot lock time', 'unit' => 'minutes',
            'readonly' => true,
            'readonly_reason' => 'Kept in step with the checkout hold above — editing it here would be overwritten on the next save.',
        ],
        'min_advance_hours' => [
            'group' => 'booking', 'input' => 'number', 'label' => 'Minimum advance notice', 'unit' => 'hours',
            'help' => 'A slot starting sooner than this can no longer be booked.',
            'rules' => ['nullable', 'integer', 'min:0', 'max:720'],
        ],
        'cancellation_hours' => [
            'group' => 'booking', 'input' => 'number', 'label' => 'Free cancellation window', 'unit' => 'hours',
            'help' => 'Cancellations earlier than this are refundable.',
            'rules' => ['nullable', 'integer', 'min:0', 'max:720'],
        ],
        'max_per_slot' => [
            'group' => 'booking', 'input' => 'number', 'label' => 'Maximum bookings per slot',
            'rules' => ['nullable', 'integer', 'min:1', 'max:1000'],
        ],
        'days_until_next_month_bookings_open' => [
            'group' => 'booking', 'input' => 'number', 'label' => 'Next month opens at', 'unit' => 'days remaining',
            'help' => 'Next month\'s slots go on sale once this many days or fewer remain in the current month.',
            'rules' => ['nullable', 'integer', 'min:0', 'max:31'],
        ],

        // ---- Payments ------------------------------------------------------
        'deposit_payment_timeout_minutes' => [
            'group' => 'payments', 'input' => 'number', 'label' => 'Deposit payment timeout', 'unit' => 'minutes',
            'help' => 'An unpaid deposit booking is cancelled after this long.',
            'rules' => ['nullable', 'integer', 'min:1', 'max:1440'],
        ],
        'payment_timeout_grace_minutes' => [
            'group' => 'payments', 'input' => 'number', 'label' => 'Timeout grace period', 'unit' => 'minutes',
            'help' => 'Added to the timeout before anything is auto-cancelled.',
            'rules' => ['nullable', 'integer', 'min:0', 'max:120'],
        ],
        'noshow_policy' => [
            'group' => 'payments', 'input' => 'textarea', 'label' => 'No-show policy',
            'help' => 'Shown to customers at checkout.', 'rules' => ['nullable', 'string', 'max:2000'],
        ],
        'trial_cancel_policy' => [
            'group' => 'payments', 'input' => 'textarea', 'label' => 'Trial cancellation policy',
            'rules' => ['nullable', 'string', 'max:2000'],
        ],
        'stripe_catalog_prices' => [
            'group' => 'payments', 'input' => 'textarea', 'label' => 'Stripe catalogue mapping',
            'readonly' => true,
            'readonly_reason' => 'Written by `php artisan stripe:sync-catalog` and read by App\\Support\\StripeCatalog. Editing it by hand breaks the product mapping.',
        ],

        // ---- Notifications --------------------------------------------------
        'notify_new_booking' => [
            'group' => 'notifications', 'input' => 'boolean', 'label' => 'New booking',
        ],
        'notify_payment_failed' => [
            'group' => 'notifications', 'input' => 'boolean', 'label' => 'Payment failed',
        ],
        'notify_provider_signup' => [
            'group' => 'notifications', 'input' => 'boolean', 'label' => 'Provider signed up',
        ],
        'notify_daily_summary' => [
            'group' => 'notifications', 'input' => 'boolean', 'label' => 'Daily summary email',
        ],

        // ---- Loyalty & reminders ---------------------------------------------
        'loyalty' => [
            'group' => 'loyalty', 'input' => 'boolean', 'label' => 'Loyalty programme',
            'help' => 'Off hides points and tiers everywhere.',
        ],
        'reminder_emails_after_days' => [
            'group' => 'loyalty', 'input' => 'number', 'label' => 'Win-back email after', 'unit' => 'days',
            'help' => 'Days of customer inactivity before a reminder is sent.',
            'rules' => ['nullable', 'integer', 'min:1', 'max:365'],
        ],
        'reminder_promocode_discount_percentage' => [
            'group' => 'loyalty', 'input' => 'number', 'label' => 'Win-back discount', 'unit' => '%',
            'rules' => ['nullable', 'integer', 'min:0', 'max:100'],
        ],
        'reminder_promocode_validity_days' => [
            'group' => 'loyalty', 'input' => 'number', 'label' => 'Win-back code valid for', 'unit' => 'days',
            'rules' => ['nullable', 'integer', 'min:1', 'max:365'],
        ],

        // ---- Feature switches --------------------------------------------------
        'special_days' => [
            'group' => 'features', 'input' => 'boolean', 'label' => 'Special days pricing',
        ],
        'resource_management' => [
            'group' => 'features', 'input' => 'boolean', 'label' => 'Resource management',
        ],
        'addons' => [
            'group' => 'features', 'input' => 'boolean', 'label' => 'Add-ons',
        ],
    ];

    /** @return array<string, array{label: string, description: string}> */
    public static function groups(): array
    {
        return self::GROUPS;
    }

    public static function isKnown(string $key): bool
    {
        return array_key_exists($key, self::SETTINGS);
    }

    /**
     * The full definition for a key, with defaults filled in. An unknown key
     * falls back to the `type` recorded against it in the database.
     */
    public static function describe(string $key, ?string $storedType = null): array
    {
        $definition = self::SETTINGS[$key] ?? [
            'group' => 'other',
            'input' => self::inputForStoredType($storedType),
            'label' => self::humanise($key),
        ];

        return $definition + [
            'group' => 'other',
            'input' => 'text',
            'label' => self::humanise($key),
            'help' => null,
            'unit' => null,
            'rules' => ['nullable', 'string', 'max:65535'],
            'readonly' => false,
            'readonly_reason' => null,
            'transform' => null,
        ];
    }

    /** Validation rules for a key, tightened for booleans and numbers. */
    public static function rulesFor(string $key, ?string $storedType = null): array
    {
        $definition = self::describe($key, $storedType);

        return match ($definition['input']) {
            'boolean' => ['required', 'boolean'],
            'number' => $definition['rules'] === ['nullable', 'string', 'max:65535']
                ? ['nullable', 'integer']
                : $definition['rules'],
            default => $definition['rules'],
        };
    }

    /** The control to render for a `type` column value the registry doesn't describe. */
    private static function inputForStoredType(?string $storedType): string
    {
        return match (strtolower((string) $storedType)) {
            'boolean', 'bool' => 'boolean',
            'integer', 'int', 'number' => 'number',
            'text', 'json' => 'textarea',
            default => 'text',
        };
    }

    /** 'brand_meta_title' => 'Brand meta title' */
    private static function humanise(string $key): string
    {
        return ucfirst(str_replace('_', ' ', $key));
    }
}
