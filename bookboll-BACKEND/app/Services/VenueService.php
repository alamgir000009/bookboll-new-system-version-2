<?php
namespace App\Services;

use App\Models\Venue;
use Exception;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class VenueService
{
    public function __construct(protected Venue $venueModel)
    {}

    public function all($request, $applyVenueFilter = true)
    {
        $search           = $request->search ?? null;
        $schedule         = $request->boolean('schedule', true);
        $pagination         = $request->boolean('pagination', false);
        $records_per_page = $request->records_per_page ?? 20;

        $query = $this->venueModel::when($search, function ($query, $search) {
                $query->where(function ($q) use ($search) {
                    $q->where('name', 'LIKE', "%{$search}%")
                        ->orWhere('status', 'LIKE', "%{$search}%")
                        ->orWhere('description', 'LIKE', "%{$search}%")
                        ->orWhere('min_advance_booking', 'LIKE', "%{$search}%")
                        ->orWhere('max_bookings_per_slot', 'LIKE', "%{$search}%");
                });
            })
            ->orderBy('sort_order', 'ASC');

        if ($schedule) {
            $query->with(['schedules']);
        }

        if ($applyVenueFilter) {
            $query = applyVenueFilter($query, 'id');
        }

        if ($request->header('from') != 'admin-web') {
            $query->active();
        }

        $venues = $pagination ? $query->paginate($records_per_page) : $query->get();

        return sendSuccess('Venues fetched successfully', compact('venues'));
    }

    public function create($request)
    {
        $formData = $request->validate([
            'name'                  => ['required', 'string', 'max:255'],
            'status'                => ['required', 'in:active,inactive'],
            'maps_url'              => ['required', 'url'],
            'description'           => ['nullable', 'string'],
            'min_advance_booking'   => ['required', 'integer', 'min:0'],
            'max_bookings_per_slot' => ['required', 'integer', 'min:1'],
            'schedule'              => ['required', 'array', 'min:1'],
            'schedule.*.day'        => ['required', 'in:monday,tuesday,wednesday,thursday,friday,saturday,sunday'],
            'schedule.*.is_open'    => ['required', 'boolean'],
            'schedule.*.start_time' => ['nullable', 'required_if:schedule.*.is_open,true', 'string', 'regex:/^([0-1][0-9]|2[0-3]):[0-5][0-9]$|^24:00$/'],
            'schedule.*.end_time'   => ['nullable', 'required_if:schedule.*.is_open,true', 'string', 'regex:/^([0-1][0-9]|2[0-3]):[0-5][0-9]$|^24:00$/'],
        ]);

        $schedules = $formData['schedule'];
        unset($formData['schedule']);

        foreach ($schedules as $index => $schedule) {
            if ($schedule['is_open'] && isset($schedule['start_time']) && isset($schedule['end_time'])) {
                $startTimeStr = $schedule['start_time'];
                $endTimeStr   = $schedule['end_time'];

                if ($startTimeStr === '00:00' && $endTimeStr === '00:00') {
                    continue;
                }

                $startMinutes = $this->timeToMinutes($startTimeStr);
                $endMinutes   = $this->timeToMinutes($endTimeStr);

                if ($endMinutes === 0 && $endTimeStr === '00:00') {
                    continue;
                }

                if ($endMinutes === 0 && $startMinutes > 0) {
                    continue;
                }

                if ($endMinutes <= $startMinutes && $endMinutes !== 0) {
                    return sendError("Schedule entry #{$index}: end_time must be after start_time", [], 422);
                }
            }
        }

        $formData['sort_order'] = $this->venueModel->max('id') + 1;

        $venue = $this->venueModel::create($formData);

        foreach ($schedules as $schedule) {
            $scheduleData = [
                'day'        => $schedule['day'],
                'is_open'    => $schedule['is_open'],
                'start_time' => $schedule['is_open'] ? ($schedule['start_time'] ?? null) : null,
                'end_time'   => $schedule['is_open'] ? ($schedule['end_time'] ?? null) : null,
            ];
            $venue->schedules()->create($scheduleData);
        }
        $venue->load('schedules');

        return sendSuccess('Venue added successfully', compact('venue'));
    }

    public function update($request, $id)
    {
        $venue    = $this->venueModel->findOrFail($id);
        $formData = $request->validate([
            'name'                  => ['required', 'string', 'max:255'],
            'status'                => ['required', 'in:active,inactive'],
            'maps_url'              => ['required', 'url'],
            'description'           => ['nullable', 'string'],
            'min_advance_booking'   => ['required', 'integer', 'min:0'],
            'max_bookings_per_slot' => ['required', 'integer', 'min:1'],
            'schedule'              => ['required', 'array', 'min:1'],
            'schedule.*.day'        => ['required', 'in:monday,tuesday,wednesday,thursday,friday,saturday,sunday'],
            'schedule.*.is_open'    => ['required', 'boolean'],
            'schedule.*.start_time' => ['nullable', 'required_if:schedule.*.is_open,true', 'string', 'regex:/^([0-1][0-9]|2[0-3]):[0-5][0-9]$|^24:00$/'],
            'schedule.*.end_time'   => ['nullable', 'required_if:schedule.*.is_open,true', 'string', 'regex:/^([0-1][0-9]|2[0-3]):[0-5][0-9]$|^24:00$/'],
        ]);

        $schedules = $formData['schedule'];
        unset($formData['schedule']);

        foreach ($schedules as $index => $schedule) {
            if ($schedule['is_open'] && isset($schedule['start_time']) && isset($schedule['end_time'])) {
                $startTimeStr = $schedule['start_time'];
                $endTimeStr   = $schedule['end_time'];

                if ($startTimeStr === '00:00' && $endTimeStr === '00:00') {
                    continue;
                }

                $startMinutes = $this->timeToMinutes($startTimeStr);
                $endMinutes   = $this->timeToMinutes($endTimeStr);

                if ($endMinutes === 0 && $endTimeStr === '00:00') {
                    continue;
                }

                if ($endMinutes === 0 && $startMinutes > 0) {
                    continue;
                }

                if ($endMinutes <= $startMinutes && $endMinutes !== 0) {
                    return sendError("Schedule entry #{$index}: end_time must be after start_time", [], 422);
                }
            }
        }

        $venue->update($formData);

        $this->syncSchedules($venue, $schedules);

        $venue->load('schedules');

        return sendSuccess('Venue updated successfully', compact('venue'));
    }

    public function getVenue($id)
    {
        $venue = $this->venueModel::with(['schedules', 'users', 'listings'])->findOrFail($id);

        $user = auth()->user();
        if ($user && ! $user->hasAnyRole(['super_admin', 'admin'])) {
            $venueIds = DB::table('user_venue')
                ->where('user_id', $user->id)
                ->pluck('venue_id')
                ->toArray();
            if (! in_array($venue->id, $venueIds)) {
                return sendError('You do not have access to this venue', [], 403);
            }
        }

        return sendSuccess('Venue fetched successfully', compact('venue'));
    }

    public function updateSortingOrder($request)
    {
        $request->validate([
            'updates'              => 'required|array|min:1',
            'updates.*.id'         => 'required|integer|exists:venues,id',
            'updates.*.sort_order' => 'required|integer|min:1',
        ]);

        $updates  = $request->input('updates');
        $venueIds = collect($updates)->pluck('id')->toArray();

        if (count(array_unique($venueIds)) !== count($venueIds)) {
            return sendError('Each venue can only appear once in the updates.');
        }

        $sortOrders = collect($updates)->pluck('sort_order')->toArray();
        if (count(array_unique($sortOrders)) !== count($sortOrders)) {
            return sendError('Sort orders must be unique within the request.');
        }

        DB::beginTransaction();

        try {
            foreach ($updates as $update) {
                $this->venueModel::where('id', $update['id'])->update(['sort_order' => $update['sort_order']]);
            }

            DB::commit();

            return sendSuccess('Sort order updated successfully.', ['data' => ['updated_venues' => $venueIds]]);

        } catch (Exception $e) {
            DB::rollBack();
            Log::error('Failed to update venue sort order: ' . $e->getMessage(), ['Exception' => $e]);
            return sendError('Failed to update sort order: ' . $e->getMessage());
        }
    }

    public function moveOnePosition($id, $request)
    {
        $request->validate([
            'direction' => 'required|in:up,down',
        ]);

        $venue = $this->venueModel::find($id);
        if (! $venue) {
            return sendError('Venue not found.', [], 404);
        }

        $direction   = $request->input('direction');
        $currentSort = (int) ($venue->sort_order ?? 0);

        $neighborQuery = $this->venueModel::query();
        if ($direction === 'up') {
            $neighbor = $neighborQuery->where('sort_order', '<', $currentSort)
                ->orderByDesc('sort_order')
                ->orderByDesc('id')
                ->first();
        } else {
            $neighbor = $neighborQuery->where('sort_order', '>', $currentSort)
                ->orderBy('sort_order')
                ->orderBy('id')
                ->first();
        }

        if (! $neighbor) {
            return sendError('No adjacent venue to swap with.', [], 422);
        }

        DB::beginTransaction();

        try {
            $neighborSort = (int) ($neighbor->sort_order ?? 0);

            $venue->update(['sort_order' => $neighborSort]);
            $neighbor->update(['sort_order' => $currentSort]);

            DB::commit();

            return sendSuccess('Venue moved successfully.', [
                'data' => [
                    'venue'    => ['id' => $venue->id, 'sort_order' => $neighborSort],
                    'neighbor' => ['id' => $neighbor->id, 'sort_order' => $currentSort],
                ],
            ]);
        } catch (Exception $e) {
            DB::rollBack();
            Log::error('Failed to move venue: ' . $e->getMessage(), ['Exception' => $e]);
            return sendError('Failed to move venue: ' . $e->getMessage());
        }
    }

    protected function timeToMinutes($time)
    {
        if ($time === '00:00' || $time === '24:00') {
            return 0;
        }

        list($hours, $minutes) = explode(':', $time);
        return (int) $hours * 60 + (int) $minutes;
    }

    protected function syncSchedules($venue, array $newSchedules)
    {
        $existingSchedules = $venue->schedules()->get();

        $normalizedNewSchedules = collect($newSchedules)->map(function ($schedule) {
            return [
                'day'        => $schedule['day'],
                'is_open'    => $schedule['is_open'],
                'start_time' => $schedule['is_open'] ? ($schedule['start_time'] ?? null) : null,
                'end_time'   => $schedule['is_open'] ? ($schedule['end_time'] ?? null) : null,
            ];
        });

        $normalizedExistingSchedules = $existingSchedules->map(function ($schedule) {
            $startTime = $schedule->start_time ?
            (is_string($schedule->start_time) ? $schedule->start_time : $schedule->start_time->format('H:i'))
                : null;
            $endTime = $schedule->end_time ?
            (is_string($schedule->end_time) ? $schedule->end_time : $schedule->end_time->format('H:i'))
                : null;

            return [
                'id'         => $schedule->id,
                'day'        => $schedule->day,
                'is_open'    => $schedule->is_open,
                'start_time' => $startTime,
                'end_time'   => $endTime,
            ];
        });

        $schedulesToDelete = $normalizedExistingSchedules->filter(function ($existing) use ($normalizedNewSchedules) {
            return ! $normalizedNewSchedules->contains(function ($new) use ($existing) {
                return $this->schedulesMatch($existing, $new);
            });
        });

        $schedulesToCreate = $normalizedNewSchedules->filter(function ($new) use ($normalizedExistingSchedules) {
            return ! $normalizedExistingSchedules->contains(function ($existing) use ($new) {
                return $this->schedulesMatch($existing, $new);
            });
        });

        if ($schedulesToDelete->isNotEmpty()) {
            $idsToDelete = $schedulesToDelete->pluck('id')->toArray();
            $venue->schedules()->whereIn('id', $idsToDelete)->whereNull('listing_id')->delete();
        }

        foreach ($schedulesToCreate as $schedule) {
            $venue->schedules()->create($schedule);
        }
    }

    protected function schedulesMatch($schedule1, $schedule2)
    {
        return $schedule1['day'] === $schedule2['day'] &&
            $schedule1['is_open'] === $schedule2['is_open'] &&
            $schedule1['start_time'] === $schedule2['start_time'] &&
            $schedule1['end_time'] === $schedule2['end_time'];
    }
}
