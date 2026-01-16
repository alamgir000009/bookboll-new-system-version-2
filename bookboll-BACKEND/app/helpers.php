<?php

use App\Models\Invoice;
use App\Models\LoyaltyPoint;
use App\Models\Setting;
use App\Models\SlotLock;
use App\Models\User;
use App\Notifications\BookingConfirmedUserNotification;
use App\Notifications\BookingCreatedAdminNotification;
use App\Notifications\BookingCreatedUserNotification;
use App\Services\AuditLogService;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Support\Facades\DB;

if (! class_exists('MobileApps')) {
    class MobileApps
    {
        public const ADMIN    = 'admin_mobile_app';
        public const CUSTOMER = 'customer_mobile_app';

        public static function list(): array
        {
            return [
                self::ADMIN,
                self::CUSTOMER,
            ];
        }
    }
}

if (! class_exists('BookingStatus')) {
    class BookingStatus
    {
        public const PENDING   = 'pending';
        public const CONFIRMED = 'confirmed';
        public const COMPLETED = 'completed';
        public const CANCELLED = 'cancelled';

        public static function list(): array
        {
            return [
                self::PENDING,
                self::CONFIRMED,
                self::COMPLETED,
                self::CANCELLED,
            ];
        }
    }
}

if (! class_exists('PaymentStatus')) {
    class PaymentStatus
    {
        public const PAID    = 'paid';
        public const PENDING = 'pending';
        public const UNPAID  = 'unpaid';

        public static function list(): array
        {
            return [
                self::PAID,
                self::PENDING,
                self::UNPAID,
            ];
        }
    }
}

if (! class_exists('BookingPaymentStatus')) {
    class BookingPaymentStatus
    {
        public const PENDING         = 'pending';
        public const FULLY_PAID      = 'fully_paid';
        public const DEPOSIT_PAID    = 'deposit_paid';
        public const PAYMENT_SKIPPED = 'payment_skipped';

        public static function list(): array
        {
            return [
                self::PENDING,
                self::FULLY_PAID,
                self::DEPOSIT_PAID,
                self::PAYMENT_SKIPPED,
            ];
        }
    }
}

if (! class_exists('BookingPaymentMethod')) {
    class BookingPaymentMethod
    {
        public const PAID_CASH                    = 'paid_cash';
        public const PAID_CHEQUE                  = 'paid_cheque';
        public const ONLINE_PAYMENT               = 'online_payment';
        public const PAYMENT_SKIPPED              = 'payment_skipped';
        public const CASH_ON_ARRIVAL              = 'cash_on_arrival';
        public const PAID_CREDIT_CARD             = 'paid_credit_card';
        public const PAID_WIRE_TRANSFER           = 'paid_wire_transfer';
        public const DEPOSIT_REMAINING_ON_ARRIVAL = 'deposit_remaining_on_arrival';

        public static function list(): array
        {
            return [
                self::PAID_CASH,
                self::PAID_CHEQUE,
                self::ONLINE_PAYMENT,
                self::PAYMENT_SKIPPED,
                self::CASH_ON_ARRIVAL,
                self::PAID_CREDIT_CARD,
                self::PAID_WIRE_TRANSFER,
                self::DEPOSIT_REMAINING_ON_ARRIVAL,
            ];
        }
    }
}

if (! class_exists('PaymentMethod')) {
    class PaymentMethod
    {
        public const CASH          = 'cash';
        public const ONLINE        = 'online';
        public const CHEQUE        = 'cheque';
        public const CREDIT_CARD   = 'credit_card';
        public const WIRE_TRANSFER = 'wire_transfer';

        public static function list()
        {
            return [
                self::CASH,
                self::ONLINE,
                self::CHEQUE,
                self::CREDIT_CARD,
                self::WIRE_TRANSFER,
            ];
        }
    }
}

if (! class_exists('PaymentType')) {
    class PaymentType
    {
        public const FULL      = 'full';
        public const DEPOSIT   = 'deposit';
        public const REMAINING = 'remaining';

        public static function list()
        {
            return [
                self::FULL,
                self::DEPOSIT,
                self::REMAINING,
            ];
        }
    }
}

if (! class_exists('SubscriberStatus')) {
    class SubscriberStatus
    {
        public const SUBSCRIBED   = 'subscribed';
        public const UNSUBSCRIBED = 'unsubscribed';

        public static function list()
        {
            return [
                self::SUBSCRIBED,
                self::UNSUBSCRIBED,
            ];
        }
    }
}

if (! class_exists('CompnayInfo')) {
    class CompnayInfo
    {
        public const EMAIL       = 'info@bookboll.com';
        public const NAME        = 'BOOKBOLL';
        public const CITY        = 'Doha';
        public const POSTAL_CODE = '12345';
        public const PHONE       = '123456789';
        public const ADDRESS     = 'Qatar';
    }
}

if (! function_exists('sendSuccess')) {
    function sendSuccess(string $message = 'Success', array $data = [], int $code = 200, $logActivity = true): \Illuminate\Http\JsonResponse
    {
        $user = auth()->user();

        if ($logActivity && $user && ! request()->is('api/login*', 'api/register*', 'api/admin-app/register*', 'api/forgot-password*', 'api/reset-password*', 'api/customer/login*', 'api/customer/google-login*', 'api/customer/forgot-password*', 'api/customer/reset-password*', 'api/health*', 'api/audit-logs*') && ! request()->isMethod('GET')) {
            try {
                $routeName  = request()->route()?->getName();
                $action     = getActionFromMethod(request()->method());
                $resource   = getResourceFromRoute($routeName);
                $resourceId = getResourceIdFromRequest(request());
                $details    = extractSuccessDetails($message, $routeName, $data);

                app(AuditLogService::class)->logActivity(
                    $action,
                    $resource,
                    $resourceId,
                    $details,
                    $user
                );
            } catch (\Exception $e) {
                \Log::error('Failed to log success activity: ' . $e->getMessage());
            }
        }

        return response()->json([
            'success' => true,
            'message' => $message,
            'data'    => $data,
        ], $code);
    }
}

if (! function_exists('sendError')) {
    function sendError(string $message = 'Error', array $errors = [], int $code = 400)
    {
        return response()->json([
            'success' => false,
            'message' => $message,
            'errors'  => $errors,
            'code'    => $code,
        ], $code);
    }
}

if (! function_exists('getActionFromMethod')) {
    function getActionFromMethod(string $method): string
    {
        return match ($method) {
            'POST'   => 'Created',
            'PUT', 'PATCH' => 'Updated',
            'DELETE' => 'Deleted',
            default  => 'Accessed'
        };
    }
}

if (! function_exists('getResourceFromRoute')) {
    function getResourceFromRoute(?string $routeName): string
    {
        if (! $routeName) {
            return 'System';
        }

        $resourceMap = [
            'posts.store'                  => 'Blog Post',
            'posts.update'                 => 'Blog Post',
            'posts.destroy'                => 'Blog Post',
            'posts.change-status'          => 'Blog Post',

            'post-categories.store'        => 'Post Category',
            'post-categories.update'       => 'Post Category',
            'post-categories.destroy'      => 'Post Category',

            'bookings.store'               => 'Booking',
            'bookings.update'              => 'Booking',
            'bookings.destroy'             => 'Booking',
            'bookings.cancel'              => 'Booking',
            'bookings.completed'           => 'Booking',
            'bookings.confirm'             => 'Booking',
            'bookings.generate-invoice'    => 'Booking',
            'bookings.confirmBooking'      => 'Booking',
            'bookings.pay'                 => 'Booking',

            'users.store'                  => 'User',
            'users.update'                 => 'User',
            'users.destroy'                => 'User',
            'users.update-profile'         => 'User',
            'users.updateProfile'          => 'User',
            'users.updateCustomerProfile'  => 'User',
            'users.updateProfileImage'     => 'User',
            'users.updatePreferences'      => 'User',
            'users.changePassword'         => 'User',

            'venues.store'                 => 'Venue',
            'venues.update'                => 'Venue',
            'venues.destroy'               => 'Venue',
            'venues.sort'                  => 'Venue',

            'resources.store'              => 'Resource',
            'resources.update'             => 'Resource',
            'resources.destroy'            => 'Resource',

            'gift-cards.store'             => 'Gift Card',
            'gift-cards.update'            => 'Gift Card',
            'gift-cards.destroy'           => 'Gift Card',
            'gift-cards.redeem'            => 'Gift Card',
            'gift-cards.redeemGiftCard'    => 'Gift Card',

            'sports-categories.store'      => 'Sports Category',
            'sports-categories.update'     => 'Sports Category',
            'sports-categories.destroy'    => 'Sports Category',
            'sports-categories.sort'       => 'Sports Category',

            'sports-types.store'           => 'Sports Type',
            'sports-types.update'          => 'Sports Type',
            'sports-types.destroy'         => 'Sports Type',
            'sports-types.sort'            => 'Sports Type',

            'add-ons.store'                => 'Add-On',
            'add-ons.update'               => 'Add-On',
            'add-ons.destroy'              => 'Add-On',
            'add-ons.sort'                 => 'Add-On',

            'listings.store'               => 'Listing',
            'listings.update'              => 'Listing',
            'listings.destroy'             => 'Listing',
            'listings.sort'                => 'Listing',
            'listings.uploadImage'         => 'Listing',
            'listings.deleteImage'         => 'Listing',

            'promocodes.store'             => 'Promocode',
            'promocodes.update'            => 'Promocode',
            'promocodes.destroy'           => 'Promocode',
            'promocodes.apply'             => 'Promocode',
            'promocodes.remove'            => 'Promocode',
            'promocodes.applyPromocode'    => 'Promocode',

            'special-days.store'           => 'Special Day',
            'special-days.update'          => 'Special Day',
            'special-days.destroy'         => 'Special Day',
            'special-days.bulk'            => 'Special Day',
            'special-days.bulkCreate'      => 'Special Day',

            'settings.update'              => 'Setting',
            'settings.updateSingle'        => 'Setting',

            'customers.store'              => 'Customer',
            'customers.update'             => 'Customer',
            'customers.destroy'            => 'Customer',
            'customers.register'           => 'Customer',
            'customers.createCustomer'     => 'Customer',
            'customers.updateTags'         => 'Customer',
            'customers.updateStatus'       => 'Customer',

            'reviews.store'                => 'Review',
            'reviews.update'               => 'Review',
            'reviews.destroy'              => 'Review',
            'reviews.helpful'              => 'Review',

            'payments.store'               => 'Payment',
            'payments.update'              => 'Payment',
            'payments.destroy'             => 'Payment',
            'payments.confirm'             => 'Payment',
            'payments.confirmPayment'      => 'Payment',
            'payments.generate-link'       => 'Payment',
            'payments.generatePaymentLink' => 'Payment',
            'payments.createPaymentIntent' => 'Payment',

            'newsletter.subscribe'         => 'Newsletter',
        ];

        foreach ($resourceMap as $routePattern => $resource) {
            if (str_contains($routeName, $routePattern)) {
                return $resource;
            }
        }

        $routeUri = request()->route()?->uri() ?? '';
        foreach ($resourceMap as $routePattern => $resource) {
            $uriPattern = str_replace('.', '/', $routePattern);
            if (str_contains($routeUri, $uriPattern) || str_contains($routeUri, str_replace('-', '/', $routePattern))) {
                return $resource;
            }
        }

        if (str_contains($routeName, '.')) {
            $parts        = explode('.', $routeName);
            $resourceName = ucwords(str_replace(['store', 'update', 'destroy', 'create'], '', $parts[0]));
            return $resourceName ?: 'System';
        }

        if ($routeUri) {
            $uriParts = explode('/', $routeUri);
            foreach ($uriParts as $part) {
                if (in_array($part, ['posts', 'venues', 'users', 'bookings', 'listings', 'customers', 'reviews', 'payments', 'promocodes', 'gift-cards', 'resources', 'add-ons', 'sports-categories', 'sports-types', 'special-days', 'post-categories', 'settings', 'newsletter'])) {
                    return ucwords(str_replace('-', ' ', $part));
                }
            }
        }

        return 'System';
    }
}

if (! function_exists('getResourceIdFromRequest')) {
    function getResourceIdFromRequest($request): string
    {
        $id = $request->route('id') ??
        $request->route('booking_id') ??
        $request->route('user_id') ??
        $request->route('venue_id') ??
        $request->route('resource_id') ??
        $request->route('gift_card_id') ??
        $request->route('sports_category_id') ??
        $request->route('promocode') ??
        $request->route('review') ??
        $request->route('customer_id') ??
        $request->route('key') ??
            'N/A';

        if ($id === 'N/A' && $request->isMethod('post')) {
            $responseData = $request->getContent();
            if (str_contains($responseData, '"id":')) {
                preg_match('/"id":(\d+)/', $responseData, $matches);
                if (isset($matches[1])) {
                    $id = $matches[1];
                }
            }
        }

        return $id;
    }
}

if (! function_exists('extractSuccessDetails')) {
    function extractSuccessDetails(string $message, ?string $routeName, array $data = []): string
    {
        if (isset($data['id'])) {
            $id = $data['id'];

            $detailsMap = [
                'posts.store' => "Created Post #{$id}",
                'posts.update' => "Updated Post #{$id}",
                'posts.destroy' => "Deleted Post #{$id}",
                'posts.change-status' => "Changed status for Post #{$id}",

                'post-categories.store' => "Created Blog Category #{$id}",
                'post-categories.update' => "Updated Blog Category #{$id}",
                'post-categories.destroy' => "Deleted Blog Category #{$id}",

                'users.store' => "Created user #{$id}",
                'users.update' => "Updated user #{$id}",
                'users.destroy' => "Deleted user #{$id}",
                'users.update-profile' => "Updated profile for user #{$id}",
                'users.updateProfile' => "Updated profile for user #{$id}",
                'users.updateCustomerProfile' => "Updated customer profile #{$id}",
                'users.updateProfileImage' => "Updated profile image for user #{$id}",
                'users.updatePreferences' => "Updated preferences for user #{$id}",
                'users.changePassword' => "Changed password for user #{$id}",
                'users.approve' => "Approved user registration #{$id}",
                'users.reject' => "Rejected user registration #{$id}",

                'venues.store' => "Created venue #{$id}",
                'venues.update' => "Updated venue #{$id}",
                'venues.destroy' => "Deleted venue #{$id}",
                'venues.sort' => "Updated venue sort order",

                'resources.store' => "Created resource #{$id}",
                'resources.update' => "Updated resource #{$id}",
                'resources.destroy' => "Deleted resource #{$id}",

                'gift-cards.store' => "Created gift card #{$id}",
                'gift-cards.update' => "Updated gift card #{$id}",
                'gift-cards.destroy' => "Deleted gift card #{$id}",
                'gift-cards.redeem' => "Redeemed gift card #{$id}",
                'gift-cards.redeemGiftCard' => "Redeemed gift card #{$id}",

                'sports-categories.store' => "Created sports category #{$id}",
                'sports-categories.update' => "Updated sports category #{$id}",
                'sports-categories.destroy' => "Deleted sports category #{$id}",
                'sports-categories.sort' => "Updated sports category sort order",

                'sports-types.store' => "Created sports type #{$id}",
                'sports-types.update' => "Updated sports type #{$id}",
                'sports-types.destroy' => "Deleted sports type #{$id}",
                'sports-types.sort' => "Updated sports type sort order",

                'add-ons.store' => "Created Add-On #{$id}",
                'add-ons.update' => "Updated Add-On #{$id}",
                'add-ons.destroy' => "Deleted Add-On #{$id}",
                'add-ons.sort' => "Updated Add-On sort order",

                'listings.store' => "Created Listing #{$id}",
                'listings.update' => "Updated Listing #{$id}",
                'listings.destroy' => "Deleted Listing #{$id}",
                'listings.sort' => "Updated Listing sort order",
                'listings.uploadImage' => "Uploaded image for Listing #{$id}",
                'listings.deleteImage' => "Deleted image for Listing #{$id}",

                'promocodes.store' => "Created Promocode #{$id}",
                'promocodes.update' => "Updated Promocode #{$id}",
                'promocodes.destroy' => "Deleted Promocode #{$id}",
                'promocodes.apply' => "Applied Promocode #{$id}",
                'promocodes.remove' => "Removed Promocode from booking #{$id}",
                'promocodes.applyPromocode' => "Applied Promocode",

                'special-days.store' => "Created Special Day #{$id}",
                'special-days.update' => "Updated Special Day #{$id}",
                'special-days.destroy' => "Deleted Special Day #{$id}",
                'special-days.bulk' => "Bulk created Special Days",
                'special-days.bulkCreate' => "Bulk created Special Days",

                'settings.update' => "Updated settings",
                'settings.updateSingle' => "Updated setting",

                'customers.store' => "Created customer #{$id}",
                'customers.update' => "Updated customer #{$id}",
                'customers.destroy' => "Deleted customer #{$id}",
                'customers.register' => "Registered customer #{$id}",
                'customers.createCustomer' => "Created customer #{$id}",
                'customers.updateTags' => "Updated tags for customer #{$id}",
                'customers.updateStatus' => "Updated status for customer #{$id}",

                'bookings.store' => "Created booking #{$id}",
                'bookings.update' => "Updated booking #{$id}",
                'bookings.destroy' => "Deleted booking #{$id}",
                'bookings.cancel' => "Cancelled booking #{$id}",
                'bookings.completed' => "Marked booking #{$id} as completed",
                'bookings.confirm' => "Confirmed booking #{$id}",
                'bookings.generate-invoice' => "Generated invoice for booking #{$id}",
                'bookings.confirmBooking' => "Confirmed booking #{$id}",
                'bookings.pay' => "Processed payment for booking #{$id}",
                'bookings.mobileUpdate' => "Updated booking #{$id} via mobile",
                'bookings.validate' => "Validated booking request",

                'reviews.store' => "Created review #{$id}",
                'reviews.update' => "Updated review #{$id}",
                'reviews.destroy' => "Deleted review #{$id}",
                'reviews.helpful' => "Marked review #{$id} as helpful",

                'payments.store' => "Created payment #{$id}",
                'payments.update' => "Updated payment #{$id}",
                'payments.destroy' => "Deleted payment #{$id}",
                'payments.confirm' => "Confirmed payment #{$id}",
                'payments.confirmPayment' => "Confirmed payment #{$id}",
                'payments.generate-link' => "Generated payment link",
                'payments.generatePaymentLink' => "Generated payment link",
                'payments.createPaymentIntent' => "Created payment intent",
                'payments.mark-booking-paid' => "Marked booking as paid",
                'payments.markBookingAsPaid' => "Marked booking as paid",

                'newsletter.subscribe' => "Subscribed to newsletter",

                'lock-slot' => "Locked time slot",
                'unlock-slot' => "Unlocked time slot",
                'unlock-all-slots' => "Unlocked all time slots",
                'extend-slot-lock' => "Extended slot lock",
                'check-slot-availability' => "Checked slot availability",

                'contact-us' => "Submitted contact form",

                'customer.subscribe-onesignal' => "Subscribed to OneSignal notifications",
            ];

            foreach ($detailsMap as $routePattern => $detail) {
                if (str_contains($routeName ?? '', $routePattern)) {
                    return $detail;
                }
            }
        }

        $cleanMessage = strtolower($message);
        $cleanMessage = preg_replace('/\s+(successfully|success)$/i', '', $cleanMessage);
        $cleanMessage = ucwords(trim($cleanMessage));

        return $cleanMessage ?: 'Performed action';
    }
}

if (! function_exists('logActivity')) {
    function logActivity(string $action, string $resource, string $resourceId, string $details): void
    {
        $user = auth()->user();
        if (! $user) {
            return;
        }

        try {
            app(AuditLogService::class)->logActivity(
                $action,
                $resource,
                $resourceId,
                $details,
                $user
            );
        } catch (\Exception $e) {
            \Log::error('Manual activity logging failed: ' . $e->getMessage());
        }
    }
}

if (! function_exists('generatePaymentId')) {
    function generatePaymentId(int $bookingId, string $type): string
    {
        $typeAbbr = match (strtolower($type)) {
            'deposit'   => 'DEP',
            'remaining' => 'REM',
            'full'      => 'FUL',
            default     => 'PAY',
        };

        $uniqueSuffix = substr(str_replace('.', '', microtime(true)), -6);

        return 'PAY-' . $bookingId . '-' . $typeAbbr . '-' . $uniqueSuffix;
    }
}

if (! function_exists('determineSlotPrice')) {
    function determineSlotPrice($listing, $weekday, $slotStart, $slotEnd, $specialDay)
    {
        if ($specialDay && $specialDay->timeBlocks && $specialDay->timeBlocks->count()) {
            foreach ($specialDay->timeBlocks as $block) {
                if (timeSlotFallsInRange($slotStart, $slotEnd, $block->start_time, $block->end_time)) {
                    return $block->price_per_hour ?? $listing->base_price;
                }
            }
        }

        if ($listing->relationLoaded('pricingGroups')) {
            foreach ($listing->pricingGroups as $group) {
                if (in_array($weekday, $group->days)) {
                    if (timeSlotFallsInRange($slotStart, $slotEnd, $group->start_time, $group->end_time)) {
                        return $group->price_per_hour;
                    }
                }
            }
        }

        return $listing->base_price;
    }
}

if (! function_exists('timeSlotFallsInRange')) {
    function timeSlotFallsInRange($slotStart, $slotEnd, $rangeStart, $rangeEnd)
    {
        $slotStartMinutes  = timeToMinutes($slotStart);
        $slotEndMinutes    = timeToMinutes($slotEnd);
        $rangeStartMinutes = timeToMinutes($rangeStart);
        $rangeEndMinutes   = ($rangeEnd === '24:00') ? 24 * 60 : timeToMinutes($rangeEnd);

        $slotSpansMidnight = ($slotEnd === '00:00' || ($slotEndMinutes < $slotStartMinutes && $slotEnd !== '24:00'));
        if ($slotSpansMidnight) {
            $slotEndMinutes = 24 * 60;
        }

        if ($rangeEnd === '00:00') {
            $rangeEndMinutes = 24 * 60;
        }

        if ($rangeStart === '00:00' && $rangeEnd === '24:00') {
            return true;
        }

        return $slotStartMinutes < $rangeEndMinutes && $slotEndMinutes > $rangeStartMinutes;
    }
}

if (! function_exists('timeToMinutes')) {
    function timeToMinutes($time)
    {
        if ($time === '00:00' || $time === '24:00') {
            return ($time === '24:00') ? 24 * 60 : 0;
        }

        list($hours, $minutes) = explode(':', $time);
        return (int) $hours * 60 + (int) $minutes;
    }
}

if (! function_exists('generateInvoiceId')) {
    function generateInvoiceId()
    {
        $invoiceNumber = null;
        DB::transaction(function () use (&$invoiceNumber) {

            $year              = now()->year;
            $latestInvoice     = Invoice::lockForUpdate()->whereYear('created_at', $year)->latest('id')->first();
            $nextInvoiceNumber = $latestInvoice ? (int) substr($latestInvoice->invoice_number, -5) + 1 : 1;
            $invoiceNumber     = sprintf('INV_%s_%05d', $year, $nextInvoiceNumber);

        });

        return $invoiceNumber;
    }
}

if (! function_exists('generateAndStoreInvoicePdf')) {
    function generateAndStoreInvoicePdf($booking, $invoiceNumber)
    {
        $pdf = Pdf::loadView('pdfs.booking-invoice', compact('booking', 'invoiceNumber'));

        $fileName     = 'invoice_' . $invoiceNumber . '_' . $booking->id . '.pdf';
        $relativePath = 'invoices/' . $fileName;

        Storage::disk('public')->makeDirectory('invoices');
        Storage::disk('public')->put($relativePath, $pdf->output());

        return $relativePath;
    }
}

if (! function_exists('getSessionId')) {
    function getSessionId()
    {
        return "slot_locked_from_" . md5(request()->ip() . request()->userAgent());
    }
}

if (! function_exists('unlockListingSlot')) {
    function unlockListingSlot($listing, $user, $booking)
    {
        $sessionId = getSessionId();

        SlotLock::where('date', $booking->date)
            ->where('session_id', $sessionId)
            ->where('listing_id', $listing->id)
            ->delete();
    }
}

if (! function_exists('createBookingInvoiceWithNotification')) {
    function createBookingInvoiceWithNotification($booking, $user)
    {
        try {

            $invoiceNumber = generateInvoiceId();

            $invoicePath = generateAndStoreInvoicePdf($booking, $invoiceNumber);

            $invoice = $user->invoices()->create([
                'user_id'        => $user->id,
                'status'         => 'generated',
                'path'           => $invoicePath,
                'booking_id'     => $booking->id,
                'invoice_number' => $invoiceNumber,
            ]);

            $booking->load('venue:id,name,maps_url');

            try {
                $user->notify(new BookingCreatedUserNotification($booking, $invoice->path));
            } catch (\Exception $e) {
                Log::error("Booking created user notification failed: " . $e->getMessage(), [
                    'booking_id' => $booking->id,
                    'user_id'    => $user->id,
                    'exception'  => $e,
                ]);
            }

            $admins = User::whereHas("roles", function ($query) {
                $query->whereIn("name", ["super_admin", "admin"]);
            })->get();

            try {
                Notification::send($admins, new BookingCreatedAdminNotification($booking));
            } catch (Exception $e) {
                Log::error("Booking created admin notification failed: " . $e->getMessage(), [
                    'booking_id' => $booking->id,
                    'exception'  => $e,
                ]);
            }

        } catch (Exception $e) {
            Log::error("Booking created notification errors: " . $e->getMessage(), ["Exception" => $e]);
        }
    }
}

if (! function_exists('getFormattedDuration')) {
    function getFormattedDuration($slotMinutes)
    {
        $hours   = floor($slotMinutes / 60);
        $minutes = $slotMinutes % 60;
        if ($hours > 0 && $minutes > 0) {
            $duration = $hours . ' hour' . ($hours > 1 ? 's' : '') . ' ' . $minutes . ' minute' . ($minutes > 1 ? 's' : '');
        } elseif ($hours > 0) {
            $duration = $hours . ' hour' . ($hours > 1 ? 's' : '');
        } else {
            $duration = $minutes . ' minute' . ($minutes > 1 ? 's' : '');
        }

        return $duration;
    }
}

if (! function_exists('regenerateBookingInvoice')) {
    function regenerateBookingInvoice($booking)
    {
        try {
            $booking->loadMissing('user');
            $user = $booking->user;

            if (! $user) {
                return;
            }

            $existingInvoices = $user->invoices()->where('booking_id', $booking->id)->get();
            foreach ($existingInvoices as $invoice) {
                $invoice->status = 're_generated';
                $invoice->save();
            }

            $invoiceNumber = generateInvoiceId();
            $invoicePath   = generateAndStoreInvoicePdf($booking, $invoiceNumber);

            $user->invoices()->create([
                'user_id'        => $user->id,
                'status'         => 'generated',
                'path'           => $invoicePath,
                'booking_id'     => $booking->id,
                'invoice_number' => $invoiceNumber,
            ]);
        } catch (Exception $e) {
            Log::error("Failed to regenerate invoice for booking #{$booking->id}: " . $e->getMessage(), [
                'booking_id' => $booking->id,
                'exception'  => $e,
            ]);
        }
    }
}

if (! function_exists('autoConfirmBookingIfPaid')) {
    function autoConfirmBookingIfPaid($booking)
    {
        try {

            if (in_array($booking->status, [BookingStatus::CONFIRMED, BookingStatus::CANCELLED, BookingStatus::COMPLETED])) {
                return;
            }

            $isFullyPaid = $booking->payment_status === BookingPaymentStatus::FULLY_PAID;

            $totalPaid  = $booking->payments()->where('status', PaymentStatus::PAID)->sum('amount');
            $totalPrice = $booking->total_price;

            if ($isFullyPaid && $totalPaid >= $totalPrice) {
                $booking->update(['status' => BookingStatus::CONFIRMED]);

                $booking->history()->create([
                    'user_id'     => $booking->user_id,
                    'action'      => 'booking_confirmed',
                    'description' => 'Booking automatically confirmed after payment completion.',
                ]);

                if ($booking->loyalty_points_earned > 0) {
                    $pointsAlreadyAdded = LoyaltyPoint::where('booking_id', $booking->id)
                        ->where('type', 'credit')
                        ->exists();

                    if (! $pointsAlreadyAdded) {
                        $user = $booking->user;
                        $user->addLoyaltyPoints($booking->loyalty_points_earned, $booking->id, "Loyalty points earned for booking auto-confirmation");
                        $booking->history()->create([
                            'user_id'     => auth()->id(),
                            'action'      => 'loyalty_points_earned',
                            'description' => "{$booking->loyalty_points_earned} loyalty points added to user #{$user->id} for booking auto-confirmation.",
                        ]);
                    }
                }

                try {
                    $booking->load('venue:id,name,maps_url');
                    $user = $booking->user;
                    $user->notify(new BookingConfirmedUserNotification($booking));
                } catch (Exception $e) {
                    Log::error("Booking confirmation notification failed: " . $e->getMessage(), [
                        'booking_id' => $booking->id,
                        'exception'  => $e,
                    ]);
                }
            }
        } catch (Exception $e) {
            Log::error("Failed to auto-confirm booking #{$booking->id}: " . $e->getMessage(), [
                'booking_id' => $booking->id,
                'exception'  => $e,
            ]);
        }
    }
}

if (! function_exists('applyVenueFilter')) {
    function applyVenueFilter($query, string $venueColumn = 'venue_id')
    {
        $user = auth()->user();

        if (! $user) {
            return $query->whereRaw('1 = 0');
        }

        if ($user->hasAnyRole(['super_admin', 'admin'])) {
            return $query;
        }

        $venueIds = DB::table('user_venue')
            ->where('user_id', $user->id)
            ->pluck('venue_id')
            ->toArray();

        if (empty($venueIds)) {
            return $query->whereRaw('1 = 0');
        }

        return $query->whereIn($venueColumn, $venueIds);
    }
}

if (! function_exists('applyVenueFilterToPayments')) {
    function applyVenueFilterToPayments($query)
    {
        $user = auth()->user();

        if (! $user) {
            return $query->whereRaw('1 = 0');
        }

        if ($user->hasAnyRole(['super_admin', 'admin'])) {
            return $query;
        }

        $venueIds = DB::table('user_venue')
            ->where('user_id', $user->id)
            ->pluck('venue_id')
            ->toArray();

        if (empty($venueIds)) {
            return $query->whereRaw('1 = 0');
        }

        return $query->whereHas('booking', function ($q) use ($venueIds) {
            $q->whereIn('venue_id', $venueIds);
        });
    }
}

if (! function_exists('isDateAvailableForBooking')) {
    function isDateAvailableForBooking($date)
    {
        $dateCarbon = $date instanceof \Carbon\Carbon  ? $date : \Carbon\Carbon::parse($date);
        $today      = \Carbon\Carbon::today();

        if ($dateCarbon->lt($today)) {
            return false;
        }

        if ($dateCarbon->month === $today->month && $dateCarbon->year === $today->year) {
            return true;
        }

        $nextMonth = $today->copy()->addMonth();
        if ($dateCarbon->month === $nextMonth->month && $dateCarbon->year === $nextMonth->year) {
            $daysBeforeNextMonth = (int) Setting::where('key', 'days_until_next_month_bookings_open')->value('value') ?? 7;

            $endOfCurrentMonth = $today->copy()->endOfMonth();
            $daysRemaining     = $today->diffInDays($endOfCurrentMonth) + 1;

            return $daysRemaining <= $daysBeforeNextMonth;
        }

        return false;
    }
}
