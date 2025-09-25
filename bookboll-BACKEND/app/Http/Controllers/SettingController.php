<?php
namespace App\Http\Controllers;

use App\Services\SettingService;
use Exception;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;

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

    public function sendTestEmail(Request $request)
    {
        try {
            $validated = $request->validate(['email' => 'required|email']);
            Mail::raw('This is a test email from your application.', function ($message) use ($validated) {
                $message->to($validated['email'])
                    ->subject('Test Email');
            });
            return sendSuccess('Test email sent successfully', []);
        } catch (Exception $e) {
            Log::error('Failed to send test email: ' . $e->getMessage());
            return sendError('Failed to send test email: ' . $e->getMessage());
        }
    }
}
