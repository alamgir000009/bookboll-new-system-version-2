<?php
namespace App\Services;

use App\Models\Setting;
use Exception;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class SettingService
{
    private string $cacheKey  = 'settings_all';
    private int $cacheMinutes = 60;

    public function __construct(protected Setting $settingModel)
    {}

    public function all()
    {
        try {
            $settings = Cache::remember($this->cacheKey, $this->cacheMinutes * 60, function () {
                return $this->settingModel::all(['key', 'type', 'description', 'encrypted'])
                    ->map(function ($setting) {
                        $rawValue = $setting->getRawValueAttribute();

                        return [
                            'key'         => $setting->key,
                            'value'       => $setting->encrypted && ! empty($rawValue)
                                ? $this->maskValue($rawValue)
                                : $setting->value,
                            'type'        => $setting->type,
                            'description' => $setting->description,
                            'encrypted'   => $setting->encrypted,
                            'masked'      => $setting->encrypted && ! empty($rawValue),
                        ];
                    })
                    ->keyBy('key')
                    ->toArray();
            });

            $groupedSettings = $this->groupSettings($settings);

            return sendSuccess('Settings retrieved successfully', ['settings' => $groupedSettings]);

        } catch (Exception $e) {
            Log::error('Failed to retrieve settings: ' . $e->getMessage());
            return sendError('Semething went wrong ' . $e->getMessage());
        }
    }

    public function update(Request $request)
    {
        $validated = $request->validate([
            // Stripe settings
            'stripe_publishable_key'      => 'required|string|max:255',
            'stripe_secret_key'           => 'required|string|max:255',

            // Booking settings
            'booking_min_advance_hours'   => 'required|integer|min:0|max:168',
            'booking_max_per_slot'        => 'required|integer|min:1|max:100',
            'booking_auto_confirm'        => 'required|boolean',

            // Module settings
            'modules_loyalty'             => 'required|boolean',
            'modules_special_days'        => 'required|boolean',
            'modules_addons'              => 'required|boolean',
            'modules_resource_management' => 'required|boolean',
        ]);

        try {

            DB::beginTransaction();

            $settingsData = [
                'stripe.publishable_key'      => $validated['stripe_publishable_key'],
                'stripe.secret_key'           => $validated['stripe_secret_key'],
                'booking.min_advance_hours'   => (int) $validated['booking_min_advance_hours'],
                'booking.max_per_slot'        => (int) $validated['booking_max_per_slot'],
                'booking.auto_confirm'        => (bool) $validated['booking_auto_confirm'],
                'modules.loyalty'             => (bool) $validated['modules_loyalty'],
                'modules.special_days'        => (bool) $validated['modules_special_days'],
                'modules.addons'              => (bool) $validated['modules_addons'],
                'modules.resource_management' => (bool) $validated['modules_resource_management'],
            ];

            $updated = [];

            foreach ($settingsData as $key => $value) {
                $this->validateValueByKey($key, $value);

                $setting = $this->settingModel::updateOrCreate(
                    ['key' => $key],
                    [
                        'value'      => $value,
                        'type'       => $this->detectType($value),
                        'updated_at' => now(),
                    ]
                );

                $this->settingModel::forgetCache($key);

                $updated[] = [
                    'key'   => $key,
                    'value' => $setting->value,
                    'type'  => $setting->type,
                ];
            }

            $this->settingModel::clearAllCache();
            Cache::forget($this->cacheKey);

            if (isset($validated['stripe_secret_key'])) {
                $this->testStripeConnection($validated['stripe_secret_key']);
            }

            DB::commit();

            return sendSuccess("Settings updated successfully", compact('updated'));

        } catch (Exception $e) {
            DB::rollBack();
            Log::error('Settings update failed: ' . $e->getMessage());
            return sendError('Something went wrong '. $e->getMessage());
        }
    }

    public function updateSingle(Request $request, string $key)
    {
        try {
            $validated = $request->validate([
                'value' => 'required',
            ]);

            $value = $validated['value'];

            $this->validateValueByKey($key, $value);

            return DB::transaction(function () use ($key, $value) {

                $setting = $this->settingModel::updateOrCreate(
                    ['key' => $key],
                    [
                        'value'      => $value,
                        'type'       => $this->detectType($value),
                        'updated_at' => now(),
                    ]
                );

                $this->settingModel::forgetCache($key);
                Cache::forget($this->cacheKey);

                if (str_contains($key, 'stripe.secret_key')) {
                    try {
                        $this->testStripeConnection($value);
                        return sendSuccess('Setting updated and Stripe connection verified successfully', [
                            'key'   => $key,
                            'value' => $setting->value,
                            'type'  => $setting->type,
                        ]);
                    } catch (Exception $stripeError) {
                        sendError('Setting updated but Stripe connection failed: ' . $stripeError->getMessage());
                    }
                }

                return sendSuccess('Setting updated successfully', [
                    'key'   => $key,
                    'value' => $setting->value,
                    'type'  => $setting->type,
                ]);
            });

        } catch (Exception $e) {
            DB::rollBack();
            Log::error('Single setting update failed: ' . $e->getMessage());
            return sendError('Single setting update failed: ' . $e->getMessage());
        }
    }

    private function testStripeConnection(string $secretKey): void
    {
        try {
            if (! class_exists(\Stripe\Stripe::class)) {
                throw new Exception('Stripe PHP SDK not installed');
            }

            \Stripe\Stripe::setApiKey($secretKey);
            \Stripe\Account::retrieve();

        } catch (\Stripe\Exception\ApiErrorException $e) {
            throw new Exception('Stripe API Error: ' . $e->getMessage());
        } catch (Exception $e) {
            throw new Exception('Stripe connection failed: ' . $e->getMessage());
        }
    }

    private function validateValueByKey(string $key, $value): void
    {
        $rules = [
            'stripe.publishable_key'      => fn()      => is_string($value) && strlen($value) <= 255,
            'stripe.secret_key'           => fn()           => is_string($value) && strlen($value) <= 255,

            'booking.min_advance_hours'   => fn()   => is_numeric($value) && $value >= 0 && $value <= 168,
            'booking.max_per_slot'        => fn()        => is_numeric($value) && $value >= 1 && $value <= 100,

            'booking.auto_confirm'        => fn()        => is_bool($value) || (is_numeric($value) && in_array($value, [0, 1])),

            'modules.loyalty'             => fn()             => is_bool($value) || (is_numeric($value) && in_array($value, [0, 1])),
            'modules.special_days'        => fn()        => is_bool($value) || (is_numeric($value) && in_array($value, [0, 1])),
            'modules.addons'              => fn()              => is_bool($value) || (is_numeric($value) && in_array($value, [0, 1])),
            'modules.resource_management' => fn() => is_bool($value) || (is_numeric($value) && in_array($value, [0, 1])),
        ];

        $validator = $rules[$key] ?? fn() => true;

        if (! $validator()) {
            $type = $this->getSettingType($key);
            throw new InvalidArgumentException("Invalid value for {$key}. Expected " . $this->getExpectedType($type));
        }
    }

    private function getSettingType(string $key): string
    {
        $typeMap = [
            'stripe.publishable_key'      => 'string',
            'stripe.secret_key'           => 'string',
            'booking.min_advance_hours'   => 'integer',
            'booking.max_per_slot'        => 'integer',
            'booking.auto_confirm'        => 'boolean',
            'modules.loyalty'             => 'boolean',
            'modules.special_days'        => 'boolean',
            'modules.addons'              => 'boolean',
            'modules.resource_management' => 'boolean',
        ];

        return $typeMap[$key] ?? 'string';
    }

    private function getExpectedType(string $type): string
    {
        return match ($type) {
            'boolean' => 'boolean (true/false or 1/0)',
            'integer' => 'integer',
            'string'  => 'string (max 255 chars)',
            default   => 'valid value',
        };
    }

    private function groupSettings(array $settings): array
    {
        return [
            'payment' => [
                'stripe_publishable_key'        => $settings['stripe.publishable_key']['value'] ?? '',
                'stripe_secret_key'             => $settings['stripe.secret_key']['value'] ?? '',
                'stripe_publishable_key_masked' => $settings['stripe.publishable_key']['masked'] ?? false,
                'stripe_secret_key_masked'      => $settings['stripe.secret_key']['masked'] ?? false,
            ],
            'booking' => [
                'min_advance_hours' => $settings['booking.min_advance_hours']['value'] ?? 2,
                'max_per_slot'      => $settings['booking.max_per_slot']['value'] ?? 1,
                'auto_confirm'      => (bool) ($settings['booking.auto_confirm']['value'] ?? true),
            ],
            'modules' => [
                'loyalty'             => (bool) ($settings['modules.loyalty']['value'] ?? true),
                'special_days'        => (bool) ($settings['modules.special_days']['value'] ?? true),
                'addons'              => (bool) ($settings['modules.addons']['value'] ?? false),
                'resource_management' => (bool) ($settings['modules.resource_management']['value'] ?? false),
            ],
        ];
    }

    private function maskValue(?string $value): string
    {
        if (empty($value)) {
            return '';
        }

        $length = strlen($value);
        if ($length <= 8) {
            return str_repeat('*', $length);
        }

        return substr($value, 0, 4) . str_repeat('*', $length - 8) . substr($value, -4);
    }

    private function detectType($value): string
    {
        if (is_bool($value)) {
            return 'boolean';
        }

        if (is_int($value)) {
            return 'integer';
        }

        if (is_array($value)) {
            return 'json';
        }

        return 'string';
    }
}
