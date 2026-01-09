<?php
namespace App\Imports;

use App\Models\AddOn;
use App\Models\Booking;
use App\Models\Listing;
use App\Models\Payment;
use App\Models\User;
use App\Models\Venue;
use App\Services\BookingService;
use BookingPaymentMethod;
use BookingPaymentStatus;
use BookingStatus;
use Carbon\Carbon;
use Illuminate\Support\Collection;
use Log;
use Maatwebsite\Excel\Concerns\ToCollection;
use Maatwebsite\Excel\Concerns\WithHeadingRow;
use PaymentMethod;
use PaymentStatus;
use PaymentType;

class BookingsImport implements ToCollection, WithHeadingRow
{
    protected $errors       = [];
    protected $successCount = 0;
    protected $errorCount   = 0;
    protected $bookingService;

    public function __construct(BookingService $bookingService = null)
    {
        $this->bookingService = $bookingService ?? app(BookingService::class);
    }

    public function collection(Collection $rows)
    {
        foreach ($rows as $index => $row) {
            try {
                // Skip empty rows
                if (empty(array_filter($row->toArray()))) {
                    continue;
                }

                $this->importRow($row, $index + 2); // +2 because heading row is 1, and we start from 2
            } catch (\Exception $e) {
                $this->errorCount++;
                $this->errors[] = [
                    'row'   => $index + 2,
                    'error' => $e->getMessage(),
                ];
            }
        }
    }

    protected function importRow($row, $rowNumber)
    {
        // Get customer email (required) - support both customer_email and user_email
        $customerEmail = $this->getValue($row, 'user_email');
        if (empty($customerEmail)) {
            Log::error("Row {$rowNumber}: Customer email is required");
            $customerEmail = "systemuser@bookboll.com";
        }

        // Find or create user
        $user = User::where('email', $customerEmail)->first();
        if (! $user) {
            // Support both customer_name/user_name and customer_phone/user_phone
            $customerName = $this->getValue($row, 'user_name');
            if (empty($customerName)) {
                // Extract name from email if not provided
                $emailParts   = explode('@', $customerEmail);
                $customerName = $emailParts[0] ?? 'Imported Customer';
            }

            $customerPhone = $this->getValue($row, 'user_phone');

            // Clean phone number - remove quotes, plus signs, and extra spaces
            $customerPhone = trim($customerPhone, "'\"+ ");

            $user = User::create([
                'name'     => $customerName,
                'email'    => $customerEmail,
                'phone'    => $customerPhone,
                'status'   => User::ACTIVE,
            ]);

            // Assign customer role
            if (! $user->hasRole('customer')) {
                $user->assignRole('customer');
            }
        }

        // Find venue by name
        $venueName = $this->getValue($row, 'venue');
        if (empty($venueName)) {
            throw new \Exception('Venue is required');
        }

        $venue = Venue::where('name', $venueName)->first();
        if (! $venue) {
            throw new \Exception("Venue '{$venueName}' not found");
        }

        // Find listing by name
        $listingName = $this->getValue($row, 'listing');
        if (empty($listingName)) {
            throw new \Exception('Listing is required');
        }

        $listing = Listing::where('name', $listingName)
            ->where('venue_id', $venue->id)
            ->first();

        if (! $listing) {
            throw new \Exception("Listing '{$listingName}' not found for venue '{$venueName}'");
        }

        // Parse date
        $date = $this->getValue($row, 'date');
        if (empty($date)) {
            throw new \Exception('Date is required');
        }

        try {

            $date = trim($date);

            // Check for DD-MM-YYYY (e.g. 31/01/2026)
            if (preg_match('/^(\d{2})\/(\d{2})\/(\d{4})$/', $date, $matches)) {

                // matches[1] = DD
                // matches[2] = MM
                // matches[3] = YYYY
                $date = Carbon::createFromFormat('d/m/Y', $date)->format('Y-m-d');

            } else {
                // Otherwise let Carbon handle valid formats like YYYY-MM-DD
                $date = Carbon::parse($date)->format('Y-m-d');
            }

        } catch (\Throwable $e) {

            throw new \Exception("Invalid date format: {$date}");
        }

        // Parse times - support both separate start_time/end_time and combined time field
        $startTime = $this->getValue($row, 'start_time');
        $endTime   = $this->getValue($row, 'end_time');

        // If separate times are not provided, try to parse from combined time field
        if (empty($startTime) || empty($endTime)) {
            $timeString = $this->getValue($row, 'time');
            if (! empty($timeString)) {
                // Handle different separators: " - ", " -", "- ", or just "-"
                if (strpos($timeString, ' - ') !== false) {
                    $timeParts = explode(' - ', $timeString);
                } elseif (strpos($timeString, ' -') !== false) {
                    $timeParts = explode(' -', $timeString);
                } elseif (strpos($timeString, '- ') !== false) {
                    $timeParts = explode('- ', $timeString);
                } elseif (strpos($timeString, '-') !== false) {
                    $timeParts = explode('-', $timeString);
                } else {
                    $timeParts = [];
                }

                if (count($timeParts) === 2) {
                    $startTime = trim($timeParts[0]);
                    $endTime   = trim($timeParts[1]);
                }
            }
        }

        if (empty($startTime) || empty($endTime)) {
            throw new \Exception('Start time and end time are required');
        }

        // Convert 12-hour format (with AM/PM) to 24-hour format
        try {
            // Try parsing with AM/PM format first (e.g., "01:00 AM", "11:00 AM")
            $startTimeParsed = Carbon::createFromFormat('h:i A', trim($startTime));
            $endTimeParsed   = Carbon::createFromFormat('h:i A', trim($endTime));

            $startTime = $startTimeParsed->format('H:i');
            $endTime   = $endTimeParsed->format('H:i');
        } catch (\Exception $e) {
            // If AM/PM format fails, try parsing as 24-hour format or other formats
            try {
                $startTimeParsed = Carbon::parse(trim($startTime));
                $endTimeParsed   = Carbon::parse(trim($endTime));

                $startTime = $startTimeParsed->format('H:i');
                $endTime   = $endTimeParsed->format('H:i');
            } catch (\Exception $e2) {
                throw new \Exception("Invalid time format: {$startTime} - {$endTime}. Error: " . $e2->getMessage());
            }
        }

        // Parse status - check is_cancelled field if status is not provided
        $statusValue = $this->getValue($row, 'status');
        if (empty($statusValue)) {
            $isCancelled = strtolower(trim($this->getValue($row, 'is_cancelled', '')));
            if ($isCancelled === 'yes' || $isCancelled === 'true' || $isCancelled === '1') {
                $statusValue = 'cancelled';
            } else {
                $statusValue = 'pending';
            }
        }
        $status = $this->normalizeStatus($statusValue);

        // Parse payment status
        $paymentStatus = $this->normalizePaymentStatus($this->getValue($row, 'payment_status', 'pending'));

        // Parse payment method - support both payment_method and payment_processor
        $paymentMethodValue = $this->getValue($row, 'payment_method') ?: $this->getValue($row, 'payment_processor', 'cash_on_arrival');
        $paymentMethod      = $this->normalizePaymentMethod($paymentMethodValue);

        // Parse prices - support both price/amount and subtotal/total_price
        $subtotal       = $this->parseDecimal($this->getValue($row, 'subtotal')) ?: $this->parseDecimal($this->getValue($row, 'price'), 0);
        $discountAmount = $this->parseDecimal($this->getValue($row, 'discount_amount', 0));
        $totalPrice     = $this->parseDecimal($this->getValue($row, 'total_price')) ?: $this->parseDecimal($this->getValue($row, 'amount', $subtotal));

        // Get add-ons IDs - support both add_ons and service_add_ons
        $addOnIds     = [];
        $addOnsString = $this->getValue($row, 'service_add_ons');
        if (! empty($addOnsString)) {
            // Split by comma first, then clean each add-on name
            $addOnNames = array_map('trim', explode(',', $addOnsString));

            foreach ($addOnNames as $addOnName) {
                // Extract name before dash or parentheses (e.g., "Click & Add Hour! (60mins.) - 100.00 QAR" -> "Click & Add Hour!")
                $cleanName = trim(preg_split('/\s*-\s*|\s*\(/', $addOnName)[0]);

                // Try exact match first
                $addOn = AddOn::where('name', $cleanName)->first();

                // If not found, try partial match
                if (! $addOn) {
                    $addOn = AddOn::where('name', 'like', '%' . $cleanName . '%')->first();
                }

                // If still not found, try the original name
                if (! $addOn) {
                    $addOn = AddOn::where('name', $addOnName)->first();
                }

                if ($addOn) {
                    $addOnIds[] = $addOn->id;
                }
            }
        }

        // Determine payment status based on payment method
        $paymentStatus = match (trim($paymentMethod)) {
            'cash_on_arrival'    => BookingPaymentStatus::PENDING,
            'paid_cash'          => BookingPaymentStatus::FULLY_PAID,
            'paid_cheque'        => BookingPaymentStatus::FULLY_PAID,
            'paid_credit_card'   => BookingPaymentStatus::FULLY_PAID,
            'paid_wire_transfer' => BookingPaymentStatus::FULLY_PAID,
            'online_payment'     => BookingPaymentStatus::FULLY_PAID,
            'free'               => BookingPaymentStatus::FULLY_PAID,
            default              => BookingPaymentStatus::PENDING,
        };

        // Calculate deposit amount if needed
        $depositAmount = 0;
        if (! empty($listing->payment_options) && isset($listing->payment_options['deposit'])) {
            $deposit = $listing->payment_options['deposit'];
            if (! empty($deposit['amount']) && $deposit['amount'] > 0) {
                $depositAmount = $deposit['amount'];
            } elseif (! empty($deposit['percentage']) && $deposit['percentage'] > 0) {
                $depositAmount = floor($totalPrice * ($deposit['percentage'] / 100));
            }
        }

        // Create booking using the same logic as store method
        $booking = Booking::create([
            'user_id'               => $user->id,
            'venue_id'              => $venue->id,
            'listing_id'            => $listing->id,
            'date'                  => $date,
            'start_time'            => $startTime,
            'end_time'              => $endTime,
            'subtotal'              => $subtotal,
            'discount_amount'       => $discountAmount,
            'total_price'           => $totalPrice,
            'status'                => $status,
            'payment_status'        => $paymentStatus,
            'payment_method'        => $paymentMethod,
            'notes'                 => $this->getValue($row, 'notes'),
            'booking_id'            => $this->getValue($row, 'booking_number'),
            'is_free'               => $paymentMethod === BookingPaymentMethod::FREE,
            'full_payment_received' => in_array($paymentMethod, [
                BookingPaymentMethod::FREE,
                BookingPaymentMethod::PAID_CHEQUE,
                BookingPaymentMethod::PAID_WIRE_TRANSFER,
                BookingPaymentMethod::PAID_CREDIT_CARD,
                BookingPaymentMethod::PAID_CASH,
            ]),
            'payment_breakdown'     => [
                'deposit_amount'   => $paymentStatus == BookingPaymentStatus::DEPOSIT_PAID ? $depositAmount : 0,
                'deposit_method'   => $paymentStatus == BookingPaymentStatus::DEPOSIT_PAID ? PaymentMethod::ONLINE : null,
                'remaining_amount' => $paymentStatus == BookingPaymentStatus::DEPOSIT_PAID ? $totalPrice - $depositAmount : 0,
                'remaining_method' => $paymentStatus == BookingPaymentStatus::DEPOSIT_PAID ? PaymentMethod::CASH : null,
            ],
        ]);

        // Attach add-ons
        if (! empty($addOnIds)) {
            $booking->addOns()->attach($addOnIds);
        }

        // Attach resources
        $resources = $listing->resourceRequirements()->select('resources.id', 'listing_resource.quantity')->get();
        foreach ($resources as $resource) {
            $booking->resourcesAllocated()->attach($resource->id, ['quantity' => $resource->pivot->quantity]);
        }

        // Create booking history
        $booking->history()->create([
            'user_id'     => $user->id,
            'action'      => 'booking_created',
            'description' => 'Booking imported from Excel file',
        ]);

        // Create payment entry
        if (! $booking->is_free) {
            $paymentMethodEnum = match (trim($paymentMethod)) {
                'paid_cash'          => PaymentMethod::CASH,
                'paid_cheque'        => PaymentMethod::CHEQUE,
                'paid_credit_card'   => PaymentMethod::CREDIT_CARD,
                'paid_wire_transfer' => PaymentMethod::WIRE_TRANSFER,
                'online_payment'     => PaymentMethod::ONLINE,
                default              => PaymentMethod::CASH,
            };

            Payment::create([
                'user_id'    => $user->id,
                'booking_id' => $booking->id,
                'type'       => PaymentType::FULL,
                'amount'     => $booking->total_price,
                'payment_id' => generatePaymentId($booking->id, PaymentType::FULL),
                'date'       => $paymentStatus == BookingPaymentStatus::FULLY_PAID ? now()->format('Y-m-d') : null,
                'status'     => $paymentStatus == BookingPaymentStatus::FULLY_PAID ? PaymentStatus::PAID : PaymentStatus::PENDING,
                'method'     => $paymentMethodEnum,
                'notes'      => $paymentStatus == BookingPaymentStatus::FULLY_PAID
                    ? "Full payment for booking #{$booking->booking_id}"
                    : "Payment pending for booking #{$booking->booking_id}",
            ]);

            if ($paymentStatus == BookingPaymentStatus::FULLY_PAID) {
                $booking->history()->create([
                    'user_id'     => $user->id,
                    'action'      => $paymentMethod,
                    'description' => 'Payment ' . str_replace('_', ' ', $paymentMethod),
                ]);
            }
        }

        // Generate invoice if not free
        if (! $booking->is_free) {
            createBookingInvoiceWithNotification($booking, $user);
        } else {
            $booking->history()->create([
                'user_id'     => $user->id,
                'action'      => 'booking_created',
                'description' => 'This booking is imported as free and marked as fully paid',
            ]);
        }

        // Unlock listing slot
        unlockListingSlot($listing, $user, $booking);

        // Handle cancellation if is_cancelled is "Yes"
        $isCancelled = strtolower(trim($this->getValue($row, 'is_cancelled', '')));
        if ($isCancelled === 'yes' || $isCancelled === 'true' || $isCancelled === '1') {
            $cancellationTime = $this->getValue($row, 'cancellation_time');
            $canceledBy       = $this->getValue($row, 'canceled_by');

            $booking->update([
                'status'        => BookingStatus::CANCELLED,
                'cancelled_at'  => ! empty($cancellationTime) ? Carbon::parse($cancellationTime) : now(),
                'cancel_reason' => 'Cancelled during import',
            ]);

            $booking->history()->create([
                'user_id'     => $user->id,
                'action'      => 'booking_cancelled',
                'description' => 'Booking cancelled during import' . (! empty($canceledBy) ? ' by ' . $canceledBy : ''),
            ]);
        }

        $this->successCount++;
    }

    protected function getValue($row, $key, $default = null)
    {
        // Handle different possible column name formats
        $keys = [
            $key,
            str_replace('_', ' ', $key),
            str_replace(' ', '_', $key),
            strtolower($key),
            strtolower(str_replace('_', ' ', $key)),
            ucfirst($key),
            ucwords(str_replace('_', ' ', $key)),
        ];

        // First try exact matches
        foreach ($keys as $k) {
            if (isset($row[$k])) {
                $value = $row[$k];
                return $value !== null && $value !== '' ? trim($value) : $default;
            }
        }

        // Then try case-insensitive matching
        foreach ($row as $rowKey => $rowValue) {
            foreach ($keys as $k) {
                if (strcasecmp($rowKey, $k) === 0) {
                    $value = $rowValue;
                    return $value !== null && $value !== '' ? trim($value) : $default;
                }
            }
        }

        return $default;
    }

    protected function normalizeStatus($status)
    {
        $status = strtolower(trim($status));

        $statusMap = [
            'pending'   => BookingStatus::PENDING,
            'confirmed' => BookingStatus::CONFIRMED,
            'completed' => BookingStatus::COMPLETED,
            'cancelled' => BookingStatus::CANCELLED,
            'canceled'  => BookingStatus::CANCELLED,
        ];

        return $statusMap[$status] ?? BookingStatus::PENDING;
    }

    protected function normalizePaymentStatus($paymentStatus)
    {
        $paymentStatus = strtolower(trim(str_replace('_', ' ', $paymentStatus)));

        $statusMap = [
            'free'         => BookingPaymentStatus::FREE,
            'pending'      => BookingPaymentStatus::PENDING,
            'fully paid'   => BookingPaymentStatus::FULLY_PAID,
            'fully_paid'   => BookingPaymentStatus::FULLY_PAID,
            'deposit paid' => BookingPaymentStatus::DEPOSIT_PAID,
            'deposit_paid' => BookingPaymentStatus::DEPOSIT_PAID,
        ];

        return $statusMap[$paymentStatus] ?? BookingPaymentStatus::PENDING;
    }

    protected function normalizePaymentMethod($paymentMethod)
    {
        $paymentMethod = strtolower(trim(str_replace('_', ' ', $paymentMethod)));

        $methodMap = [
            'free'                         => BookingPaymentMethod::FREE,
            'cash'                         => BookingPaymentMethod::PAID_CASH,
            'paid cash'                    => BookingPaymentMethod::PAID_CASH,
            'paid_cash'                    => BookingPaymentMethod::PAID_CASH,
            'cheque'                       => BookingPaymentMethod::PAID_CHEQUE,
            'paid cheque'                  => BookingPaymentMethod::PAID_CHEQUE,
            'paid_cheque'                  => BookingPaymentMethod::PAID_CHEQUE,
            'credit card'                  => BookingPaymentMethod::PAID_CREDIT_CARD,
            'paid credit card'             => BookingPaymentMethod::PAID_CREDIT_CARD,
            'paid_credit_card'             => BookingPaymentMethod::PAID_CREDIT_CARD,
            'online payment'               => BookingPaymentMethod::ONLINE_PAYMENT,
            'online_payment'               => BookingPaymentMethod::ONLINE_PAYMENT,
            'cash on arrival'              => BookingPaymentMethod::CASH_ON_ARRIVAL,
            'cash_on_arrival'              => BookingPaymentMethod::CASH_ON_ARRIVAL,
            'wire transfer'                => BookingPaymentMethod::PAID_WIRE_TRANSFER,
            'paid wire transfer'           => BookingPaymentMethod::PAID_WIRE_TRANSFER,
            'paid_wire_transfer'           => BookingPaymentMethod::PAID_WIRE_TRANSFER,
            'deposit remaining on arrival' => BookingPaymentMethod::DEPOSIT_REMAINING_ON_ARRIVAL,
            'deposit_remaining_on_arrival' => BookingPaymentMethod::DEPOSIT_REMAINING_ON_ARRIVAL,
        ];

        return $methodMap[$paymentMethod] ?? BookingPaymentMethod::CASH_ON_ARRIVAL;
    }

    protected function parseDecimal($value, $default = 0)
    {
        if (empty($value)) {
            return $default;
        }

        // Remove currency symbols and spaces
        $value = preg_replace('/[^\d.,-]/', '', $value);

        // Replace comma with dot if it's a decimal separator
        $value = str_replace(',', '.', $value);

        $float = (float) $value;
        return round($float, 2);
    }

    public function getErrors()
    {
        return $this->errors;
    }

    public function getSuccessCount()
    {
        return $this->successCount;
    }

    public function getErrorCount()
    {
        return $this->errorCount;
    }
}
