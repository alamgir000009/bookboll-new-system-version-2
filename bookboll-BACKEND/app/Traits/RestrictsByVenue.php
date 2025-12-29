<?php

namespace App\Traits;

use App\Models\Booking;
use App\Models\Invoice;
use App\Models\Listing;
use App\Models\LoyaltyPoint;
use App\Models\Payment;
use App\Models\Review;
use App\Models\Schedule;
use App\Models\SpecialDay;
use App\Models\Transaction;
use App\Models\Venue;
use Illuminate\Database\Eloquent\Builder;

trait RestrictsByVenue
{
    protected static function bootRestrictsByVenue()
    {
        static::addGlobalScope('venue_restriction', function (Builder $builder) {
            // Only restrict if a user is logged in
            if (auth()->check()) {
                $user = auth()->user();

                // super_admin and admin roles see everything
                if ($user->hasRole(['super_admin', 'admin'])) {
                    return;
                }

                $table = $builder->getModel()->getTable();
                
                // Get venue IDs the user is assigned to. 
                // Use withoutGlobalScopes to avoid recursion since Venue model also uses this trait.
                $venueIds = $user->venues()
                    ->withoutGlobalScopes()
                    ->pluck('venues.id')
                    ->toArray();

                $class = static::class;

                if ($class === Venue::class) {
                    $builder->whereIn("{$table}.id", $venueIds);
                } elseif (in_array($class, [Listing::class, Booking::class, SpecialDay::class, Schedule::class])) {
                    $builder->whereIn("{$table}.venue_id", $venueIds);
                } elseif (in_array($class, [Payment::class, Invoice::class, Transaction::class, Review::class, LoyaltyPoint::class])) {
                    // These models relate to Venue through a Booking
                    $builder->whereHas('booking', function ($q) use ($venueIds) {
                        $q->withoutGlobalScopes()->whereIn('venue_id', $venueIds);
                    });
                }
            }
        });
    }
}
