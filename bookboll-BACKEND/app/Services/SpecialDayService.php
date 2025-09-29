<?php
namespace App\Services;

use App\Models\Listing;
use App\Models\SpecialDay;
use App\Models\TimeBlock;
use App\Models\Venue;
use Carbon\Carbon;
use Exception;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class SpecialDayService
{
    public function __construct(protected SpecialDay $specialDayModel)
    {}

    public function all(Request $request)
    {
        $query = $this->specialDayModel::with(['venue', 'listing', 'timeBlocks']);

        if ($request->filled('venue_id')) {
            $query->forVenue($request->venue_id);
        }

        if ($request->filled('listing_id')) {
            $query->forListing($request->listing_id);
        }

        if ($request->filled('type')) {
            $query->where('type', $request->type);
        }

        if ($request->filled('date_from') && $request->filled('date_to')) {
            $start = Carbon::parse($request->date_from);
            $end   = Carbon::parse($request->date_to);
            $query->inDateRange($start, $end);
        }

        $specialDays = $query->orderBy('date', 'asc')->get();

        return sendSuccess('Special days fetched successfully', compact('specialDays'));
    }

    public function calendar(int $venueId, ?int $listingId = null, string $yearMonth): array
    {
        try {
            $calendarData = SpecialDay::getCalendarData($venueId, $listingId, $yearMonth);

            // Get venue/listing names for context
            $venue       = Venue::findOrFail($venueId);
            $listingName = $listingId ? Listing::findOrFail($listingId)->name : 'All Listings';

            return [
                'success' => true,
                'message' => 'Calendar data retrieved successfully',
                'data'    => [
                    'venue'    => $venue->name,
                    'listing'  => $listingName,
                    'calendar' => $calendarData,
                ],
            ];
        } catch (Exception $e) {
            \Log::error('Failed to retrieve calendar data: ' . $e->getMessage());
            return [
                'success' => false,
                'message' => 'Failed to retrieve calendar data',
                'data'    => [],
            ];
        }
    }

    /**
     * Create special day
     */
    public function create(Request $request): array
    {
        try {
            $validated = $request->validate([
                'venue_id'    => 'required|exists:venues,id',
                'listing_id'  => 'nullable|exists:listings,id',
                'date'        => 'required|date|unique:special_days,date,NULL,id,venue_id,' . $request->venue_id,
                'type'        => 'required|in:holiday,special_pricing',
                'price'       => 'nullable|required_if:type,special_pricing|numeric|min:0.01',
                'description' => 'nullable|string|max:500',
                'status'      => 'required|in:active,inactive',
            ]);

            // Ensure listing belongs to venue if specified
            if ($validated['listing_id']) {
                $listing = Listing::findOrFail($validated['listing_id']);
                if ($listing->venue_id !== $validated['venue_id']) {
                    return [
                        'success' => false,
                        'message' => 'Listing does not belong to the selected venue',
                        'data'    => null,
                    ];
                }
            }

            $specialDay = SpecialDay::create($validated);

            return [
                'success' => true,
                'message' => 'Special day created successfully',
                'data'    => $specialDay->load(['venue', 'listing']),
            ];
        } catch (Exception $e) {
            \Log::error('Failed to create special day: ' . $e->getMessage());
            return [
                'success' => false,
                'message' => 'Failed to create special day: ' . $e->getMessage(),
                'data'    => null,
            ];
        }
    }

    /**
     * Update special day
     */
    public function update(Request $request, int $id): array
    {
        try {
            $specialDay = SpecialDay::findOrFail($id);

            $validated = $request->validate([
                'venue_id'    => 'sometimes|exists:venues,id',
                'listing_id'  => 'nullable|exists:listings,id',
                'date'        => 'sometimes|date|unique:special_days,date,' . $id . ',id,venue_id,' . ($request->venue_id ?? $specialDay->venue_id),
                'type'        => 'sometimes|in:holiday,special_pricing',
                'price'       => 'nullable|required_if:type,special_pricing|numeric|min:0.01',
                'description' => 'nullable|string|max:500',
                'status'      => 'sometimes|in:active,inactive',
            ]);

            // Validate listing-venue relationship if changed
            if (isset($validated['listing_id'])) {
                $listing = Listing::findOrFail($validated['listing_id']);
                $venueId = $validated['venue_id'] ?? $specialDay->venue_id;
                if ($listing->venue_id !== $venueId) {
                    return [
                        'success' => false,
                        'message' => 'Listing does not belong to the selected venue',
                        'data'    => null,
                    ];
                }
            }

            $specialDay->update($validated);

            return [
                'success' => true,
                'message' => 'Special day updated successfully',
                'data'    => $specialDay->load(['venue', 'listing']),
            ];
        } catch (Exception $e) {
            \Log::error('Failed to update special day: ' . $e->getMessage());
            return [
                'success' => false,
                'message' => 'Failed to update special day: ' . $e->getMessage(),
                'data'    => null,
            ];
        }
    }

    public function delete(int $id): array
    {
        try {
            $specialDay = SpecialDay::findOrFail($id);
            $specialDay->delete();

            return [
                'success' => true,
                'message' => 'Special day deleted successfully',
                'data'    => null,
            ];
        } catch (Exception $e) {
            \Log::error('Failed to delete special day: ' . $e->getMessage());
            return [
                'success' => false,
                'message' => 'Failed to delete special day: ' . $e->getMessage(),
                'data'    => null,
            ];
        }
    }

    public function bulkCreate($request)
    {

        $validated = $request->validate([
            'dates'                        => 'required|array|min:1',
            'dates.*'                      => 'required|date_format:Y-m-d',
            'venue_id'                     => 'required|exists:venues,id',
            'listing_id'                   => 'nullable|exists:listings,id',
            'type'                         => 'required|in:holiday,special',
            'description'                  => 'nullable|string|max:500',
            'time_blocks'                  => 'required_if:type,special|array|min:0',
            'time_blocks.*.start_time'     => 'required_with:end_time|date_format:H:i',
            'time_blocks.*.end_time'       => 'required_with:start_time|date_format:H:i|after:time_blocks.*.start_time',
            'time_blocks.*.price_per_hour' => 'required|numeric|min:0',
            'time_blocks.*.price_currency' => 'nullable|string|in:qar,usd',
        ], [
            'time_blocks.*.end_time.after' => 'End time must be after start time.',
        ]);

        DB::beginTransaction();
        try {
            $createdSpecialDays = [];
            foreach ($validated['dates'] as $dateString) {
                // Check for existing special day
                $existing = SpecialDay::where('venue_id', $validated['venue_id'])
                    ->where('date', $dateString)
                    ->where('listing_id', $validated['listing_id'])
                    ->first();

                if ($existing) {
                    return response()->json([
                        'success' => false,
                        'message' => "Special day already exists for date: {$dateString}",
                    ], 409);
                }

                // Create special day
                $specialDay = SpecialDay::create([
                    'venue_id'    => $validated['venue_id'],
                    'listing_id'  => $validated['listing_id'],
                    'date'        => Carbon::parse($dateString),
                    'type'        => $validated['type'],
                    'description' => $validated['description'] ?? null,
                    'status'      => 'active',
                ]);

                // Create time blocks if not holiday
                if ($validated['type'] === 'special' && ! empty($validated['time_blocks'])) {
                    foreach ($validated['time_blocks'] as $timeBlock) {
                        TimeBlock::create([
                            'special_day_id' => $specialDay->id,
                            'start_time'     => $timeBlock['start_time'],
                            'end_time'       => $timeBlock['end_time'],
                            'price_per_hour' => $timeBlock['price_per_hour'],
                            'price_currency' => $timeBlock['price_currency'] ?? 'qar',
                        ]);
                    }
                }

                $createdSpecialDays[] = $specialDay->load(['venue', 'listing', 'timeBlocks']);
            }

            DB::commit();

            return response()->json([
                'success' => true,
                'message' => 'Special days created successfully',
                'data'    => [
                    'specialDays' => $createdSpecialDays,
                ],
            ]);
        } catch (Exception $e) {
            DB::rollBack();
            return response()->json([
                'success' => false,
                'message' => 'Failed to create special days: ' . $e->getMessage(),
            ], 500);
        }
    }
}
