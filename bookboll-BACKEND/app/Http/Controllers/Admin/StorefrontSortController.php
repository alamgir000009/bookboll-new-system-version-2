<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Services\StorefrontSortService;
use Illuminate\Http\Request;

/** Fallback ordering for the customer storefront (Listings → customer site order). */
class StorefrontSortController extends Controller
{
    public function __construct(protected StorefrontSortService $sort)
    {}

    public function show(Request $request)
    {
        return $this->sort->show($request);
    }

    public function update(Request $request)
    {
        return $this->sort->update($request);
    }
}
