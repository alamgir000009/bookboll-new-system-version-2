<?php
namespace App\Http\Controllers;

use App\Models\Venue;
use App\Services\VenueService;
use Illuminate\Http\Request;

class VenueController extends Controller
{
    public function __construct(
        protected VenueService $venueService,
        protected \App\Services\VenueAgreementService $agreements,
    ) {}

    /* Operating agreements — what Bookboll owes a landlord, over time. */

    public function operatingAgreements(Request $request, $id)
    {
        return $this->agreements->index($request, (int) $id);
    }

    public function storeOperatingAgreement(Request $request, $id)
    {
        return $this->agreements->store($request, (int) $id);
    }

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
        return $this->venueService->all($request, false);
    }
    public function adminFetchVenues(Request $request)
    {
        return $this->venueService->all($request);
    }

    public function sort(Request $request)
    {
        return $this->venueService->updateSortingOrder($request);
    }

    public function move(Request $request, $id)
    {
        return $this->venueService->moveOnePosition($id, $request);
    }

    // ── New admin dashboard (apps/admin) ──────────────────────────────────────

    public function dashboard(Request $request)
    {
        return $this->venueService->dashboardIndex($request);
    }

    public function dashboardShow($id)
    {
        return $this->venueService->dashboardShow($id);
    }

    public function dashboardStore(Request $request)
    {
        return $this->venueService->dashboardStore($request);
    }

    public function dashboardUpdate(Request $request, $id)
    {
        return $this->venueService->dashboardUpdate($request, $id);
    }

    public function dashboardDestroy($id)
    {
        return $this->venueService->dashboardDestroy($id);
    }

    public function dashboardPatch(Request $request, $id)
    {
        return $this->venueService->dashboardPatch($request, $id);
    }

    public function dashboardSort(Request $request)
    {
        return $this->venueService->dashboardSort($request);
    }

    public function dashboardApplyHours(Request $request, $id)
    {
        return $this->venueService->applyHoursToListings($request, $id);
    }
}
