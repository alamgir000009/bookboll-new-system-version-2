<?php
namespace App\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Models\Booking;
use App\Services\BookingService;
use Illuminate\Http\Request;

class BookingController extends Controller
{

    public function __construct(protected BookingService $bookingService)
    {}

    public function index(Request $request)
    {
        return $this->bookingService->all($request);
    }

    public function store(Request $request)
    {
        return $this->bookingService->store($request);
    }

    public function show($booking_id)
    {
        return $this->bookingService->getBooking($booking_id);
    }

    public function update(Request $request, Booking $booking)
    {
        return $this->bookingService->update($booking, $request);
    }

    public function destroy($id)
    {
        return $this->bookingService->delete($id);
    }

    public function cancel(Request $request, $id)
    {
        return $this->bookingService->cancel($request, $id);
    }

    public function dashboardData()
    {
        return $this->bookingService->dashboardData();
    }

    public function dashboardChart(Request $request)
    {
        return $this->bookingService->chartData($request);
    }

    public function formData(Request $request)
    {
        return $this->bookingService->formData($request);
    }

    public function customerDueBookings(Request $request)
    {
        return $this->bookingService->customerDueBookings($request);
    }

    public function bookingsForCalendar(Request $request)
    {
        return $this->bookingService->bookingsForCalendar($request);
    }

    public function customerHistory($customerId)
    {
        return $this->bookingService->customerHistory($customerId);
    }

    public function confirmBooking(Request $request)
    {
        return $this->bookingService->createBooking($request);
    }

    public function upcoming(Request $request)
    {
        return $this->bookingService->upcoming($request);
    }

    public function recent(Request $request)
    {
        return $this->bookingService->recent($request);
    }
}
