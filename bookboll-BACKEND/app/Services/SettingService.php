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
                '*.value' => 'required',
            ]);

            DB::beginTransaction();

            $updated = [];
            foreach ($formData as $item) {
                $setting = $this->settingModel::updateOrCreate(
                    ['key' => $item['key']],
                    ['value' => $item['value'], 'updated_at' => now()]
                );

                $updated[$item['key']] = $setting->value;
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
        $request->validate(['value' => 'required']);
        $value = $request->value;

        $setting = $this->settingModel::updateOrCreate(
            ['key' => $key],
            ['value' => $value]);

        return sendSuccess('Setting updated successfully', compact('key', 'value'));
    }
}
