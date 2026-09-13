<?php
namespace App\Services;

use App\Models\Booking;
use App\Support\AccessScope;
use App\Models\Listing;
use App\Models\SpecialDay;
use App\Models\Venue;
use BookingStatus;
use Carbon\Carbon;
use Exception;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;

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

    /**
     * New admin dashboard Special Days page payload
     * (locations + listings + mapped special_days).
     */
    public function dashboardPage(Request $request)
    {
        $user = auth()->user();
        $venueQuery = Venue::query()->orderBy('sort_order')->orderBy('name');
        AccessScope::applyVenue($venueQuery, 'id', $user);

        $venueCols = ['id', 'name', 'status', 'sort_order', 'created_by'];
        foreach (['city_id', 'country_id', 'owner_user_id', 'currency_override'] as $col) {
            if (Schema::hasColumn('venues', $col)) {
                $venueCols[] = $col;
            }
        }

        $venues = $venueQuery->with('users')->get($venueCols);
        $venueIds = $venues->pluck('id')->all();

        $locations = $venues->map(function (Venue $v) {
            $ownerId = null;
            if (Schema::hasColumn('venues', 'owner_user_id') && $v->owner_user_id) {
                $ownerId = $v->owner_user_id;
            } else {
                $ownerId = $v->relationLoaded('users')
                    ? ($v->users->first()?->id ?? $v->created_by)
                    : $v->created_by;
            }

            return [
                'id'            => (string) $v->id,
                'name'          => $v->name,
                'status'        => $v->status,
                'city_id'       => $v->city_id ? (string) $v->city_id : null,
                'country_id'    => $v->country_id ? (string) $v->country_id : null,
                'owner_user_id' => $ownerId ? (string) $ownerId : null,
                'sort_order'    => (int) ($v->sort_order ?? 0),
            ];
        })->values();

        $listings = $venueIds
            ? Listing::with('sportsCategory:id,name')
                ->whereIn('venue_id', $venueIds)
                ->orderBy('sort_order')
                ->orderBy('name')
                ->get()
                ->map(fn (Listing $l) => [
                    'id'             => (string) $l->id,
                    'location_id'    => $l->venue_id ? (string) $l->venue_id : null,
                    'name'           => $l->name,
                    'base_price'     => $l->base_price !== null ? (float) $l->base_price : null,
                    'sport_category' => $l->sportsCategory?->name,
                    'status'         => $l->status,
                ])->values()
            : collect();

        $daysQuery = SpecialDay::with('timeBlocks')->orderBy('date');
        if ($venueIds) {
            $daysQuery->whereIn('venue_id', $venueIds);
        } else {
            $daysQuery->whereRaw('1 = 0');
        }

        $specialDays = $daysQuery->get()
            ->flatMap(fn (SpecialDay $sd) => $this->mapToDashboard($sd))
            ->values();

        return sendSuccess('Special days dashboard fetched', [
            'locations'    => $locations,
            'listings'     => $listings,
            'special_days' => $specialDays,
        ]);
    }

    /**
     * Bulk create / upsert special days for the new dashboard UI.
     */
    public function dashboardStore(Request $request)
    {
        $validated = $request->validate([
            'rows'                    => 'required|array|min:1',
            'rows.*.date'             => 'required|date_format:Y-m-d',
            'rows.*.location_id'      => 'required|exists:venues,id',
            'rows.*.listing_id'       => 'nullable|exists:listings,id',
            'rows.*.name'             => 'nullable|string|max:500',
            'rows.*.type'             => 'required|in:holiday,custom,special',
            'rows.*.is_closed'        => 'nullable|boolean',
            'rows.*.open_time'        => 'nullable|date_format:H:i',
            'rows.*.close_time'       => 'nullable|date_format:H:i',
            'rows.*.custom_price'     => 'nullable|numeric|min:0',
            'rows.*.price_multiplier' => 'nullable|numeric|min:0.01',
        ]);

        DB::beginTransaction();
        try {
            $created = [];
            foreach ($validated['rows'] as $row) {
                $payload = $this->normalizeDashboardRow($row);
                if ($err = $this->assertVenueAccess((int) $payload['venue_id'])) {
                    DB::rollBack();
                    return $err;
                }
                if ($err = $this->validateCustomWindow($payload)) {
                    DB::rollBack();
                    return $err;
                }

                $day = $this->upsertDashboardDay($payload);
                $created[] = $day;
            }

            DB::commit();

            $mapped = collect($created)
                ->flatMap(fn (SpecialDay $sd) => $this->mapToDashboard($sd->load('timeBlocks')))
                ->values();

            return sendSuccess('Special days saved', ['special_days' => $mapped]);
        } catch (\Throwable $e) {
            DB::rollBack();
            Log::error('dashboardStore special days failed: ' . $e->getMessage(), ['exception' => $e]);
            return sendError('Failed to save special days');
        }
    }

    public function dashboardUpdate(Request $request, $id)
    {
        $dayId = $this->parseSpecialDayId($id);
        $validated = $request->validate([
            'date'             => 'required|date_format:Y-m-d',
            'location_id'      => 'required|exists:venues,id',
            'listing_id'       => 'nullable|exists:listings,id',
            'name'             => 'nullable|string|max:500',
            'type'             => 'required|in:holiday,custom,special',
            'is_closed'        => 'nullable|boolean',
            'open_time'        => 'nullable|date_format:H:i',
            'close_time'       => 'nullable|date_format:H:i',
            'custom_price'     => 'nullable|numeric|min:0',
            'price_multiplier' => 'nullable|numeric|min:0.01',
        ]);

        $specialDay = SpecialDay::with('timeBlocks')->find($dayId);
        if (! $specialDay) {
            return sendError('Special day not found', [], 404);
        }

        $payload = $this->normalizeDashboardRow($validated);
        if ($err = $this->assertVenueAccess((int) $payload['venue_id'])) {
            return $err;
        }
        if ($err = $this->assertVenueAccess((int) $specialDay->venue_id)) {
            return $err;
        }
        if ($err = $this->validateCustomWindow($payload)) {
            return $err;
        }

        DB::beginTransaction();
        try {
            $this->applyDashboardPayload($specialDay, $payload);
            DB::commit();

            $mapped = $this->mapToDashboard($specialDay->fresh('timeBlocks'));

            return sendSuccess('Special day updated', [
                'special_days' => $mapped,
            ]);
        } catch (\Throwable $e) {
            DB::rollBack();
            Log::error('dashboardUpdate special day failed: ' . $e->getMessage(), ['exception' => $e]);
            return sendError('Failed to update special day');
        }
    }

    public function dashboardDestroy($id)
    {
        $dayId = $this->parseSpecialDayId($id);
        $specialDay = SpecialDay::find($dayId);
        if (! $specialDay) {
            return sendError('Special day not found', [], 404);
        }
        if ($err = $this->assertVenueAccess((int) $specialDay->venue_id)) {
            return $err;
        }

        $specialDay->timeBlocks()->delete();
        $specialDay->delete();

        return sendSuccess('Special day deleted');
    }

    public function dashboardBulkClear(Request $request)
    {
        $validated = $request->validate([
            'ids'   => 'required|array|min:1',
            'ids.*' => 'required',
        ]);

        $dayIds = collect($validated['ids'])
            ->map(fn ($id) => $this->parseSpecialDayId($id))
            ->filter()
            ->unique()
            ->values()
            ->all();

        if (! $dayIds) {
            return sendError('No special days to clear');
        }

        $days = SpecialDay::whereIn('id', $dayIds)->get();
        foreach ($days as $day) {
            if ($err = $this->assertVenueAccess((int) $day->venue_id)) {
                return $err;
            }
        }

        DB::beginTransaction();
        try {
            $days = SpecialDay::whereIn('id', $dayIds)->get();
            foreach ($days as $day) {
                $day->timeBlocks()->delete();
                $day->delete();
            }
            DB::commit();

            return sendSuccess('Special days cleared', ['deleted' => $days->count()]);
        } catch (\Throwable $e) {
            DB::rollBack();
            Log::error('dashboardBulkClear failed: ' . $e->getMessage(), ['exception' => $e]);
            return sendError('Failed to clear special days');
        }
    }

    public function dashboardConflicts(Request $request)
    {
        $validated = $request->validate([
            'dates'         => 'required|array|min:1',
            'dates.*'       => 'required|date_format:Y-m-d',
            'location_ids'  => 'required|array|min:1',
            'location_ids.*'=> 'required|exists:venues,id',
            'listing_ids'   => 'nullable|array',
            'listing_ids.*' => 'integer|exists:listings,id',
        ]);

        $query = Booking::query()
            ->whereIn('date', $validated['dates'])
            ->whereIn('venue_id', $validated['location_ids'])
            ->where('status', '!=', BookingStatus::CANCELLED);

        if (! empty($validated['listing_ids'])) {
            $query->whereIn('listing_id', $validated['listing_ids']);
        }

        return sendSuccess('Conflicts counted', [
            'count' => (int) $query->count(),
        ]);
    }

    /**
     * Map a SpecialDay (+ time blocks) to the apps/admin UI shape.
     * @return array<int, array<string, mixed>>
     */
    public function mapToDashboard(SpecialDay $sd): array
    {
        $dateStr  = $sd->date instanceof \Carbon\CarbonInterface
            ? $sd->date->format('Y-m-d')
            : (string) $sd->date;
        $isClosed = strtolower((string) $sd->type) === 'holiday';
        $uiType   = $isClosed ? 'holiday' : 'custom';
        $blocks   = $sd->relationLoaded('timeBlocks') ? $sd->timeBlocks : $sd->timeBlocks()->get();

        $multiplier = 1.0;
        if ($sd->price_multiplier !== null && (float) $sd->price_multiplier > 0) {
            $multiplier = (float) $sd->price_multiplier;
        }

        $dayPrice = $sd->price !== null ? (float) $sd->price : null;

        if ($isClosed || $blocks->isEmpty()) {
            return [[
                'id'               => (string) $sd->id,
                'location_id'      => $sd->venue_id ? (string) $sd->venue_id : null,
                'listing_id'       => $sd->listing_id ? (string) $sd->listing_id : null,
                'name'             => $sd->description ?: ($isClosed ? 'Closed' : 'Special schedule'),
                'date'             => $dateStr,
                'type'             => $uiType,
                'is_closed'        => $isClosed,
                'open_time'        => null,
                'close_time'       => null,
                'custom_price'     => $dayPrice,
                'price_multiplier' => $multiplier,
            ]];
        }

        return $blocks->map(function ($block) use ($sd, $dateStr, $uiType, $dayPrice, $multiplier) {
            $blockPrice = $block->price_per_hour !== null ? (float) $block->price_per_hour : null;
            $customPrice = $dayPrice !== null ? $dayPrice : $blockPrice;

            return [
                'id'               => (string) $sd->id . '-' . $block->id,
                'location_id'      => $sd->venue_id ? (string) $sd->venue_id : null,
                'listing_id'       => $sd->listing_id ? (string) $sd->listing_id : null,
                'name'             => $sd->description ?: 'Special schedule',
                'date'             => $dateStr,
                'type'             => $uiType,
                'is_closed'        => false,
                'open_time'        => $this->fmtTime($block->start_time),
                'close_time'       => $this->fmtTime($block->end_time),
                'custom_price'     => $customPrice,
                'price_multiplier' => $multiplier,
            ];
        })->all();
    }

    protected function normalizeDashboardRow(array $row): array
    {
        $uiType   = strtolower((string) ($row['type'] ?? 'holiday'));
        $isClosed = array_key_exists('is_closed', $row)
            ? (bool) $row['is_closed']
            : in_array($uiType, ['holiday'], true);

        if (in_array($uiType, ['holiday'], true) || $isClosed) {
            $dbType   = 'holiday';
            $isClosed = true;
        } else {
            $dbType   = 'special';
            $isClosed = false;
        }

        $multiplier = isset($row['price_multiplier']) && $row['price_multiplier'] !== null
            ? (float) $row['price_multiplier']
            : 1.0;
        if ($multiplier <= 0) {
            $multiplier = 1.0;
        }

        $customPrice = array_key_exists('custom_price', $row) && $row['custom_price'] !== null && $row['custom_price'] !== ''
            ? (float) $row['custom_price']
            : null;

        return [
            'venue_id'         => (int) $row['location_id'],
            'listing_id'       => ! empty($row['listing_id']) ? (int) $row['listing_id'] : null,
            'date'             => $row['date'],
            'type'             => $dbType,
            'is_closed'        => $isClosed,
            'description'      => $row['name'] ?? null,
            'open_time'        => $isClosed ? null : ($row['open_time'] ?? null),
            'close_time'       => $isClosed ? null : ($row['close_time'] ?? null),
            'price'            => $isClosed ? null : $customPrice,
            'price_multiplier' => $isClosed ? 1.0 : $multiplier,
        ];
    }

    protected function validateCustomWindow(array $payload)
    {
        if ($payload['type'] !== 'special') {
            return null;
        }
        if (empty($payload['open_time']) || empty($payload['close_time'])) {
            return sendError('Custom schedule requires open and close times.');
        }

        $start = Carbon::parse($payload['open_time']);
        $end   = Carbon::parse($payload['close_time']);
        if ($payload['close_time'] === '00:00') {
            $end->addDay();
        }
        if ($end->lessThanOrEqualTo($start)) {
            return sendError('Close time must be after open time.');
        }

        return null;
    }

    protected function upsertDashboardDay(array $payload): SpecialDay
    {
        $query = SpecialDay::where('venue_id', $payload['venue_id'])
            ->where('date', $payload['date']);

        if ($payload['listing_id'] === null) {
            $query->whereNull('listing_id');
        } else {
            $query->where('listing_id', $payload['listing_id']);
        }

        $day = $query->first();
        if (! $day) {
            $day = new SpecialDay([
                'venue_id'   => $payload['venue_id'],
                'listing_id' => $payload['listing_id'],
                'date'       => $payload['date'],
                'status'     => 'active',
            ]);
        }

        $this->applyDashboardPayload($day, $payload);

        return $day->fresh('timeBlocks');
    }

    protected function applyDashboardPayload(SpecialDay $day, array $payload): void
    {
        $attrs = [
            'venue_id'    => $payload['venue_id'],
            'listing_id'  => $payload['listing_id'],
            'date'        => $payload['date'],
            'type'        => $payload['type'],
            'description' => $payload['description'],
            'status'      => 'active',
        ];

        if (Schema::hasColumn('special_days', 'price')) {
            $attrs['price'] = $payload['price'];
        }
        if (Schema::hasColumn('special_days', 'price_multiplier')) {
            $attrs['price_multiplier'] = $payload['price_multiplier'];
        }

        $day->fill($attrs);
        $day->save();

        $day->timeBlocks()->delete();

        if ($payload['type'] === 'special') {
            $day->timeBlocks()->create([
                'start_time'     => $payload['open_time'],
                'end_time'       => $payload['close_time'],
                'price_per_hour' => $payload['price'],
                'price_currency' => 'qar',
            ]);
        }
    }

    protected function assertVenueAccess(int $venueId)
    {
        $user = auth()->user();
        if (! $user) {
            return sendError('Unauthorized', [], 401);
        }

        // One authority for "may I touch this venue": ownership, explicit
        // assignment and platform jurisdiction all live in AccessScope. Asking
        // the user_venue pivot directly missed venues a provider OWNS.
        if (AccessScope::canTouchVenue($user, $venueId)) {
            return null;
        }

        return sendError('You do not have access to this venue.', [], 403);
    }

    protected function parseSpecialDayId($id): int
    {
        $raw = (string) $id;
        if (str_contains($raw, '-')) {
            $raw = explode('-', $raw, 2)[0];
        }

        return (int) $raw;
    }

    protected function fmtTime($value): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }
        $s = (string) $value;
        if (strlen($s) >= 5) {
            return substr($s, 0, 5);
        }

        return $s;
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
                if (isset($block['start_time'], $block['end_time'])) {
                    $startTime = Carbon::parse($block['start_time']);
                    $endTime   = Carbon::parse($block['end_time']);

                    // Treat 00:00 as midnight end-of-day (next day) so blocks like 22:00-00:00 are valid
                    if ($block['end_time'] === '00:00') {
                        $endTime->addDay();
                    }

                    if ($endTime->lessThanOrEqualTo($startTime)) {
                        return sendError('End time must be after start time for all time blocks.');
                    }
                }
            }
        }

        DB::beginTransaction();
        try {

            $createdSpecialDays = [];
            $updatedSpecialDays = [];

            foreach ($validated['dates'] as $dateString) {

                $query = SpecialDay::where('venue_id', $validated['venue_id'])
                    ->where('date', $dateString);

                if ($validated['listing_id'] === null) {
                    $query->whereNull('listing_id');
                } else {
                    $query->where('listing_id', $validated['listing_id']);
                }

                $existingSpecialDay = $query->first();

                if ($existingSpecialDay) {
                    if ($existingSpecialDay->type === 'holiday' && $validated['type'] === 'special') {
                        $existingSpecialDay->update([
                            'type'        => 'special',
                            'description' => $validated['description'] ?? $existingSpecialDay->description,
                        ]);

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
                        $existingSpecialDay->update([
                            'type'        => 'holiday',
                            'description' => $validated['description'] ?? $existingSpecialDay->description,
                        ]);
                        $existingSpecialDay->timeBlocks()->delete();
                    } elseif ($existingSpecialDay->type === 'special' && $validated['type'] === 'special') {
                        $existingSpecialDay->update([
                            'description' => $validated['description'] ?? $existingSpecialDay->description,
                        ]);

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
                        $existingSpecialDay->update([
                            'description' => $validated['description'] ?? $existingSpecialDay->description,
                        ]);
                    }

                    $updatedSpecialDays[] = $existingSpecialDay->load(['venue', 'listing', 'timeBlocks']);
                } else {
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
