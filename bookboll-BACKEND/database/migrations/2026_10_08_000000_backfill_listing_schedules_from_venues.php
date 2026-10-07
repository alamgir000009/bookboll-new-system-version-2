<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Give every listing its own copy of its venue's opening hours.
 *
 * Slot generation needs BOTH levels — ListingService::fetchTimeSlots() intersects
 * the listing's hours with the venue's and returns "No available schedule for this
 * day" if either side is empty. The read side has required that since Nov 2025, but
 * nothing WROTE listing-level rows until the new dashboard (Aug 2026), so every
 * venue set up before then has venue-level rows only. On that data every listing
 * returns zero bookable slots.
 *
 * This copies each venue's rows down to its listings, which is what the hours
 * already mean: the court is open when the venue is. `inherit_from_location` is set
 * so the dashboard shows them as inherited rather than hand-set.
 *
 * Idempotent on purpose: a listing that already has any schedule row is left
 * completely alone, so re-running can never duplicate or overwrite hours a provider
 * has since edited.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! \Illuminate\Support\Facades\Schema::hasTable('schedules')) {
            return;
        }

        $hasInherit   = \Illuminate\Support\Facades\Schema::hasColumn('schedules', 'inherit_from_location');
        $now          = now();
        $copied       = 0;
        $listingsDone = 0;
        $noVenueHours = [];

        // Listings that already have hours are out of scope entirely.
        $alreadyHave = DB::table('schedules')
            ->whereNotNull('listing_id')
            ->distinct()
            ->pluck('listing_id')
            ->all();

        DB::table('listings')
            ->select('id', 'venue_id')
            ->whereNotNull('venue_id')
            ->when($alreadyHave, fn ($q) => $q->whereNotIn('id', $alreadyHave))
            ->orderBy('id')
            ->chunkById(200, function ($listings) use (&$copied, &$listingsDone, &$noVenueHours, $hasInherit, $now) {
                // One query per chunk rather than per listing — several listings
                // usually share a venue.
                $venueIds = $listings->pluck('venue_id')->unique()->all();

                $venueHours = DB::table('schedules')
                    ->whereIn('venue_id', $venueIds)
                    ->whereNull('listing_id')
                    ->get(['venue_id', 'day', 'is_open', 'start_time', 'end_time'])
                    ->groupBy('venue_id');

                $rows = [];

                foreach ($listings as $listing) {
                    $hours = $venueHours->get($listing->venue_id);

                    if (! $hours || $hours->isEmpty()) {
                        // Nothing to copy. Left unbookable, and named in the log so
                        // it is visible rather than silently broken.
                        $noVenueHours[] = $listing->id;
                        continue;
                    }

                    foreach ($hours as $h) {
                        $row = [
                            'venue_id'   => $listing->venue_id,
                            'listing_id' => $listing->id,
                            'day'        => $h->day,
                            'is_open'    => $h->is_open,
                            'start_time' => $h->start_time,
                            'end_time'   => $h->end_time,
                            'created_at' => $now,
                            'updated_at' => $now,
                        ];

                        if ($hasInherit) {
                            $row['inherit_from_location'] = 1;
                        }

                        $rows[] = $row;
                    }

                    $listingsDone++;
                }

                foreach (array_chunk($rows, 500) as $batch) {
                    DB::table('schedules')->insert($batch);
                    $copied += count($batch);
                }
            });

        Log::info('Backfilled listing schedules from venue hours', [
            'listings_backfilled'      => $listingsDone,
            'schedule_rows_created'    => $copied,
            'listings_with_no_venue_hours' => $noVenueHours,
        ]);
    }

    /**
     * Deliberately not reversed.
     *
     * Once this has run, providers edit these hours through the dashboard like any
     * other, and nothing distinguishes an edited row from a copied one. Deleting
     * every listing-level row on rollback would throw away real opening hours, so
     * refusing to guess is the safer failure.
     */
    public function down(): void
    {
        Log::warning('backfill_listing_schedules_from_venues is not reversible; listing hours were left in place.');
    }
};
