<?php
namespace App\Services;

use Exception;
use App\Models\Setting;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class SettingService
{
    public function __construct(protected Setting $settingModel)
    {}

    public function all()
    {
        $settings = $this->settingModel::all(['key', 'value'])
            ->pluck('value', 'key')
            ->toArray();

        return sendSuccess('Settings retrieved successfully', compact('settings'));
    }

    public function update(Request $request)
    {
        try {
            $formData = $request->validate([
                '*.key'   => 'required|string|max:255',
                '*.value' => 'nullable',
            ]);

            DB::beginTransaction();

            $updated = [];
            foreach ($formData as $item) {
                $value = $item['value'];
                if (is_bool($value)) {
                    $value = $value ? '1' : '0';
                } elseif (is_array($value) || is_object($value)) {
                    $value = json_encode($value);
                } else {
                    $value = $value === null ? null : (string) $value;
                }

                $setting = $this->settingModel::updateOrCreate(
                    ['key' => $item['key']],
                    ['value' => $value, 'updated_at' => now()]
                );

                $updated[$item['key']] = $setting->value;

                // Keep Laravel booking lock duration in sync with admin hold timer.
                if ($item['key'] === 'booking_hold_minutes') {
                    $this->settingModel::updateOrCreate(
                        ['key' => 'default_bookings_slot_lock_time'],
                        ['value' => (string) max(1, (int) $value), 'updated_at' => now()]
                    );
                    $updated['default_bookings_slot_lock_time'] = (string) max(1, (int) $value);
                }
            }
            DB::commit();

            return sendSuccess('Settings updated successfully', ['settings' => $updated]);
        } catch (Exception $e) {
            DB::rollBack();
            Log::error('Settings update failed: ' . $e->getMessage());
            return sendError('Settings update failed: ' . $e->getMessage());
        }
    }

    public function updateSingle(Request $request, string $key)
    {
        $request->validate(['value' => 'nullable']);
        $value = $request->value;
        if (is_bool($value)) {
            $value = $value ? '1' : '0';
        } elseif (is_array($value) || is_object($value)) {
            $value = json_encode($value);
        } else {
            $value = $value === null ? null : (string) $value;
        }

        $setting = $this->settingModel::updateOrCreate(
            ['key' => $key],
            ['value' => $value]
        );

        if ($key === 'booking_hold_minutes') {
            $this->settingModel::updateOrCreate(
                ['key' => 'default_bookings_slot_lock_time'],
                ['value' => (string) max(1, (int) $value)]
            );
        }

        return sendSuccess('Setting updated successfully', ['key' => $key, 'value' => $setting->value]);
    }
}
