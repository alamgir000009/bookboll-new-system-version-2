<?php

namespace App\Models\Scopes;

use App\Models\Booking;
use App\Models\Listing;
use App\Models\Review;
use App\Models\Schedule;
use App\Models\SpecialDay;
use App\Models\Venue;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Scope;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;

class VenueAccessScope implements Scope
{
    /**
     * Static cache for venue IDs per user (per request)
     */
    protected static array $venueIdsCache = [];

    /**
     * Static cache for user access level (per request)
     */
    protected static array $accessLevelCache = [];

    /**
     * Apply the scope to a given Eloquent query builder.
     *
     * @param  \Illuminate\Database\Eloquent\Builder  $builder
     * @param  \Illuminate\Database\Eloquent\Model  $model
     * @return void
     */
    public function apply(Builder $builder, Model $model): void
    {
        // Only apply scope if user is authenticated
        if (!Auth::check()) {
            return;
        }

        // Don't apply scope to User model queries (to avoid issues with login/auth)
        if ($model instanceof \App\Models\User) {
            return;
        }

        $user = Auth::user();
        
        // Safety check - if user is null, skip
        if (!$user || !isset($user->id)) {
            return;
        }
        
        $userId = $user->id;

        // Check access level (cached per request)
        $accessLevel = $this->getUserAccessLevel($user, $userId);
        
        // Only super_admin and admin see all venues - no filtering needed
        if ($accessLevel === 'full') {
            return;
        }

        // Get user's accessible venue IDs (cached per request)
        $venueIds = $this->getCachedVenueIds($user, $userId);

        // If user has no venues assigned, return empty result
        if (empty($venueIds)) {
            $builder->whereRaw('1 = 0'); // Always false condition
            return;
        }

        // Apply venue filter based on model
        $this->applyVenueFilter($builder, $model, $venueIds);
    }

    /**
     * Get user access level (customer, full, or limited)
     * Cached per request to avoid repeated role queries
     *
     * @param  \App\Models\User  $user
     * @param  int  $userId
     * @return string
     */
    protected function getUserAccessLevel($user, int $userId): string
    {
        if (isset(self::$accessLevelCache[$userId])) {
            return self::$accessLevelCache[$userId];
        }

        // Use direct query instead of loading relationship to avoid N+1
        // Check if roles are already loaded first
        if ($user->relationLoaded('roles')) {
            $userRoles = $user->getRoleNames();
        } else {
            // Single optimized query to check all roles at once
            $roleNames = DB::table('model_has_roles')
                ->where('model_id', $userId)
                ->where('model_type', get_class($user))
                ->join('roles', 'model_has_roles.role_id', '=', 'roles.id')
                ->pluck('roles.name')
                ->toArray();

            // Only super_admin and admin get full access
            if (in_array('super_admin', $roleNames) || in_array('admin', $roleNames)) {
                self::$accessLevelCache[$userId] = 'full';
                return 'full';
            }
            
            // All other roles are limited
            self::$accessLevelCache[$userId] = 'limited';
            return 'limited';
        }

        // Only super_admin and admin get full access
        if ($userRoles->contains('super_admin') || $userRoles->contains('admin')) {
            self::$accessLevelCache[$userId] = 'full';
        } else {
            self::$accessLevelCache[$userId] = 'limited';
        }

        return self::$accessLevelCache[$userId];
    }

    /**
     * Get cached venue IDs for the user (cached per request)
     * Uses direct query to avoid relationship loading overhead
     *
     * @param  \App\Models\User  $user
     * @param  int  $userId
     * @return array
     */
    protected function getCachedVenueIds($user, int $userId): array
    {
        if (isset(self::$venueIdsCache[$userId])) {
            return self::$venueIdsCache[$userId];
        }

        // Use direct query instead of loading relationship to avoid overhead
        // Always use direct query for better performance and to avoid relationship issues
        $venueIds = DB::table('user_venue')
            ->where('user_id', $userId)
            ->pluck('venue_id')
            ->toArray();
        
        self::$venueIdsCache[$userId] = $venueIds;

        return $venueIds;
    }

    /**
     * Apply venue filter based on the model's structure
     * Uses static mapping to avoid expensive schema checks
     *
     * @param  \Illuminate\Database\Eloquent\Builder  $builder
     * @param  \Illuminate\Database\Eloquent\Model  $model
     * @param  array  $venueIds
     * @return void
     */
    protected function applyVenueFilter(Builder $builder, Model $model, array $venueIds): void
    {
        $tableName = $model->getTable();
        $modelClass = get_class($model);

        // For Venue model itself, filter by id (check this first)
        if ($tableName === 'venues' || $modelClass === Venue::class) {
            $builder->whereIn('id', $venueIds);
            return;
        }

        // Use static mapping for models with direct venue_id column
        $venueIdModels = [
            'bookings' => Booking::class,
            'listings' => Listing::class,
            'schedules' => Schedule::class,
            'special_days' => SpecialDay::class,
        ];

        // Check if table name exists in mapping
        if (isset($venueIdModels[$tableName])) {
            $builder->whereIn('venue_id', $venueIds);
            return;
        }

        // Check if model class matches any in mapping
        if (in_array($modelClass, $venueIdModels, true)) {
            $builder->whereIn('venue_id', $venueIds);
            return;
        }
        
        // For Payment model - filter through booking relationship
        if ($tableName === 'payments' || $modelClass === \App\Models\Payment::class) {
            $builder->whereHas('booking', function ($query) use ($venueIds) {
                $query->whereIn('venue_id', $venueIds);
            });
            return;
        }

        // For Review model - filter through booking relationship
        if ($tableName === 'reviews' || $modelClass === Review::class) {
            $builder->whereHas('booking', function ($query) use ($venueIds) {
                $query->whereIn('venue_id', $venueIds);
            });
            return;
        }

        // Fallback: check if model has listing relationship and filter through listing
        if (method_exists($model, 'listing')) {
            $builder->whereHas('listing', function ($query) use ($venueIds) {
                $query->whereIn('venue_id', $venueIds);
            });
            return;
        }
    }

    /**
     * Extend the query builder with the needed functions.
     *
     * @param  \Illuminate\Database\Eloquent\Builder  $builder
     * @return void
     */
    public function extend(Builder $builder): void
    {
        $scope = $this;
        
        // Add a method to bypass the scope if needed
        $builder->macro('withoutVenueAccessFilter', function (Builder $builder) use ($scope) {
            return $builder->withoutGlobalScope($scope);
        });

        // Add a method to apply venue filter manually
        $builder->macro('withVenueAccess', function (Builder $builder, array $venueIds) {
            $tableName = $builder->getModel()->getTable();
            
            if ($builder->getConnection()->getSchemaBuilder()->hasColumn($tableName, 'venue_id')) {
                return $builder->whereIn("{$tableName}.venue_id", $venueIds);
            }
            
            return $builder;
        });
    }
}

