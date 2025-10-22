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

            foreach ($validated['dates'] as $dateString) {

                $exists = SpecialDay::where('venue_id', $validated['venue_id'])
                    ->where('date', $dateString)
                    ->where('listing_id', $validated['listing_id'])
                    ->exists();

                if ($exists) {
                    DB::rollBack();
                    return sendError("Special day already exists for date: {$dateString}");
                }

                $specialDay = SpecialDay::create([
                    'venue_id'    => $validated['venue_id'],
                    'listing_id'  => $validated['listing_id'],
                    'date'        => $dateString,
                    'type'        => $validated['type'],
                    'description' => $validated['description'] ?? null,
                    'status'      => 'active',
                ]);

                if ($validated['type'] == 'special' && ! empty($validated['time_blocks'])) {
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

            DB::commit();

            return sendSuccess('Special days created successfully.', ['specialDays' => $createdSpecialDays]);
        } catch (\Throwable $e) {
            DB::rollBack();

            return sendError('Failed to create special days: ' . $e->getMessage());
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
