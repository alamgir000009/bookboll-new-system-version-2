<?php
namespace App\Http\Controllers;

use App\Models\AddOn;
use App\Models\Listing;
use App\Models\Resource;
use App\Models\SportsCategory;
use App\Models\User;
use App\Models\Venue;

class HelperController extends Controller
{
    public function extraData()
    {
        $venues   = Venue::with(['listings:id,venue_id,name', 'schedules'])->get(['id', 'name']);
        $listings = Listing::with(['venue'])->get(['id', 'name', 'venue_id']);
        return sendSuccess('Data fetched successfully', compact('venues', 'listings'));
    }

    public function extraCustomers()
    {
        $customers = User::role('customer')->get(['id', 'name']);
        return sendSuccess('Data fetched successfully', compact('customers'));
    }

    public function extraResources()
    {
        $resources = Resource::latest()->get();
        return sendSuccess('Data fetched successfully', compact('resources'));
    }

    public function extraAddOns()
    {
        $addons = AddOn::latest()->get();
        return sendSuccess('Data fetched successfully', compact('addons'));
    }
    public function extraSportCategories()
    {
        $sportCategories = SportsCategory::latest()->get();
        return sendSuccess('Data fetched successfully', compact('sportCategories'));
    }

    public function extraDataForListing()
    {
        $addons          = AddOn::latest()->get();
        $resources       = Resource::latest()->get();
        $sportCategories = SportsCategory::latest()->get();
        $venues          = Venue::with(['listings:id,venue_id,name', 'schedules'])->get(['id', 'name']);

        return sendSuccess('Data fetched successfully', compact('venues', 'resources', 'addons', 'sportCategories'));
    }

}
