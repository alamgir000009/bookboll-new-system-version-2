<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Refuse rewards fields on payment routes that cannot honour them yet.
 *
 * readmeAlamgir: "Do not silently ignore rewards fields." A membership
 * enrolment or an event host payment that quietly dropped loyalty_quote_id
 * would charge full price while the customer believed their points had been
 * applied — and the points would still be sitting in their balance, so nothing
 * would look wrong until they compared the receipt.
 *
 * Venue checkout enforces the quote properly and does not use this.
 */
class RejectUnsupportedRewards
{
    public function handle(Request $request, Closure $next): Response
    {
        if ($request->filled('loyalty_quote_id') || (int) $request->input('loyalty_points', 0) > 0) {
            return sendError(
                'Points cannot be applied to this checkout yet. Your balance is unchanged.',
                ['loyalty_quote_id' => ['Rewards are not available for this kind of payment yet.']],
                422
            );
        }

        return $next($request);
    }
}
