<?php
namespace App;

use App\Models\Venue;
use Exception;
use Illuminate\Support\Facades\DB;

class VenueService
{
    public function __construct(protected Venue $venueModel)
    {}

    public function all($request)
    {
        $search = $request->search ?? null;

        $venues = $this->venueModel::with(['schedules'])
            ->when($search, function ($query, $search) {
                $query->where(function ($q) use ($search) {
                    $q->where('name', 'LIKE', "%{$search}%")
                        ->orWhere('status', 'LIKE', "%{$search}%")
                        ->orWhere('description', 'LIKE', "%{$search}%")
                        ->orWhere('min_advance_booking', 'LIKE', "%{$search}%")
                        ->orWhere('max_bookings_per_slot', 'LIKE', "%{$search}%");
                });
            })
            ->active()
            ->orderBy('sort_order', 'ASC')
            ->get();

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

        // Validate that end_time is after start_time for each schedule entry
        foreach ($schedules as $index => $schedule) {
            if ($schedule['is_open'] && isset($schedule['start_time']) && isset($schedule['end_time'])) {
                $startTimeStr = $schedule['start_time'];
                $endTimeStr   = $schedule['end_time'];

                // Handle 24-hour operation (00:00 to 00:00)
                if ($startTimeStr === '00:00' && $endTimeStr === '00:00') {
                    // 24-hour operation is valid
                    continue;
                }

                // Convert to minutes for easier comparison
                $startMinutes = $this->timeToMinutes($startTimeStr);
                $endMinutes   = $this->timeToMinutes($endTimeStr);

                // Handle wrap-around times (e.g., 23:30 to 00:00 means until midnight)
                if ($endMinutes === 0 && $endTimeStr === '00:00') {
                    // End time is midnight (00:00), which is valid for any start time
                    // This represents "until end of day"
                    continue;
                }

                // Normal case: end must be after start
                // But if end is 00:00 and start is not 00:00, it's a wrap-around (valid)
                if ($endMinutes === 0 && $startMinutes > 0) {
                    // Wrap-around case: 23:xx to 00:00 is valid
                    continue;
                }

                // For same-day times, end must be after start
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
        logger($request->all());
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

        // Validate that end_time is after start_time for each schedule entry
        foreach ($schedules as $index => $schedule) {
            if ($schedule['is_open'] && isset($schedule['start_time']) && isset($schedule['end_time'])) {
                $startTimeStr = $schedule['start_time'];
                $endTimeStr   = $schedule['end_time'];

                // Handle 24-hour operation (00:00 to 00:00)
                if ($startTimeStr === '00:00' && $endTimeStr === '00:00') {
                    // 24-hour operation is valid
                    continue;
                }

                // Convert to minutes for easier comparison
                $startMinutes = $this->timeToMinutes($startTimeStr);
                $endMinutes   = $this->timeToMinutes($endTimeStr);

                // Handle wrap-around times (e.g., 23:30 to 00:00 means until midnight)
                if ($endMinutes === 0 && $endTimeStr === '00:00') {
                    // End time is midnight (00:00), which is valid for any start time
                    // This represents "until end of day"
                    continue;
                }

                // Normal case: end must be after start
                // But if end is 00:00 and start is not 00:00, it's a wrap-around (valid)
                if ($endMinutes === 0 && $startMinutes > 0) {
                    // Wrap-around case: 23:xx to 00:00 is valid
                    continue;
                }

                // For same-day times, end must be after start
                if ($endMinutes <= $startMinutes && $endMinutes !== 0) {
                    return sendError("Schedule entry #{$index}: end_time must be after start_time", [], 422);
                }
            }
        }

        $venue->update($formData);

        // Sync schedules intelligently - only update what changed
        $this->syncSchedules($venue, $schedules);

        $venue->load('schedules');

        return sendSuccess('Venue updated successfully', compact('venue'));
    }

    public function getVenue($id)
    {
        $venue = $this->venueModel::with(['schedules', 'users', 'listings'])->findOrFail($id);
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

        $sortOrders = collect($updates)->pluck('sort_order')->sort()->values()->toArray();
        if (count(array_unique($sortOrders)) !== count($sortOrders) || $sortOrders[0] !== 1
            || end($sortOrders) !== count($sortOrders)) {
            return sendError('Sort orders must be unique, sequential integers starting from 1.');
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
            return sendError('Failed to update sort order: ' . $e->getMessage());
        }
    }

    /**
     * Convert time string (HH:MM) to minutes since midnight
     *
     * @param string $time Time in HH:MM format
     * @return int Minutes since midnight
     */
    protected function timeToMinutes($time)
    {
        if ($time === '00:00' || $time === '24:00') {
            return 0; // Treat both 00:00 and 24:00 as midnight
        }

        list($hours, $minutes) = explode(':', $time);
        return (int) $hours * 60 + (int) $minutes;
    }

    /**
     * Sync schedules intelligently - only update what changed
     *
     * @param Venue $venue
     * @param array $newSchedules
     * @return void
     */
    protected function syncSchedules($venue, array $newSchedules)
    {
        // Load existing schedules
        $existingSchedules = $venue->schedules()->get();

        // Normalize new schedules for comparison
        $normalizedNewSchedules = collect($newSchedules)->map(function ($schedule) {
            return [
                'day'        => $schedule['day'],
                'is_open'    => $schedule['is_open'],
                'start_time' => $schedule['is_open'] ? ($schedule['start_time'] ?? null) : null,
                'end_time'   => $schedule['is_open'] ? ($schedule['end_time'] ?? null) : null,
            ];
        });

        // Normalize existing schedules for comparison
        $normalizedExistingSchedules = $existingSchedules->map(function ($schedule) {
            // Convert datetime to string format for comparison
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

        // Find schedules to delete (exist in DB but not in new data)
        $schedulesToDelete = $normalizedExistingSchedules->filter(function ($existing) use ($normalizedNewSchedules) {
            return ! $normalizedNewSchedules->contains(function ($new) use ($existing) {
                return $this->schedulesMatch($existing, $new);
            });
        });

        // Find schedules to create (exist in new data but not in DB)
        $schedulesToCreate = $normalizedNewSchedules->filter(function ($new) use ($normalizedExistingSchedules) {
            return ! $normalizedExistingSchedules->contains(function ($existing) use ($new) {
                return $this->schedulesMatch($existing, $new);
            });
        });

        // Delete schedules that no longer exist
        if ($schedulesToDelete->isNotEmpty()) {
            $idsToDelete = $schedulesToDelete->pluck('id')->toArray();
            $venue->schedules()->whereIn('id', $idsToDelete)->delete();
        }

        // Create new schedules
        foreach ($schedulesToCreate as $schedule) {
            $venue->schedules()->create($schedule);
        }
    }

    /**
     * Check if two schedule entries match
     *
     * @param array $schedule1
     * @param array $schedule2
     * @return bool
     */
    protected function schedulesMatch($schedule1, $schedule2)
    {
        return $schedule1['day'] === $schedule2['day'] &&
               $schedule1['is_open'] === $schedule2['is_open'] &&
               $schedule1['start_time'] === $schedule2['start_time'] &&
               $schedule1['end_time'] === $schedule2['end_time'];
    }
}
