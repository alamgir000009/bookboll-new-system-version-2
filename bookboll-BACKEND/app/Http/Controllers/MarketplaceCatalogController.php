<?php

namespace App\Http\Controllers;

use App\Services\MarketplaceCatalogService;
use App\Services\MarketplaceGeoService;
use Illuminate\Http\Request;

class MarketplaceCatalogController extends Controller
{
    public function __construct(
        protected MarketplaceCatalogService $catalog,
        protected MarketplaceGeoService $geo,
    ) {
    }

    public function listings(Request $request)
    {
        return $this->catalog->listings($request);
    }

    public function venues(Request $request)
    {
        return $this->catalog->venues($request);
    }

    public function venue($id, Request $request)
    {
        return $this->catalog->venue($id, $request);
    }

    public function listing($id, Request $request)
    {
        return $this->catalog->listing($id, $request);
    }

    public function countries(Request $request)
    {
        return $this->geo->countries($request);
    }

    public function cities(Request $request)
    {
        return $this->geo->cities($request);
    }

    public function city($id)
    {
        return $this->geo->city($id);
    }

    public function directory()
    {
        return $this->geo->directory();
    }

    public function defaultCity(Request $request)
    {
        return $this->geo->defaultCity($request);
    }

    public function servedRegions(Request $request)
    {
        return $this->geo->servedRegions($request);
    }
}
