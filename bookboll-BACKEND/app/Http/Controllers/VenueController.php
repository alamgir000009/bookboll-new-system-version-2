<?php
namespace App\Http\Controllers;

use App\Models\Venue;
use Illuminate\Http\Request;

class VenueController extends Controller
{
    public function store(Request $request)
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
            'schedule.*.from_time'  => ['required_if:schedule.*.open,true', 'string', 'regex:/^\d{2}:\d{2}$/'],
            'schedule.*.to_time'    => ['required_if:schedule.*.open,true', 'string', 'regex:/^\d{2}:\d{2}$/', 'after:schedule.*.from'],
        ]);

        $schedules = $formData['schedule'];
        unset($formData['schedule']);

        $venue = Venue::create($formData);

        foreach ($schedules as $schedule) {
            $venue->schedules()->create($schedule);
        }
        $venue->load('schedules');

        return sendSuccess('Venue added successfully', compact('venue'));
    }

    public function update(Request $request, Venue $venue)
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
            'schedule.*.from_time'  => ['required_if:schedule.*.is_open,true', 'string', 'regex:/^\d{2}:\d{2}$/'],
            'schedule.*.to_time'    => ['required_if:schedule.*.is_open,true', 'string', 'regex:/^\d{2}:\d{2}$/', 'after:schedule.*.from_time'],
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
                    'is_open'   => $schedule['is_open'],
                    'from_time' => $schedule['from_time'],
                    'to_time'   => $schedule['to_time'],
                ]
            );

            $scheduleIds[] = $scheduleModel->id;
        }

        $venue->schedules()->whereNotIn('id', $scheduleIds)->delete();

        $venue->load('schedules');

        return sendSuccess('Venue updated successfully', compact('venue'));
    }

    public function destroy(Venue $venue)
    {
        $venue->delete();
        return sendSuccess('Venue deleted successfull');
    }

}
