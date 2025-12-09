<?php
namespace App\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Services\ListingService;
use Illuminate\Http\Request;

class ListingController extends Controller
{
    public function __construct(protected ListingService $listingService)
    {}

    public function index(Request $request)
    {
        return $this->listingService->all($request);
    }

    public function store(Request $request)
    {
        return $this->listingService->create($request);
    }

    public function show($id)
    {
        return $this->listingService->show($id);
    }

    public function update(Request $request, $id)
    {
        return $this->listingService->update($request, $id);
    }

    public function destroy($id)
    {
        return $this->listingService->delete($id);
    }

    public function fetchListings(Request $request)
    {
        return $this->listingService->fetchListings($request);
    }

    public function fetchSuggestedListings(Request $request)
    {
        return $this->listingService->fetchSuggestions($request);
    }

    public function fetchListing($id)
    {
        return $this->listingService->listing($id);
    }

    public function fetchTimeSlots(Request $request)
    {
        return $this->listingService->fetchTimeSlots($request);
    }

    public function fetchAddons($listing_id)
    {
        return $this->listingService->fetchAddons($listing_id);
    }

    public function fetchRecurringBookedSlots(Request $request)
    {
        return $this->listingService->fetchRecurringBookedSlots($request);
    }

    public function sort(Request $request)
    {
        return $this->listingService->updateSortOrder($request);
    }

    public function uploadImage(Request $request, $id)
    {
        return $this->listingService->uploadImage($request, $id);
    }

    public function deleteImage(Request $request, $id)
    {
        return $this->listingService->deleteImage($request, $id);
    }

    public function lockSlot(Request $request)
    {
        return $this->listingService->lockSlot($request);
    }

    public function unlockSlot(Request $request)
    {
        return $this->listingService->unlockSlot($request);
    }

    public function unlockAllSlots(Request $request)
    {
        return $this->listingService->unlockAllSlots($request);
    }

    public function extendSlotLock(Request $request)
    {
        return $this->listingService->extendSlotLock($request);
    }

    public function checkSlotAvailability(Request $request)
    {
        return $this->listingService->checkSlotAvailability($request);
    }

    public function mobileListingsList(Request $request)
    {
        return $this->listingService->mobileListingsList($request);
    }
}
