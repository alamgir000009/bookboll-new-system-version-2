<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Gate a route on one or more capabilities — the server-side twin of the
 * dashboard's `can(CAP.…)` checks and PAGE_CAP map.
 *
 *   ->middleware('capability:manage_bookings')
 *   ->middleware('capability:view_wallet,manage_payouts,withdraw_funds')   // any of
 *
 * Several capabilities mean "any of", matching PAGE_CAP's array form.
 */
class CheckCapability
{
    public function handle(Request $request, Closure $next, string ...$capabilities): Response
    {
        $user = $request->user();

        if (! $user) {
            return response()->json(['success' => false, 'message' => 'Unauthenticated.'], 401);
        }

        if ($capabilities === []) {
            abort(500, 'No capability specified for this route.');
        }

        if (! $user->hasAnyCapability($capabilities)) {
            return response()->json([
                'success' => false,
                'message' => 'You do not have permission to do that.',
            ], 403);
        }

        return $next($request);
    }
}
