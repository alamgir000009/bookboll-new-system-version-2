<?php
namespace App\Http\Controllers;

use App\Models\Listing;
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
        $customers = User::role('customer')->get(['id', 'name']); // ✅ use role() instead of hasRole()
        return sendSuccess('Data fetched successfully', compact('customers'));
    }

}
