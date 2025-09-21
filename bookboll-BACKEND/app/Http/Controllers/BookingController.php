<?php

namespace App\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Services\BookingService;
use App\Models\Booking;
use Illuminate\Http\Request;

class BookingController extends Controller
{

    public function __construct(protected BookingService $bookingService){}

    public function index(Request $request)
    {
        return $this->bookingService->all($request);
    }

    public function store(Request $request)
    {
        return $this->bookingService->create($request);
    }

    public function update(Request $request, Booking $booking)
    {
        return $this->bookingService->update($booking, $request);
    }

    public function destroy($id)
    {
        return $this->bookingService->delete($id);
    }

    public function cancel(Booking $booking)
    {
        return $this->bookingService->cancel($booking);
    }
}
