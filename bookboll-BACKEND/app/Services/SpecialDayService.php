<?php
namespace App\Services;

use App\Models\Listing;
use App\Models\SpecialDay;
use App\Models\Venue;
use Carbon\Carbon;
use Exception;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

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

    public function calendar(int $venueId, ?int $listingId = null, string $yearMonth)
    {
        try {
            $calendarData = SpecialDay::getCalendarData($venueId, $listingId, $yearMonth);

            $venue       = Venue::findOrFail($venueId);
            $listingName = $listingId ? Listing::findOrFail($listingId)->name : 'All Listings';

            return sendSuccess('Calendar data retrieved successfully', [
                'venue'    => $venue->name,
                'listing'  => $listingName,
                'calendar' => $calendarData,
            ]);
        } catch (Exception $e) {
            Log::error('Failed to retrieve calendar data: ' . $e->getMessage());
            return sendError('Failed to retrieve calendar data');
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
            'time_blocks.*.start_time'     => 'required_with:time_blocks.*.end_time|date_format:H:i',
            'time_blocks.*.end_time'       => 'required_with:time_blocks.*.start_time|date_format:H:i',
            'time_blocks.*.price_per_hour' => 'required|numeric|min:0',
            'time_blocks.*.price_currency' => 'nullable|string|in:qar,usd',
        ]);

        if (! empty($validated['time_blocks'])) {
            foreach ($validated['time_blocks'] as $block) {
                if (
                    isset($block['start_time'], $block['end_time']) &&
                    Carbon::parse($block['end_time'])->lessThanOrEqualTo(Carbon::parse($block['start_time']))
                ) {
                    return sendError('End time must be after start time for all time blocks.');
                }
            }
        }

        DB::beginTransaction();
        try {

            $createdSpecialDays = [];
            $updatedSpecialDays = [];

            foreach ($validated['dates'] as $dateString) {

                // Find existing special day - handle null listing_id properly
                $query = SpecialDay::where('venue_id', $validated['venue_id'])
                    ->where('date', $dateString);

                if ($validated['listing_id'] === null) {
                    $query->whereNull('listing_id');
                } else {
                    $query->where('listing_id', $validated['listing_id']);
                }

                $existingSpecialDay = $query->first();

                if ($existingSpecialDay) {
                    // Special day already exists - update it based on the new type and time blocks
                    // Handle conversion scenarios
                    if ($existingSpecialDay->type === 'holiday' && $validated['type'] === 'special') {
                        // Convert holiday to special day and add time blocks
                        $existingSpecialDay->update([
                            'type'        => 'special',
                            'description' => $validated['description'] ?? $existingSpecialDay->description,
                        ]);

                        // Add new time blocks (don't delete existing ones if any)
                        if (!empty($validated['time_blocks'])) {
                            foreach ($validated['time_blocks'] as $block) {
                                $existingSpecialDay->timeBlocks()->create([
                                    'start_time'     => $block['start_time'],
                                    'end_time'       => $block['end_time'],
                                    'price_per_hour' => $block['price_per_hour'],
                                    'price_currency' => $block['price_currency'] ?? 'qar',
                                ]);
                            }
                        }
                    } elseif ($existingSpecialDay->type === 'special' && $validated['type'] === 'holiday') {
                        // Convert special day to holiday (remove all time blocks)
                        $existingSpecialDay->update([
                            'type'        => 'holiday',
                            'description' => $validated['description'] ?? $existingSpecialDay->description,
                        ]);
                        $existingSpecialDay->timeBlocks()->delete();
                    } elseif ($existingSpecialDay->type === 'special' && $validated['type'] === 'special') {
                        // Add more time blocks to existing special day
                        $existingSpecialDay->update([
                            'description' => $validated['description'] ?? $existingSpecialDay->description,
                        ]);

                        // Add new time blocks (append to existing ones)
                        if (!empty($validated['time_blocks'])) {
                            foreach ($validated['time_blocks'] as $block) {
                                $existingSpecialDay->timeBlocks()->create([
                                    'start_time'     => $block['start_time'],
                                    'end_time'       => $block['end_time'],
                                    'price_per_hour' => $block['price_per_hour'],
                                    'price_currency' => $block['price_currency'] ?? 'qar',
                                ]);
                            }
                        }
                    } elseif ($existingSpecialDay->type === 'holiday' && $validated['type'] === 'holiday') {
                        // Update holiday description if provided
                        $existingSpecialDay->update([
                            'description' => $validated['description'] ?? $existingSpecialDay->description,
                        ]);
                    }

                    $updatedSpecialDays[] = $existingSpecialDay->load(['venue', 'listing', 'timeBlocks']);
                } else {
                    // Create new special day
                    $specialDay = SpecialDay::create([
                        'venue_id'    => $validated['venue_id'],
                        'listing_id'  => $validated['listing_id'],
                        'date'        => $dateString,
                        'type'        => $validated['type'],
                        'description' => $validated['description'] ?? null,
                        'status'      => 'active',
                    ]);

                    if ($validated['type'] == 'special' && !empty($validated['time_blocks'])) {
                        foreach ($validated['time_blocks'] as $block) {
                            $specialDay->timeBlocks()->create([
                                'start_time'     => $block['start_time'],
                                'end_time'       => $block['end_time'],
                                'price_per_hour' => $block['price_per_hour'],
                                'price_currency' => $block['price_currency'] ?? 'qar',
                            ]);
                        }
                    }

                    $createdSpecialDays[] = $specialDay->load(['venue', 'listing', 'timeBlocks']);
                }
            }

            DB::commit();

            $message = 'Special days processed successfully.';
            $data = [
                'created' => $createdSpecialDays,
                'updated' => $updatedSpecialDays,
            ];

            if (!empty($createdSpecialDays) && !empty($updatedSpecialDays)) {
                $message = 'Special days created and updated successfully.';
            } elseif (!empty($updatedSpecialDays)) {
                $message = 'Special days updated successfully.';
            }

            return sendSuccess($message, $data);
        } catch (\Throwable $e) {
            DB::rollBack();
            Log::error('Failed to process special days: ' . $e->getMessage(), ['exception' => $e]);

            return sendError('Failed to process special days: ' . $e->getMessage());
        }
    }

    public function update($request, $id)
    {
        $dayId   = $request->input('day_id', $id);
        $blockId = $request->input('block_id');
        $status  = $request->input('status');

        $specialDay = SpecialDay::find($dayId);
        if (! $specialDay) {
            return response()->json(['message' => 'Special day not found'], 404);
        }

        if ($blockId) {

            $block = $specialDay->timeBlocks()->find($blockId);
            if (! $block) {
                return sendError('Time block not found');
            }

            if ($status == 'holiday') {
                $specialDay->update(['type' => 'holiday']);
                $specialDay->timeBlocks()->delete();
                return sendSuccess('Converted block to holiday successfully');
            }

            $block->update([
                'start_time'     => $request->input('start_time'),
                'end_time'       => $request->input('end_time'),
                'price_per_hour' => $request->input('price_per_hour'),
            ]);

            $specialDay->update(['type' => 'special']);
            return sendSuccess('Time block updated successfully');
        }

        if ($specialDay->type == 'holiday' && $status == 'special') {
            $specialDay->update(['type' => 'special']);
            $specialDay->timeBlocks()->delete();

            $specialDay->timeBlocks()->create([
                'start_time'     => $request->input('start_time', '06:00'),
                'end_time'       => $request->input('end_time', '12:00'),
                'price_per_hour' => $request->input('price_per_hour', 100),
            ]);

            return sendSuccess('Converted holiday to special day successfully');
        }

        if ($specialDay->type == 'special' && $status == 'holiday') {
            $specialDay->update(['type' => 'holiday']);
            $specialDay->timeBlocks()->delete();
            return sendSuccess('Converted special day to holiday successfully');
        }

        $specialDay->update(['type' => $status]);

        if ($status == 'special') {

            $specialDay->timeBlocks()->delete();
            $specialDay->timeBlocks()->create([
                'start_time'     => $request->input('start_time'),
                'end_time'       => $request->input('end_time'),
                'price_per_hour' => $request->input('price_per_hour'),
            ]);
        }

        return sendSuccess('Special day updated successfully');
    }

    public function delete($id)
    {
        $blockId = request()->query('block_id');

        if ($blockId) {
            $specialDay = SpecialDay::find($id);

            if (! $specialDay) {
                return sendError('Special day not found');
            }

            $block = $specialDay->timeBlocks()->find($blockId);
            if (! $block) {
                return sendError('Time block not found');
            }

            $block->delete();

            if ($specialDay->timeBlocks()->count() == 0) {
                $specialDay->delete();
            }

            return sendSuccess('Time block deleted successfully');
        }

        $specialDay = SpecialDay::find($id);
        if (! $specialDay) {
            return sendError('Special day not found');
        }

        $specialDay->timeBlocks()->delete();
        $specialDay->delete();

        return sendSuccess('Special day deleted successfully');
    }

}
