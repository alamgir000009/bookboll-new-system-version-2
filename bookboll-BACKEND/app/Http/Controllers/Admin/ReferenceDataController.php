<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Services\AdminReferenceDataService;
use Illuminate\Http\Request;

/**
 * Providers, venues, listings, cities and countries in one call — already
 * narrowed to what the caller may see. Backs the dashboard's scope filters.
 */
class ReferenceDataController extends Controller
{
    public function __construct(protected AdminReferenceDataService $reference)
    {}

    public function index(Request $request)
    {
        return $this->reference->index($request);
    }
}
