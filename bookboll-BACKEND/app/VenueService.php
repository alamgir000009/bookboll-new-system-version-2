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
            'schedule'              => ['required', 'array'],
            'schedule.*.day'        => ['required', 'in:monday,tuesday,wednesday,thursday,friday,saturday,sunday'],
            'schedule.*.is_open'    => ['required', 'boolean'],
            'schedule.*.start_time' => ['nullable', 'required_if:schedule.*.open,true'],
            'schedule.*.end_time'   => ['nullable', 'required_if:schedule.*.open,true', 'after:schedule.*.from'],
        ]);

        $schedules = $formData['schedule'];
        unset($formData['schedule']);

        $formData['sort_order'] = $this->venueModel->max('id') + 1;

        $venue = $this->venueModel::create($formData);

        foreach ($schedules as $schedule) {
            $venue->schedules()->create($schedule);
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
            'schedule'              => ['required', 'array'],
            'schedule.*.day'        => ['required', 'in:monday,tuesday,wednesday,thursday,friday,saturday,sunday'],
            'schedule.*.is_open'    => ['required', 'boolean'],
            'schedule.*.start_time' => ['nullable', 'required_if:schedule.*.is_open,true', 'string'],
            'schedule.*.end_time'   => ['nullable', 'required_if:schedule.*.is_open,true', 'string', 'after:schedule.*.start_time'],
        ]);

        $schedules = $formData['schedule'];
        unset($formData['schedule']);

        $venue->update($formData);

        $scheduleIds = [];

        foreach ($schedules as $schedule) {

            $scheduleModel = $venue->schedules()->updateOrCreate(
                [
                    'day'      => $schedule['day'],
                    'venue_id' => $venue->id,
                ],
                [
                    'is_open'    => $schedule['is_open'],
                    'start_time' => $schedule['start_time'],
                    'end_time'   => $schedule['end_time'],
                ]
            );

            $scheduleIds[] = $scheduleModel->id;
        }

        $venue->schedules()->whereNotIn('id', $scheduleIds)->delete();

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
}
