<?php

namespace App\Http\Controllers;

use App\Services\ProviderSettingsService;
use Illuminate\Http\Request;

/**
 * Settings → Business Profile / My Account / Notifications.
 *
 * Every action acts on the signed-in person and the business they own or
 * belong to. None takes an id, so there is nothing to tamper with.
 */
class ProviderSettingsController extends Controller
{
    public function __construct(protected ProviderSettingsService $settings)
    {
    }

    public function show(Request $request)
    {
        return $this->settings->show($request);
    }

    public function updateBusiness(Request $request)
    {
        return $this->settings->updateBusiness($request);
    }

    public function updateAccount(Request $request)
    {
        return $this->settings->updateAccount($request);
    }

    public function updateNotifications(Request $request)
    {
        return $this->settings->updateNotifications($request);
    }
}
