<?php

namespace App\Http\Controllers;

use App\Services\NotificationTemplateService;
use Illuminate\Http\Request;

class BusinessNotificationTemplateController extends Controller
{
    public function __construct(protected NotificationTemplateService $templates) {}

    public function show(Request $request)
    {
        return $this->templates->getBusiness($request);
    }

    public function update(Request $request)
    {
        return $this->templates->saveBusiness($request);
    }
}
