<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Services\NotificationTemplateService;
use Illuminate\Http\Request;

class NotificationTemplateController extends Controller
{
    public function __construct(protected NotificationTemplateService $templates) {}

    public function showPlatform()
    {
        return $this->templates->getPlatform();
    }

    public function updatePlatform(Request $request)
    {
        return $this->templates->savePlatform($request);
    }
}
