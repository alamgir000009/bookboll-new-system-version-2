<?php
namespace App\Http\Controllers;

use App\Services\SettingService;
use Illuminate\Http\Request;

class SettingController extends Controller
{
    public function __construct(protected SettingService $settingService)
    {}

    public function index()
    {
        return $this->settingService->all();
    }

    public function update(Request $request)
    {
        return $this->settingService->update($request);
    }

    public function updateSingle(Request $request, string $key)
    {
        return $this->settingService->updateSingle($request, $key);
    }
}
