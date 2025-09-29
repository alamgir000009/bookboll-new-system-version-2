<?php
namespace App\Http\Controllers;

use App\Services\SpecialDayService;
use Carbon\Carbon;
use Illuminate\Http\Request;

class SpecialDayController extends Controller
{
    public function __construct(protected SpecialDayService $specialDayService)
    {}

    public function index(Request $request)
    {
        return $this->specialDayService->all($request);
    }

    public function calendar(Request $request, int $venueId)
    {
        $listingId = $request->query('listing_id');
        $yearMonth = $request->query('month', Carbon::now()->format('Y-m'));

        return $this->specialDayService->calendar($venueId, $listingId, $yearMonth);
    }

    public function store(Request $request)
    {
        return $this->specialDayService->create($request);
    }

    public function update(Request $request, int $id)
    {
        return $this->specialDayService->update($request, $id);
    }

    public function destroy(int $id)
    {
        return $this->specialDayService->delete($id);
    }

    public function bulkCreate(Request $request)
    {
        return $this->specialDayService->bulkCreate($request);
    }
}
