<?php
namespace App\Http\Controllers;

use App\Models\Venue;
use App\Services\VenueService;
use Illuminate\Http\Request;

class VenueController extends Controller
{
    public function __construct(protected VenueService $venueService){}

    public function index(Request $request)
    {
        return $this->venueService->all($request);
    }
    public function store(Request $request)
    {
        return $this->venueService->create($request);
    }

    public function show($id)
    {
        return $this->venueService->getVenue($id);
    }

    public function update(Request $request, $id)
    {
        return $this->venueService->update($request, $id);
    }

    public function destroy(Venue $venue)
    {
        $venue->delete();
        return sendSuccess('Venue deleted successfull');
    }

    public function fetchVenues(Request $request)
    {
        return $this->venueService->all($request);
    }

    public function sort(Request $request)
    {
        return $this->venueService->updateSortingOrder($request);
    }

}
