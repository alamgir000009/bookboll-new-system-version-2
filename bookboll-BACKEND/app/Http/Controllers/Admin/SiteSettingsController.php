<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Services\SiteSettingsService;
use Illuminate\Http\Request;

/**
 * "My Website" — Site, Home banners, Custom domain, Embed, Blog and Tracking.
 * A provider edits their white-label site; a platform admin edits the marketplace.
 */
class SiteSettingsController extends Controller
{
    public function __construct(protected SiteSettingsService $site)
    {}

    public function show(Request $request)
    {
        return $this->site->show($request);
    }

    public function update(Request $request)
    {
        return $this->site->update($request);
    }
}
