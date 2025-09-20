<?php
namespace App;

use App\Models\Venue;

class VenueService
{
    public function __construct(protected Venue $venueModel)
    {}

    public function all($request)
    {
        $search = $request->search ?? null;

        $venues = $this->venueModel::latest()
            ->when($search, function ($query, $search) {
                $query->where(function ($q) use ($search) {
                    $q->where('name', 'LIKE', "%{$search}%")
                        ->orWhere('status', 'LIKE', "%{$search}%")
                        ->orWhere('description', 'LIKE', "%{$search}%")
                        ->orWhere('min_advance_booking', 'LIKE', "%{$search}%")
                        ->orWhere('max_bookings_per_slot', 'LIKE', "%{$search}%");
                });
            })->get();

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
            'schedule.*.from_time'  => ['required_if:schedule.*.open,true', 'string', 'regex:/^\d{2}:\d{2}$/'],
            'schedule.*.to_time'    => ['required_if:schedule.*.open,true', 'string', 'regex:/^\d{2}:\d{2}$/', 'after:schedule.*.from'],
        ]);

        $schedules = $formData['schedule'];
        unset($formData['schedule']);

        $venue = $this->venueModel::create($formData);

        foreach ($schedules as $schedule) {
            $venue->schedules()->create($schedule);
        }
        $venue->load('schedules');

        return sendSuccess('Venue added successfully', compact('venue'));
    }
}
