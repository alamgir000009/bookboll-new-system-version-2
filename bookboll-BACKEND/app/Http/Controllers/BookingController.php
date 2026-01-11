<?php
namespace App\Http\Controllers;

use App\Exports\BookingsExport;
use App\Http\Controllers\Controller;
use App\Models\Booking;
use App\Services\BookingService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Maatwebsite\Excel\Facades\Excel;
use BookingPaymentMethod;

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

    public function update(Request $request, $booking_id)
    {
        return $this->bookingService->update($request, $booking_id);
    }

    public function destroy($id)
    {
        return $this->bookingService->delete($id);
    }

    public function cancel(Request $request)
    {
        return $this->bookingService->cancel($request);
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

    public function confirm(Request $request)
    {
        return $this->bookingService->confirm($request);
    }

    public function completed(Request $request)
    {
        return $this->bookingService->completed($request);
    }

    public function generateInvoice(Request $request)
    {
        return $this->bookingService->generateInvoice($request);
    }

    public function upcoming(Request $request)
    {
        return $this->bookingService->upcoming($request);
    }

    public function recent(Request $request)
    {
        return $this->bookingService->recent($request);
    }

    public function validateBooking(Request $request)
    {
        return $this->bookingService->validateBookingRequest($request);
    }

    public function mobileBookingsList(Request $request)
    {
        return $this->bookingService->mobileBookingsList($request);
    }

    public function mobileBookingsFilterOptions(Request $request)
    {
        return $this->bookingService->mobileBookingsFilterOptions($request);
    }

    public function mobileUpdateBooking(Request $request, $booking_id)
    {
        return $this->bookingService->mobileUpdateBooking($request, $booking_id);
    }

    public function export(Request $request)
    {
        $bookings = $this->bookingService->getBookingsForExport($request);
        $filename = 'bookings-' . now()->format('Y-m-d') . '.xlsx';

        return Excel::download(new BookingsExport($bookings), $filename);
    }

    public function getPaymentMethods(Request $request)
    {
        try {
            $user = auth()->user();
            
            if (!$user) {
                return sendError('Unauthorized', [], 401);
            }

            $userRoles = $user->getRoleNames()->toArray();
            
            // Full payment methods for super_admin, admin, and senior_employee
            $fullAccessRoles = ['super_admin', 'admin', 'senior_employee'];
            $hasFullAccess = !empty(array_intersect($userRoles, $fullAccessRoles));
            
            if ($hasFullAccess) {
                // Return all payment methods
                $paymentMethods = BookingPaymentMethod::list();
            } else {
                // Limited payment methods for junior_employee and guard
                // Only "free" and "pay later" (cash_on_arrival)
                $paymentMethods = [
                    BookingPaymentMethod::FREE,
                    BookingPaymentMethod::CASH_ON_ARRIVAL,
                ];
            }

            // Format payment methods with labels for better frontend usage
            $formattedMethods = array_map(function ($method) {
                return [
                    'value' => $method,
                    'label' => ucwords(str_replace('_', ' ', $method)),
                ];
            }, $paymentMethods);

            return sendSuccess('Payment methods fetched successfully', [
                'payment_methods' => $formattedMethods,
            ]);

        } catch (\Exception $e) {
            Log::error('Get Payment Methods Error: ' . $e->getMessage(), ['Exception' => $e]);
            return sendError('Something went wrong while fetching payment methods');
        }
    }
}
