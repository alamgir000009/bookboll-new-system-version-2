<?php

use App\Models\User;
use App\Services\AuditLogService;

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
                'bookings.store' => "Created booking #{$id}",
                'users.store' => "Created user #{$id}",
                'venues.store' => "Created venue #{$id}",
                'resources.store' => "Created resource #{$id}",
                'gift-cards.store' => "Created gift card #{$id}",
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
