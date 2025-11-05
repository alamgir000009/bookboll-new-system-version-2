<?php

use App\Services\AuditLogService;

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
        public const PENDING      = 'pending';
        public const FULLY_PAID   = 'fully_paid';
        public const DEPOSIT_PAID = 'deposit_paid';

        public static function list(): array
        {
            return [
                self::PENDING,
                self::FULLY_PAID,
                self::DEPOSIT_PAID,
            ];
        }
    }
}

if (! class_exists('BookingPaymentMethod')) {
    class BookingPaymentMethod
    {
        public const ONLINE_PAYMENT               = 'online_payment';
        public const CASH_ON_ARRIVAL              = 'cash_on_arrival';
        public const DEPOSIT_REMAINING_ON_ARRIVAL = 'deposit_remaining_on_arrival';

        public static function list(): array
        {
            return [
                self::CASH_ON_ARRIVAL,
                self::ONLINE_PAYMENT,
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
        public const WIRE_TRANSFER = 'wire_transfer';

        public static function list()
        {
            return [
                self::CASH,
                self::ONLINE,
                self::CHEQUE,
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
        public const EMAIL   = 'support@bookboll.com';
        public const NAME    = 'BOOKBOLL';
        public const ADDRESS = '123 Main Street, Doha, Qatar';
        public const PHONE   = '123456789';
    }
}

if (! function_exists('sendSuccess')) {
    function sendSuccess(string $message = 'Success', array $data = [], int $code = 200, $logActivity = true): \Illuminate\Http\JsonResponse
    {
        $user = auth()->user();

        if ($logActivity && $user && ! request()->is('api/login*', 'api/register*', 'api/health*', 'api/audit-logs*') && ! request()->isMethod('GET')) {
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
    function sendError(string $message = 'Error', array $errors = [], int $code = 400): \Illuminate\Http\JsonResponse
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
            // Blog Posts
            'posts.store'               => 'Blog Post',
            'posts.update'              => 'Blog Post',
            'posts.destroy'             => 'Blog Post',

            // Post Categories
            'post-categories.store'     => 'Post Category',
            'post-categories.update'    => 'Post Category',
            'post-categories.destroy'   => 'Post Category',

            // Bookings
            'bookings.store'            => 'Booking',
            'bookings.update'           => 'Booking',
            'bookings.destroy'          => 'Booking',
            'bookings.cancel'           => 'Booking',

            // Users
            'users.store'               => 'User',
            'users.update'              => 'User',
            'users.destroy'             => 'User',

            // Venues
            'venues.store'              => 'Venue',
            'venues.update'             => 'Venue',
            'venues.destroy'            => 'Venue',

            // Resources
            'resources.store'           => 'Resource',
            'resources.update'          => 'Resource',
            'resources.destroy'         => 'Resource',

            // Gift Cards
            'gift-cards.store'          => 'Gift Card',
            'gift-cards.update'         => 'Gift Card',
            'gift-cards.destroy'        => 'Gift Card',

            // Sports Categories
            'sports-categories.store'   => 'Sports Category',
            'sports-categories.update'  => 'Sports Category',
            'sports-categories.destroy' => 'Sports Category',

            // Add-Ons
            'add-ons.store'             => 'Add-On',
            'add-ons.update'            => 'Add-On',
            'add-ons.destroy'           => 'Add-On',

            // Listings
            'listings.store'            => 'Listing',
            'listings.update'           => 'Listing',
            'listings.destroy'          => 'Listing',

            // Promocode
            'promocodes.store'          => 'Promocde',
            'promocodes.update'         => 'Promocde',
            'promocodes.destroy'        => 'Promocde',

            // Special Days
            'special-days.store'        => 'Special Day',
            'special-days.update'       => 'Special Day',
            'special-days.destroy'      => 'Special Day',

            // Settings
            'settings.update'           => 'Setting',
        ];

        foreach ($resourceMap as $routePattern => $resource) {
            if (str_contains($routeName, $routePattern)) {
                return $resource;
            }
        }

        if (str_contains($routeName, '.')) {
            $parts        = explode('.', $routeName);
            $resourceName = ucwords(str_replace(['store', 'update', 'destroy', 'create'], '', $parts[0]));
            return $resourceName ?: 'System';
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
                'users.store' => "Created user #{$id}",
                'venues.store' => "Created venue #{$id}",
                'add-ons.store' => "Created Add-On #{$id}",
                'bookings.store' => "Created booking #{$id}",
                'listings.store' => "Created Listing #{$id}",
                'resources.store' => "Created resource #{$id}",
                'gift-cards.store' => "Created gift card #{$id}",
                'promocodes.store' => "Created Promocode #{$id}",
                'special-days.store' => "Special Day Promocode #{$id}",
                'post-categories.store' => "Created Blog Category #{$id}",
                'sports-categories.store' => "Created sports category #{$id}",
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
        $typeAbbr = match ($type) {
            'deposit'   => 'DEP',
            'remaining' => 'REM',
            default     => '',
        };

        return 'PAY-' . $bookingId . $typeAbbr;
    }
}

if (! function_exists('determineSlotPrice')) {
    function determineSlotPrice($listing, $weekday, $slotStart, $slotEnd, $specialDay)
    {
        $price = $listing->base_price;

        foreach ($listing->pricingGroups as $group) {
            if (in_array($weekday, $group->days)) {
                if (strtotime($slotStart) >= strtotime($group->start_time) &&
                    strtotime($slotEnd) <= strtotime($group->end_time)) {
                    $price = $group->price_per_hour;
                    break;
                }
            }
        }

        if ($specialDay && $specialDay->timeBlocks->count()) {
            foreach ($specialDay->timeBlocks as $block) {
                if (strtotime($slotStart) >= strtotime($block->start_time) &&
                    strtotime($slotEnd) <= strtotime($block->end_time)) {
                    $price = $block->price_per_hour ?? $price;
                    break;
                }
            }
        }

        return $price;
    }
}
