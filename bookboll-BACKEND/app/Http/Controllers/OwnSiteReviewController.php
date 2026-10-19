<?php

namespace App\Http\Controllers;

use App\Services\OwnSiteReviewService;
use Illuminate\Http\Request;

/**
 * The owner review path for own-site listings that take no online bookings.
 *
 * Read the queue, then apply a method the owner names. There is no endpoint
 * that decides for them.
 */
class OwnSiteReviewController extends Controller
{
    public function __construct(protected OwnSiteReviewService $review)
    {
    }

    public function index(Request $request)
    {
        return $this->review->index($request);
    }

    public function apply(Request $request)
    {
        return $this->review->apply($request);
    }
}
