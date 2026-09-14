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
                'name'   => $customerName,
                'email'  => $customerEmail,
                'phone'  => $customerPhone,
                'status' => User::ACTIVE,
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

        // Parse prices - support both price/amount and subtotal/total_price
        // Column mappings: "b Price" or "price" for booking price, "Amount" for payment amount
        $bookingPrice   = $this->parseDecimal($this->getValue($row, 'b_price')) ?: $this->parseDecimal($this->getValue($row, 'price'), 0);
        $subtotal       = $this->parseDecimal($this->getValue($row, 'subtotal')) ?: $bookingPrice;
        $discountAmount = $this->parseDecimal($this->getValue($row, 'discount_amount', 0));
        $paymentAmount  = $this->parseDecimal($this->getValue($row, 'amount', 0));
        $totalPrice     = $this->parseDecimal($this->getValue($row, 'total_price')) ?: ($subtotal > 0 ? $subtotal : $paymentAmount);

        // Get payment currency and processor from Excel columns
        $paymentCurrency  = $this->getValue($row, 'currency');
        $paymentProcessor = strtolower(trim($this->getValue($row, 'payment_p') ?: $this->getValue($row, 'payment_processor', '')));

        // Determine payment method and status based on Excel data:
        // 1. If no amount/processor or amount is 0 -> payment_skipped (no payment records)
        // 2. Processor = "delay" -> Amount column = REMAINING/PENDING amount
        //    - Paid = Price - Amount, Remaining = Amount (pending)
        //    - Example: price=250, amount=200 -> deposit=50 paid, remaining=200 pending
        //    - Example: price=200, amount=200 -> single payment=200 pending
        // 3. Other processors (wire_transfer, cash, stripe, check) -> ALL payments are PAID
        //    - If price > amount: deposit = Price - Amount (paid), remaining = Amount (paid)
        //    - Example: price=250, amount=200, cash -> deposit=50 paid, remaining=200 paid (all paid)
        //    - If price = amount: single payment fully paid
        $paymentMethod = null;
        $paymentStatus = null;
        $paidAmount    = 0;
        $pendingAmount = 0;

        if ((empty($paymentAmount) || $paymentAmount == 0) && empty($paymentProcessor)) {
            // Case 1: No payment info or amount is 0 - mark as payment skipped
            $paymentMethod = BookingPaymentMethod::PAYMENT_SKIPPED;
            $paymentStatus = BookingPaymentStatus::PAYMENT_SKIPPED;
            $paidAmount    = 0;
            $pendingAmount = 0;
        } elseif ($paymentProcessor === 'delay') {
            // Case 2: Processor = "delay" - Amount column is the REMAINING/PENDING amount
            // Example: Price=250, Amount=200 means Deposit=50 paid, Remaining=200 pending
            // Example: Price=200, Amount=200 means single payment=200 pending
            $pendingAmount = $paymentAmount;
            $paidAmount    = $bookingPrice - $paymentAmount;

            if ($paidAmount > 0) {
                // Partial payment made (deposit paid), rest is pending
                $paymentMethod = BookingPaymentMethod::DEPOSIT_REMAINING_ON_ARRIVAL;
                $paymentStatus = BookingPaymentStatus::DEPOSIT_PAID;
            } else {
                // No payment made yet, full amount is pending
                $paymentMethod = BookingPaymentMethod::CASH_ON_ARRIVAL;
                $paymentStatus = BookingPaymentStatus::PENDING;
                $paidAmount    = 0;
            }
        } else {
            // Case 3: Other processors (wire_transfer, cash, stripe, check) - ALL payments are PAID
            // Example: price=250, amount=200, cash -> deposit=50 paid, remaining=200 paid (all paid)
            // Example: price=200, amount=200, cash -> single payment=200 paid (fully paid)

            // Determine the payment method based on processor
            $paymentMethod = match ($paymentProcessor) {
                'wire_transfer' => BookingPaymentMethod::PAID_WIRE_TRANSFER,
                'cash'          => BookingPaymentMethod::PAID_CASH,
                'stripe', 'online'    => BookingPaymentMethod::ONLINE_PAYMENT,
                'check', 'cheque'     => BookingPaymentMethod::PAID_CHEQUE,
                'credit_card', 'card' => BookingPaymentMethod::PAID_CREDIT_CARD,
                default         => BookingPaymentMethod::PAID_CASH,
            };

            if ($bookingPrice > $paymentAmount) {
                // Price > Amount: deposit = Price - Amount (paid), remaining = Amount (paid)
                // All payments are paid, but we track them separately
                $paidAmount    = $bookingPrice; // Total paid = deposit + remaining
                $pendingAmount = 0;
                $paymentStatus = BookingPaymentStatus::FULLY_PAID;
                // Keep the payment method as the processor type (e.g., PAID_CASH, PAID_WIRE_TRANSFER)
            } else {
                // Price = Amount: single payment fully paid
                $paidAmount    = $paymentAmount;
                $pendingAmount = 0;
                $paymentStatus = BookingPaymentStatus::FULLY_PAID;
            }
        }

        // Get add-ons IDs - support both add_ons and service_add_ons
        $addOnIds     = [];
        $addOnsString = $this->getValue($row, 'service_add_ons');
        if (! empty($addOnsString)) {
            // Split by comma first, then clean each add-on name
            $addOnNames = array_map('trim', explode(',', $addOnsString));

            foreach ($addOnNames as $addOnName) {
                // Parse add-on string: "Click & Add Hour! (60mins.) - 125.00 QAR"
                $parsedAddOn = $this->parseAddOnString($addOnName);
                $cleanName   = $parsedAddOn['name'];

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

                // If add-on doesn't exist, create it
                if (! $addOn && ! empty($cleanName)) {
                    $addOn = AddOn::create([
                        'name'            => $cleanName,
                        'price'           => $parsedAddOn['price'],
                        'hours_extension' => $parsedAddOn['hours_extension'],
                        'status'          => 'active',
                        'currency'        => 'QAR',
                    ]);
                }

                if ($addOn) {
                    $addOnIds[] = $addOn->id;
                }
            }
        }

        // If payment status was not set by the Excel data processing logic above, determine from payment method
        if ($paymentStatus === null) {
            $paymentStatus = match (trim($paymentMethod)) {
                BookingPaymentMethod::CASH_ON_ARRIVAL, 'cash_on_arrival'                           => BookingPaymentStatus::PENDING,
                BookingPaymentMethod::PAID_CASH, 'paid_cash'                                       => BookingPaymentStatus::FULLY_PAID,
                BookingPaymentMethod::PAID_CHEQUE, 'paid_cheque'                                   => BookingPaymentStatus::FULLY_PAID,
                BookingPaymentMethod::PAID_CREDIT_CARD, 'paid_credit_card'                         => BookingPaymentStatus::FULLY_PAID,
                BookingPaymentMethod::PAID_WIRE_TRANSFER, 'paid_wire_transfer'                     => BookingPaymentStatus::FULLY_PAID,
                BookingPaymentMethod::ONLINE_PAYMENT, 'online_payment'                             => BookingPaymentStatus::FULLY_PAID,
                BookingPaymentMethod::PAYMENT_SKIPPED, 'payment_skipped'                           => BookingPaymentStatus::PAYMENT_SKIPPED,
                BookingPaymentMethod::DEPOSIT_REMAINING_ON_ARRIVAL, 'deposit_remaining_on_arrival' => BookingPaymentStatus::DEPOSIT_PAID,
                default => BookingPaymentStatus::PENDING,
            };
        }

        // Calculate deposit amount if needed
        $depositAmount   = 0;
        $remainingAmount = 0;

        // If payment status is FULLY_PAID and processor is NOT "delay", calculate deposit/remaining from Price and Amount
        if ($paymentStatus === BookingPaymentStatus::FULLY_PAID && $paymentProcessor !== 'delay' && !empty($paymentProcessor) && $bookingPrice > $paymentAmount) {
            // All payments are paid: deposit = Price - Amount (paid), remaining = Amount (paid)
            $depositAmount   = $bookingPrice - $paymentAmount;
            $remainingAmount = $paymentAmount;
        } elseif ($paymentStatus === BookingPaymentStatus::DEPOSIT_PAID && $paidAmount > 0) {
            // If payment status is DEPOSIT_PAID (delay processor), use the calculated paid/pending amounts
            $depositAmount   = $paidAmount;
            $remainingAmount = $pendingAmount;
        } elseif ($paymentStatus === BookingPaymentStatus::PENDING) {
            // Full amount is pending
            $depositAmount   = 0;
            $remainingAmount = $totalPrice;
        } elseif (! empty($listing->payment_options) && isset($listing->payment_options['deposit'])) {
            $deposit = $listing->payment_options['deposit'];
            if (! empty($deposit['amount']) && $deposit['amount'] > 0) {
                $depositAmount = $deposit['amount'];
            } elseif (! empty($deposit['percentage']) && $deposit['percentage'] > 0) {
                $depositAmount = floor($totalPrice * ($deposit['percentage'] / 100));
            }
            $remainingAmount = $totalPrice - $depositAmount;
        }

        // Parse Record date if provided
        // Supported formats:
        // - d-m-Y H:i (24-hour format, e.g., "01-01-2026 22:50")
        // - d-m-Y h:i A (12-hour format with uppercase AM/PM, e.g., "13-01-2026 06:09 PM")
        // - d-m-Y h:i a (12-hour format with lowercase am/pm, e.g., "01-01-2026 10:35 pm")
        $recordDate = $this->getValue($row, 'record_date');
        $createdAt  = null;

        if (! empty($recordDate)) {
            $recordDate = trim($recordDate);

            try {
                // Try parsing the format: d-m-Y H:i (24-hour format, e.g., "01-01-2026 22:50")
                $createdAt = Carbon::createFromFormat('d-m-Y H:i', $recordDate);
            } catch (\Exception $e) {
                // If that format fails, try 12-hour formats
                try {
                    // Try d-m-Y h:i A (12-hour format with uppercase AM/PM, e.g., "13-01-2026 06:09 PM")
                    $createdAt = Carbon::createFromFormat('d-m-Y h:i A', $recordDate);
                } catch (\Exception $e2) {
                    try {
                        // Try d-m-Y h:i a (12-hour format with lowercase am/pm, e.g., "01-01-2026 10:35 pm")
                        $createdAt = Carbon::createFromFormat('d-m-Y h:i a', $recordDate);
                    } catch (\Exception $e3) {
                        // Normalize AM/PM to lowercase and try again
                        try {
                            $normalizedDate = preg_replace_callback('/\b(AM|PM)\b/', function($matches) {
                                return strtolower($matches[1]);
                            }, $recordDate);
                            $createdAt = Carbon::createFromFormat('d-m-Y h:i a', $normalizedDate);
                        } catch (\Exception $e4) {
                            // Let Carbon auto-detect the format as last resort
                            try {
                                $createdAt = Carbon::parse($recordDate);
                            } catch (\Exception $e5) {
                                Log::warning("Row {$rowNumber}: Could not parse Record date '{$recordDate}'. Using current timestamp.");
                            }
                        }
                    }
                }
            }
        }

        // Get duration (in hours, e.g., 1, 2, 3, 4)
        $duration = $this->getValue($row, 'duration');

        // Parse created_by column - if "admin" use user ID 2, otherwise find user by email/name
        $createdByValue = $this->getValue($row, 'created_by');
        $createdById = null;

        if (! empty($createdByValue)) {
            $createdByValueLower = strtolower(trim($createdByValue));

            if ($createdByValueLower === 'admin') {
                // Use admin user ID 2
                $createdById = 2;
            } else {
                $originalValue = trim($createdByValue);

                // Try to find user by email first (since created_by might be an email)
                $createdByUser = User::where('email', $originalValue)->first();

                // If not found by email, try case-insensitive email match
                if (! $createdByUser) {
                    $createdByUser = User::whereRaw('LOWER(email) = ?', [$createdByValueLower])->first();
                }

                // If still not found, try by name (case-insensitive)
                if (! $createdByUser) {
                    $createdByUser = User::whereRaw('LOWER(name) = ?', [$createdByValueLower])->first();
                }

                if ($createdByUser) {
                    $createdById = $createdByUser->id;
                } else {
                    // If user not found, log warning and use admin (ID 2) as fallback
                    Log::warning("Row {$rowNumber}: Created by user '{$originalValue}' not found. Using admin (ID 2) as fallback.");
                    $createdById = 2;
                }
            }
        } else {
            // If created_by is empty, use admin (ID 2) as default
            $createdById = 2;
        }

        // If all payments are paid, mark booking as confirmed (unless already cancelled)
        if ($paymentStatus === BookingPaymentStatus::FULLY_PAID && $status !== BookingStatus::CANCELLED) {
            $status = BookingStatus::CONFIRMED;
        }

        // Determine payment method enum for payment_breakdown
        $breakdownDepositMethod = null;
        $breakdownRemainingMethod = null;

        if ($paymentStatus == BookingPaymentStatus::DEPOSIT_PAID || ($paymentStatus == BookingPaymentStatus::FULLY_PAID && $depositAmount > 0)) {
            $breakdownDepositMethod = match ($paymentProcessor) {
                'wire_transfer' => PaymentMethod::WIRE_TRANSFER,
                'cash'          => PaymentMethod::CASH,
                'stripe', 'online'    => PaymentMethod::ONLINE,
                'check', 'cheque'     => PaymentMethod::CHEQUE,
                'credit_card', 'card' => PaymentMethod::CREDIT_CARD,
                'delay'         => PaymentMethod::CASH,
                default         => PaymentMethod::CASH,
            };
        }

        if ($paymentStatus == BookingPaymentStatus::DEPOSIT_PAID || ($paymentStatus == BookingPaymentStatus::FULLY_PAID && $remainingAmount > 0)) {
            if ($paymentProcessor === 'delay') {
                $breakdownRemainingMethod = PaymentMethod::CASH; // Pending payment method
            } else {
                $breakdownRemainingMethod = match ($paymentProcessor) {
                    'wire_transfer' => PaymentMethod::WIRE_TRANSFER,
                    'cash'          => PaymentMethod::CASH,
                    'stripe', 'online'    => PaymentMethod::ONLINE,
                    'check', 'cheque'     => PaymentMethod::CHEQUE,
                    'credit_card', 'card' => PaymentMethod::CREDIT_CARD,
                    default         => PaymentMethod::CASH,
                };
            }
        }

        // Create booking using the same logic as store method
        $bookingData = [
            'user_id'               => $user->id,
            'venue_id'              => $venue->id,
            'listing_id'            => $listing->id,
            'date'                  => $date,
            'start_time'            => $startTime,
            'end_time'              => $endTime,
            'duration'              => ! empty($duration) ? (int) $duration : null,
            'subtotal'              => $subtotal,
            'discount_amount'       => $discountAmount,
            'total_price'           => $totalPrice,
            'status'                => $status,
            'payment_status'        => $paymentStatus,
            'payment_method'        => $paymentMethod,
            'notes'                 => $this->getValue($row, 'notes'),
            'booking_id'            => $this->getValue($row, 'booking_number'),
            'created_by'            => $createdById,
            'is_free'               => $paymentMethod === BookingPaymentMethod::PAYMENT_SKIPPED,
            'full_payment_received' => $paymentStatus === BookingPaymentStatus::FULLY_PAID || in_array($paymentMethod, [
                BookingPaymentMethod::PAID_CHEQUE,
                BookingPaymentMethod::PAID_WIRE_TRANSFER,
                BookingPaymentMethod::PAID_CREDIT_CARD,
                BookingPaymentMethod::PAID_CASH,
                BookingPaymentMethod::ONLINE_PAYMENT,
                BookingPaymentMethod::PAYMENT_SKIPPED,
            ]),
            'payment_breakdown'     => [
                'deposit_amount'   => ($paymentStatus == BookingPaymentStatus::DEPOSIT_PAID || ($paymentStatus == BookingPaymentStatus::FULLY_PAID && $depositAmount > 0)) ? $depositAmount : 0,
                'deposit_method'   => $breakdownDepositMethod,
                'remaining_amount' => ($paymentStatus == BookingPaymentStatus::DEPOSIT_PAID || ($paymentStatus == BookingPaymentStatus::FULLY_PAID && $remainingAmount > 0)) ? $remainingAmount : 0,
                'remaining_method' => $breakdownRemainingMethod,
            ],
        ];

        // Add created_at if Record date was provided
        if ($createdAt) {
            $bookingData['created_at'] = $createdAt;
            $bookingData['updated_at'] = $createdAt; // Set updated_at to same value to maintain consistency
        }

        $booking = Booking::create($bookingData);

        // Attach add-ons
        if (! empty($addOnIds)) {
            $booking->addOns()->attach($addOnIds);
        }

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

        // Create payment entry based on payment status
        $isPaymentSkipped = $paymentMethod === BookingPaymentMethod::PAYMENT_SKIPPED;

        if (! $booking->is_free && ! $isPaymentSkipped) {
            $paymentMethodEnum = match (trim($paymentMethod)) {
                BookingPaymentMethod::PAID_CASH, 'paid_cash'                                       => PaymentMethod::CASH,
                BookingPaymentMethod::PAID_CHEQUE, 'paid_cheque'                                   => PaymentMethod::CHEQUE,
                BookingPaymentMethod::PAID_CREDIT_CARD, 'paid_credit_card'                         => PaymentMethod::CREDIT_CARD,
                BookingPaymentMethod::PAID_WIRE_TRANSFER, 'paid_wire_transfer'                     => PaymentMethod::WIRE_TRANSFER,
                BookingPaymentMethod::ONLINE_PAYMENT, 'online_payment'                             => PaymentMethod::ONLINE,
                BookingPaymentMethod::DEPOSIT_REMAINING_ON_ARRIVAL, 'deposit_remaining_on_arrival' => PaymentMethod::CHEQUE,
                default => PaymentMethod::CASH,
            };

            if ($paymentStatus === BookingPaymentStatus::DEPOSIT_PAID) {
                // Determine the deposit method based on processor type
                $depositMethodEnum = match ($paymentProcessor) {
                    'wire_transfer' => PaymentMethod::WIRE_TRANSFER,
                    'cash'          => PaymentMethod::CASH,
                    'stripe', 'online'    => PaymentMethod::ONLINE,
                    'check', 'cheque'     => PaymentMethod::CHEQUE,
                    'credit_card', 'card' => PaymentMethod::CREDIT_CARD,
                    'delay'         => PaymentMethod::CASH, // For delay, deposit was paid in cash
                    default         => PaymentMethod::CASH,
                };

                $depositMethodName = match ($paymentProcessor) {
                    'wire_transfer' => 'wire transfer',
                    'cash'          => 'cash',
                    'stripe', 'online'    => 'online payment',
                    'check', 'cheque'     => 'cheque',
                    'credit_card', 'card' => 'credit card',
                    'delay'         => 'cash',
                    default         => 'cash',
                };

                // Create deposit payment (paid amount)
                Payment::create([
                    'currency'   => $this->importCurrency($booking),
                    'user_id'    => $user->id,
                    'booking_id' => $booking->id,
                    'type'       => PaymentType::DEPOSIT,
                    'amount'     => $depositAmount,
                    'payment_id' => generatePaymentId($booking->id, PaymentType::DEPOSIT),
                    'date'       => $createdAt ? $createdAt->format('Y-m-d') : now()->format('Y-m-d'),
                    'status'     => PaymentStatus::PAID,
                    'method'     => $depositMethodEnum,
                    'notes'      => "Deposit payment of {$depositAmount} via {$depositMethodName} for booking #{$booking->booking_id}",
                ]);

                // Create remaining payment (pending for delay, paid for other processors)
                if ($remainingAmount > 0) {
                    $remainingStatus = ($paymentProcessor === 'delay') ? PaymentStatus::PENDING : PaymentStatus::PAID;
                    $remainingDate = ($paymentProcessor === 'delay') ? null : ($createdAt ? $createdAt->format('Y-m-d') : now()->format('Y-m-d'));
                    $remainingMethod = ($paymentProcessor === 'delay') ? PaymentMethod::CASH : $depositMethodEnum;
                    $remainingNotes = ($paymentProcessor === 'delay')
                        ? "Remaining payment of {$remainingAmount} pending for booking #{$booking->booking_id}"
                        : "Remaining payment of {$remainingAmount} via {$depositMethodName} for booking #{$booking->booking_id}";

                    Payment::create([
                        'currency'   => $this->importCurrency($booking),
                        'user_id'    => $user->id,
                        'booking_id' => $booking->id,
                        'type'       => PaymentType::REMAINING,
                        'amount'     => $remainingAmount,
                        'payment_id' => generatePaymentId($booking->id, PaymentType::REMAINING),
                        'date'       => $remainingDate,
                        'status'     => $remainingStatus,
                        'method'     => $remainingMethod,
                        'notes'      => $remainingNotes,
                    ]);
                }

                $historyDescription = ($paymentProcessor === 'delay')
                    ? "Deposit of {$depositAmount} paid via {$depositMethodName}, remaining {$remainingAmount} pending"
                    : "Deposit of {$depositAmount} paid via {$depositMethodName}, remaining {$remainingAmount} paid via {$depositMethodName}";

                $booking->history()->create([
                    'user_id'     => $user->id,
                    'action'      => 'deposit_paid',
                    'description' => $historyDescription,
                ]);
            } elseif ($paymentStatus === BookingPaymentStatus::PENDING) {
                // Delay/pending payment - create as pending
                Payment::create([
                    'currency'   => $this->importCurrency($booking),
                    'user_id'    => $user->id,
                    'booking_id' => $booking->id,
                    'type'       => PaymentType::FULL,
                    'amount'     => $booking->total_price,
                    'payment_id' => generatePaymentId($booking->id, PaymentType::FULL),
                    'date'       => null,
                    'status'     => PaymentStatus::PENDING,
                    'method'     => $paymentMethodEnum,
                    'notes'      => "Payment pending for booking #{$booking->booking_id}",
                ]);
            } else {
                // Fully paid or other status
                $fullyPaidMethodName = match ($paymentProcessor) {
                    'wire_transfer' => 'wire transfer',
                    'cash'          => 'cash',
                    'stripe', 'online'    => 'online payment',
                    'check', 'cheque'     => 'cheque',
                    'credit_card', 'card' => 'credit card',
                    default         => 'cash',
                };

                // If processor is NOT "delay" and we have deposit/remaining split (Price > Amount), create separate payments
                if ($paymentStatus == BookingPaymentStatus::FULLY_PAID && $paymentProcessor !== 'delay' && !empty($paymentProcessor) && $bookingPrice > $paymentAmount && $depositAmount > 0 && $remainingAmount > 0) {
                    // Create deposit payment (paid)
                    Payment::create([
                        'currency'   => $this->importCurrency($booking),
                        'user_id'    => $user->id,
                        'booking_id' => $booking->id,
                        'type'       => PaymentType::DEPOSIT,
                        'amount'     => $depositAmount,
                        'payment_id' => generatePaymentId($booking->id, PaymentType::DEPOSIT),
                        'date'       => $createdAt ? $createdAt->format('Y-m-d') : now()->format('Y-m-d'),
                        'status'     => PaymentStatus::PAID,
                        'method'     => $paymentMethodEnum,
                        'notes'      => "Deposit payment of {$depositAmount} via {$fullyPaidMethodName} for booking #{$booking->booking_id}",
                    ]);

                    // Create remaining payment (paid)
                    Payment::create([
                        'currency'   => $this->importCurrency($booking),
                        'user_id'    => $user->id,
                        'booking_id' => $booking->id,
                        'type'       => PaymentType::REMAINING,
                        'amount'     => $remainingAmount,
                        'payment_id' => generatePaymentId($booking->id, PaymentType::REMAINING),
                        'date'       => $createdAt ? $createdAt->format('Y-m-d') : now()->format('Y-m-d'),
                        'status'     => PaymentStatus::PAID,
                        'method'     => $paymentMethodEnum,
                        'notes'      => "Remaining payment of {$remainingAmount} via {$fullyPaidMethodName} for booking #{$booking->booking_id}",
                    ]);

                    $booking->history()->create([
                        'user_id'     => $user->id,
                        'action'      => $paymentMethod,
                        'description' => "Full payment received: deposit of {$depositAmount} and remaining of {$remainingAmount} via {$fullyPaidMethodName}",
                    ]);
                } else {
                    // Single payment (Price = Amount) or other cases - create full payment
                    Payment::create([
                        'currency'   => $this->importCurrency($booking),
                        'user_id'    => $user->id,
                        'booking_id' => $booking->id,
                        'type'       => PaymentType::FULL,
                        'amount'     => $booking->total_price,
                        'payment_id' => generatePaymentId($booking->id, PaymentType::FULL),
                        'date'       => $paymentStatus == BookingPaymentStatus::FULLY_PAID ? ($createdAt ? $createdAt->format('Y-m-d') : now()->format('Y-m-d')) : null,
                        'status'     => $paymentStatus == BookingPaymentStatus::FULLY_PAID ? PaymentStatus::PAID : PaymentStatus::PENDING,
                        'method'     => $paymentMethodEnum,
                        'notes'      => $paymentStatus == BookingPaymentStatus::FULLY_PAID
                            ? "Full payment of {$booking->total_price} via {$fullyPaidMethodName} for booking #{$booking->booking_id}"
                            : "Payment pending for booking #{$booking->booking_id}",
                    ]);

                    if ($paymentStatus == BookingPaymentStatus::FULLY_PAID) {
                        $booking->history()->create([
                            'user_id'     => $user->id,
                            'action'      => $paymentMethod,
                            'description' => "Full payment of {$booking->total_price} received via {$fullyPaidMethodName}",
                        ]);
                    }
                }
            }
        } elseif ($isPaymentSkipped) {
            // Payment skipped - log in history
            $booking->history()->create([
                'user_id'     => $user->id,
                'action'      => 'payment_skipped',
                'description' => 'This booking is imported with payment skipped',
            ]);
        }

        // Generate invoice if not free and not payment skipped
        if (! $booking->is_free && ! $isPaymentSkipped) {
            createBookingInvoiceWithNotification($booking, $user);
        } elseif ($booking->is_free && ! $isPaymentSkipped) {
            $booking->history()->create([
                'user_id'     => $user->id,
                'action'      => 'booking_created',
                'description' => 'This booking is imported as free and marked as fully paid',
            ]);
        }
        // Note: Payment skipped history entry is already created above

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

    /**
     * Currency for an imported booking's payments.
     *
     * The import never wrote one, so every imported payment reported as
     * "Unknown currency" on the Payments page. Cached: an import runs thousands
     * of rows over a handful of venues.
     *
     * @var array<int,string>
     */
    protected array $venueCurrencyCache = [];

    protected function importCurrency($booking): string
    {
        $venueId = (int) ($booking->venue_id ?? 0);
        if ($venueId <= 0) {
            return '';
        }
        if (array_key_exists($venueId, $this->venueCurrencyCache)) {
            return $this->venueCurrencyCache[$venueId];
        }

        $venue = Venue::with('country.currency')->find($venueId);
        $code  = $venue
            ? ((string) ($venue->currency_override ?: ($venue->country?->currency?->code ?? '')))
            : '';

        return $this->venueCurrencyCache[$venueId] = $code;
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
            'free'            => BookingPaymentStatus::PAYMENT_SKIPPED,
            'pending'         => BookingPaymentStatus::PENDING,
            'fully paid'      => BookingPaymentStatus::FULLY_PAID,
            'fully_paid'      => BookingPaymentStatus::FULLY_PAID,
            'deposit paid'    => BookingPaymentStatus::DEPOSIT_PAID,
            'deposit_paid'    => BookingPaymentStatus::DEPOSIT_PAID,
            'payment skipped' => BookingPaymentStatus::PAYMENT_SKIPPED,
            'payment_skipped' => BookingPaymentStatus::PAYMENT_SKIPPED,
        ];

        return $statusMap[$paymentStatus] ?? BookingPaymentStatus::PENDING;
    }

    protected function normalizePaymentMethod($paymentMethod)
    {
        $paymentMethod = strtolower(trim(str_replace('_', ' ', $paymentMethod)));

        $methodMap = [
            'free'                         => BookingPaymentMethod::PAYMENT_SKIPPED,
            'cash'                         => BookingPaymentMethod::PAID_CASH,
            'paid cash'                    => BookingPaymentMethod::PAID_CASH,
            'paid_cash'                    => BookingPaymentMethod::PAID_CASH,
            'check'                        => BookingPaymentMethod::PAID_CHEQUE,
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
            'delay'                        => BookingPaymentMethod::CASH_ON_ARRIVAL,
            'wire transfer'                => BookingPaymentMethod::PAID_WIRE_TRANSFER,
            'paid wire transfer'           => BookingPaymentMethod::PAID_WIRE_TRANSFER,
            'paid_wire_transfer'           => BookingPaymentMethod::PAID_WIRE_TRANSFER,
            'deposit remaining on arrival' => BookingPaymentMethod::DEPOSIT_REMAINING_ON_ARRIVAL,
            'deposit_remaining_on_arrival' => BookingPaymentMethod::DEPOSIT_REMAINING_ON_ARRIVAL,
            'payment skipped'              => BookingPaymentMethod::PAYMENT_SKIPPED,
            'payment_skipped'              => BookingPaymentMethod::PAYMENT_SKIPPED,
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

    /**
     * Parse add-on string like "Click & Add Hour! (60mins.) - 125.00 QAR"
     * Returns: ['name' => 'Click & Add Hour!', 'hours_extension' => 1, 'price' => 125.00]
     */
    protected function parseAddOnString($addOnString)
    {
        $name           = '';
        $hoursExtension = 0;
        $price          = 0;

        // Extract name (before parentheses or dash)
        // e.g., "Click & Add Hour! (60mins.) - 125.00 QAR" -> "Click & Add Hour!"
        $name = trim(preg_split('/\s*[\(\-]/', $addOnString)[0]);

        // Extract minutes from parentheses (e.g., "(60mins.)" -> 60)
        if (preg_match('/\((\d+)\s*min/i', $addOnString, $matches)) {
            $minutes        = (int) $matches[1];
            $hoursExtension = (int) round($minutes / 60); // Convert to hours
            if ($hoursExtension < 1 && $minutes > 0) {
                $hoursExtension = 1; // Minimum 1 hour
            }
        }

        // Extract price (e.g., "- 125.00 QAR" -> 125.00)
        if (preg_match('/[\-]\s*([\d.]+)\s*(?:QAR)?/i', $addOnString, $matches)) {
            $price = (float) $matches[1];
        }

        return [
            'name'            => $name,
            'hours_extension' => $hoursExtension,
            'price'           => $price,
        ];
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
