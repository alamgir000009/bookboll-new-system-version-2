<?php
namespace App\Services;

use App\Models\Booking;
use App\Models\Listing;
use App\Models\Schedule;
use App\Models\SpecialDay;
use App\Models\SportsCategory;
use App\Models\SportsType;
use BookingStatus;
use Carbon\Carbon;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;

class ListingService
{
    public function __construct(protected Listing $listingModel)
    {}

    public function all($request)
    {
        $records_per_page = $request->records_per_page ?? 10;
        $search           = $request->search ?? null;

        $query = $this->listingModel::with([
            'venue',
            'pricingGroups',
            'addOns',
            'resources',
            'schedules' => function ($q) {
                $q->where('is_open', true)->orderBy('day');
            },
        ])
            ->when($search, function ($query, $search) {
                $query->where(function ($q) use ($search) {
                    $q->where('name', 'LIKE', "%{$search}%")
                        ->orWhere('venue_id', 'LIKE', "%{$search}%")
                        ->orWhere('status', 'LIKE', "%{$search}%")
                        ->orWhere('description', 'LIKE', "%{$search}%")
                        ->orWhere('base_price', 'LIKE', "%{$search}%")
                        ->orWhere('status', 'LIKE', "%{$search}%")
                        ->orWhereHas('sportsCategory', function ($query) use ($search) {
                            $query->where('name', 'LIKE', "%{$search}%")
                                ->orWhere("slug", 'LIKE', "%{$search}%");
                        });
                });
            })
            ->when($request->venue ?? null, function ($query, $venue) {
                $query->whereHas('venue', function ($q) use ($venue) {
                    $q->where('name', 'LIKE', "%{$venue}%");
                });
            });

        $listings = $query->paginate($records_per_page);

        return sendSuccess('Listings fetched successfully', compact('listings'));
    }

    public function create($request)
    {
        $formData = $this->parseAndValidateRequest($request);

        try {
            DB::beginTransaction();

            $listing = $this->listingModel::create($this->extractListingData($formData));

            if (! empty($formData['add_ons'])) {
                $listing->addOns()->sync($formData['add_ons']);
            }

            if (! empty($formData['resources'])) {
                $syncData = [];
                foreach ($formData['resources'] as $resource) {
                    $syncData[$resource['id']] = ['quantity' => $resource['quantity']];
                }
                $listing->resources()->sync($syncData);
            }

            if ($request->hasFile('images')) {
                $imageUrls = $this->uploadImages($request->file('images'));
                $listing->update(['images_urls' => $imageUrls]);
            }

            if (! empty($formData['schedule'])) {
                $this->createSchedules($listing, $formData['schedule']);
            }

            if (! empty($formData['pricing_groups'])) {
                foreach ($formData['pricing_groups'] as $groupData) {
                    $listing->pricingGroups()->create($groupData);
                }
            }

            $listing->validateScheduleConflicts($formData['schedule'] ?? []);
            $listing->validatePricingGroups($formData['pricing_groups'] ?? []);

            DB::commit();

            $listing->load([
                'venue',
                'schedules' => function ($q) {$q->where('is_open', true);},
                'pricingGroups',
                'addOns',
                'resources',
            ]);

            return sendSuccess('Listing created successfully', compact('listing'));

        } catch (\Exception $e) {
            DB::rollBack();
            return sendError('Failed to create listing: ' . $e->getMessage());
        }
    }

    public function update($request, $id)
    {
        $listing = $this->listingModel->findOrFail($id);

        $formData = $this->parseAndValidateRequest($request, true);

        try {

            DB::beginTransaction();

            $listingUpdateData = $this->extractListingData($formData);

            if ($request->hasFile('images')) {
                $this->deleteOldImages($listing->images_urls);
                $imageUrls                        = $this->uploadImages($request->file('images'));
                $listingUpdateData['images_urls'] = $imageUrls;
            } else {
                unset($listingUpdateData['images_urls']);
            }

            $listing->update($listingUpdateData);

            if (isset($formData['add_ons'])) {
                $listing->addOns()->sync($formData['add_ons']);
            }

            if (isset($formData['resources'])) {
                $syncData = [];
                foreach ($formData['resources'] as $resource) {
                    $syncData[$resource['id']] = ['quantity' => $resource['quantity']];
                }
                $listing->resources()->sync($syncData);
            }

            if (isset($formData['schedule'])) {
                $this->syncSchedules($listing, $formData['schedule']);
            }

            if (isset($formData['pricing_groups'])) {
                $listing->pricingGroups()->delete();
                foreach ($formData['pricing_groups'] as $groupData) {
                    $listing->pricingGroups()->create($groupData);
                }
            }

            $listing->validateScheduleConflicts($formData['schedule'] ?? $listing->schedules->toArray());
            $listing->validatePricingGroups($formData['pricing_groups'] ?? $listing->pricingGroups->toArray());

            DB::commit();

            $listing->load([
                'venue',
                'schedules' => function ($q) {$q->where('is_open', true);},
                'pricingGroups',
                'addOns',
                'resources',
            ]);

            return sendSuccess('Listing updated successfully', compact('listing'));

        } catch (\Exception $e) {
            DB::rollBack();
            return sendError('Failed to update listing: ' . $e->getMessage());
        }
    }

    public function show($id)
    {
        $listing = $this->listingModel->with(['schedules', 'addOns', 'resources', 'pricingGroups'])->findOrFail($id);
        return sendSuccess('Listing deleted successfully', compact('listing'));
    }

    public function delete($id)
    {
        $listing = $this->listingModel->findOrFail($id);

        $this->deleteOldImages($listing->images_urls);
        $listing->schedules()->delete();
        $listing->pricingGroups()->delete();
        $listing->delete();

        return sendSuccess('Listing deleted successfully');
    }

    public function isAvailable(Listing $listing, $day, $time)
    {
        $schedule = $listing->getScheduleForDay($day);

        if (! $schedule) {
            return false;
        }

        return $schedule->isTimeSlotAvailable($time);
    }

    protected function parseAndValidateRequest($request, bool $isUpdate = false): array
    {
        $rules = [
            'name'                               => 'required|string|max:255',
            'venue_id'                           => 'required|exists:venues,id',
            'sports_category_id'                 => 'required|exists:sports_categories,id',
            'status'                             => 'required|in:active,inactive',
            'description'                        => 'nullable|string',
            'base_price'                         => 'required|numeric|min:0',
            'max_bookings_per_slot'              => 'required|integer|min:1',
            'capacity'                           => 'required|integer|min:1',
            'slot_duration'                      => 'required|numeric|min:0.5',
            'loyalty_points'                     => 'nullable|integer|min:0',
            'calendar_color'                     => 'nullable|string|regex:/^#[a-fA-F0-9]{6}$/',
            'payment_options'                    => 'required|array',
            'payment_options.cash_on_arrival'    => 'nullable|boolean',
            'payment_options.deposit.amount'     => 'nullable|numeric|min:0',
            'payment_options.deposit.percentage' => 'nullable|numeric|between:0,100',
            'schedule'                           => $isUpdate ? 'sometimes|required|array|min:1' : 'required|array|min:7',
            'schedule.*.day'                     => 'required|in:monday,tuesday,wednesday,thursday,friday,saturday,sunday',
            'schedule.*.is_open'                 => 'required|boolean',
            'schedule.*.start_time'              => 'required_if:schedule.*.is_open,true|date_format:H:i',
            'schedule.*.end_time'                => 'required_if:schedule.*.start_time,*,schedule.*.is_open,true|after:schedule.*.start_time',
            'pricing_groups'                     => 'nullable|array',
            'pricing_groups.*.name'              => 'nullable|string|max:255',
            'pricing_groups.*.start_time'        => 'required|date_format:H:i',
            'pricing_groups.*.end_time'          => 'required|date_format:H:i|after:pricing_groups.*.start_time',
            'pricing_groups.*.price_per_hour'    => 'required|numeric|min:0',
            'pricing_groups.*.days'              => 'required|array',
            'pricing_groups.*.days.*'            => 'in:monday,tuesday,wednesday,thursday,friday,saturday,sunday',
            'images.*'                           => 'nullable|image|mimes:jpeg,png,jpg,gif|max:4096',
            'add_ons.*'                          => 'nullable|exists:add_ons,id',
            'resources'                          => 'nullable|array',
            'resources.*.id'                     => 'required|exists:resources,id',
            'resources.*.quantity'               => 'nullable|integer|min:1',
            'amenities'                          => 'array',
        ];

        if ($isUpdate) {
            $rules = $this->makeRulesOptional($rules);
        }

        $request->validate($rules);

        $parsedData = $request->all();

        $parsedData = $this->parseJsonFields($parsedData);

        $this->validateComplexData($parsedData, $isUpdate);

        return $parsedData;
    }

    protected function parseJsonFields(array $data): array
    {
        if (isset($data['payment_options']) && is_string($data['payment_options'])) {
            $decoded = json_decode($data['payment_options'], true);
            if (json_last_error() == JSON_ERROR_NONE) {
                $data['payment_options'] = $decoded;
            } else {
                throw ValidationException::withMessages([
                    'payment_options' => ['Invalid JSON format for payment options'],
                ]);
            }
        }

        if (isset($data['schedule']) && is_string($data['schedule'])) {
            $decoded = json_decode($data['schedule'], true);
            if (json_last_error() == JSON_ERROR_NONE) {
                $data['schedule'] = is_array($decoded) ? $decoded : [];
            } else {
                throw ValidationException::withMessages([
                    'schedule' => ['Invalid JSON format for schedule'],
                ]);
            }
        }

        if (isset($data['pricing_groups']) && is_string($data['pricing_groups'])) {
            $decoded = json_decode($data['pricing_groups'], true);
            if (json_last_error() == JSON_ERROR_NONE) {
                $data['pricing_groups'] = is_array($decoded) ? $decoded : [];
            } else {
                throw ValidationException::withMessages([
                    'pricing_groups' => ['Invalid JSON format for pricing groups'],
                ]);
            }
        }

        if (isset($data['add_ons']) && ! is_array($data['add_ons'])) {
            $data['add_ons'] = $data['add_ons'] ? [$data['add_ons']] : [];
        }

        if (isset($data['resources']) && is_array($data['resources'])) {
            $resources = [];
            foreach ($data['resources'] as $index => $resource) {
                if (is_array($resource)) {
                    $resources[] = $resource;
                } elseif (is_numeric($resource)) {
                    $resources[] = ['id' => $resource, 'quantity' => 1];
                }
            }
            $data['resources'] = $resources;
        }

        return $data;
    }

    protected function validateComplexData(array $data, bool $isUpdate = false): void
    {
        $rules = [
            'payment_options'                    => 'required|array',
            'payment_options.cash_on_arrival'    => 'nullable|boolean',
            'payment_options.deposit.amount'     => 'nullable|numeric|min:0',
            'payment_options.deposit.percentage' => 'nullable|numeric|between:0,100',

            'schedule'                           => $isUpdate ? 'sometimes|required|array|min:1' : 'required|array|min:7',
            'schedule.*.day'                     => 'required|in:monday,tuesday,wednesday,thursday,friday,saturday,sunday',
            'schedule.*.is_open'                 => 'required|boolean',
            'schedule.*.start_time'              => 'required_if:schedule.*.is_open,true|date_format:H:i',
            'schedule.*.end_time'                => 'required_if:schedule.*.start_time,*,schedule.*.is_open,true|after:schedule.*.start_time',

            'pricing_groups'                     => 'nullable|array',
            'pricing_groups.*.name'              => 'nullable|string|max:255',
            'pricing_groups.*.start_time'        => 'required|date_format:H:i',
            'pricing_groups.*.end_time'          => 'required|date_format:H:i|after:pricing_groups.*.start_time',
            'pricing_groups.*.price_per_hour'    => 'required|numeric|min:0',
            'pricing_groups.*.days'              => 'required|array',
            'pricing_groups.*.days.*'            => 'in:monday,tuesday,wednesday,thursday,friday,saturday,sunday',

            'add_ons'                            => 'nullable|array',
            'resources'                          => 'nullable|array',
            'resources.*.id'                     => 'required|exists:resources,id',
            'resources.*.quantity'               => 'nullable|integer|min:1',
        ];

        $validator = \Validator::make($data, $rules);

        if ($validator->fails()) {
            throw ValidationException::withMessages($validator->errors()->messages());
        }
    }

    protected function makeRulesOptional(array $rules): array
    {
        foreach ($rules as $field => $rule) {
            if (str_contains($rule, 'required') && ! str_contains($rule, 'sometimes')) {
                $rules[$field] = str_replace('required', 'sometimes|required', $rule);
            }
        }
        return $rules;
    }

    protected function extractListingData(array $data): array
    {
        return [
            'name'                  => $data['name'],
            'status'                => $data['status'],
            'venue_id'              => $data['venue_id'],
            'capacity'              => $data['capacity'],
            'base_price'            => $data['base_price'],
            'slot_duration'         => $data['slot_duration'],
            'amenities'             => $data['amenities'] ?? [],
            'images_urls'           => $data['images_urls'] ?? [],
            'sports_category_id'    => $data['sports_category_id'],
            'loyalty_points'        => $data['loyalty_points'] ?? 0,
            'description'           => $data['description'] ?? null,
            'payment_options'       => $data['payment_options'] ?? [],
            'max_bookings_per_slot' => $data['max_bookings_per_slot'] ?? 1,
            'calendar_color'        => $data['calendar_color'] ?? '#0000FF',
        ];
    }

    protected function createSchedules(Listing $listing, array $scheduleData): void
    {
        $listing->schedules()->delete();

        foreach ($scheduleData as $schedule) {
            if ($schedule['is_open']) {
                Schedule::create([
                    'venue_id'   => $listing->venue_id,
                    'listing_id' => $listing->id,
                    'day'        => $schedule['day'],
                    'is_open'    => true,
                    'start_time' => $schedule['start_time'],
                    'end_time'   => $schedule['end_time'],
                ]);
            }
        }
    }

    protected function syncSchedules(Listing $listing, array $scheduleData): void
    {
        $listing->schedules()->delete();
        $this->createSchedules($listing, $scheduleData);
    }

    protected function uploadImages(array $images): array
    {
        $uploadedUrls = [];

        foreach ($images as $image) {
            if ($image->isValid()) {
                $uploadedUrls[] = $image->store('listings', 'public');
            }
        }

        return $uploadedUrls;
    }

    protected function deleteOldImages(array $imageUrls): void
    {
        foreach ($imageUrls as $imageUrl) {
            $path = str_replace('/storage/', '', $imageUrl);
            if (Storage::disk('public')->exists($path)) {
                Storage::disk('public')->delete($path);
            }
        }
    }

    public function fetchListings($request)
    {
        $date             = $request->date ?? null;
        $time             = $request->time ?? null;
        $search           = $request->search ?? null;
        $venue_id         = $request->venue_id ?? null;
        $sports_type      = $request->category ?? null;
        $categories       = $request->categories ?? [];
        $records_per_page = $request->records_per_page ?? null;

        logger($request->all());

        $query = $this->listingModel::with(['venue', 'schedules', 'sportsCategory.sportsType'])
            ->when($search, function ($query, $search) {
                $query->where(function ($q) use ($search) {
                    $q->where('name', 'LIKE', "%{$search}%")
                        ->orWhere('description', 'LIKE', "%{$search}%")
                        ->orWhere('base_price', 'LIKE', "%{$search}%");
                });
            })
            ->when($venue_id, function ($query, $venue_id) {
                $query->whereRelation('venue', 'id', $venue_id);
            })
            ->when($sports_type, function ($query, $sports_type) {
                $query->whereHas('sportsCategory.sportsType', function ($q) use ($sports_type) {
                    $q->where('title', $sports_type);
                });
            })
            ->when($categories, function ($query, $categories) {
                $query->whereHas('sportsCategory', function ($q) use ($categories) {
                    $q->whereIn('name', $categories);
                });
            })
            ->when($date, function ($query, $date) {
                $dayName = Carbon::parse($date)->format('l');
                $query->whereHas('schedules', function ($q) use ($dayName) {
                    $q->where('day', $dayName)->where('is_open', true);
                });
            })
            ->when($time, function ($query, $time) use ($date) {
                $dayName = Carbon::parse($date)->format('l');
                $query->whereHas('schedules', function ($q) use ($dayName, $time) {
                    $q->where('day', $dayName)
                        ->where('is_open', true)
                        ->whereTime('start_time', '<=', $time)
                        ->whereTime('end_time', '>=', $time);
                });
            });

        if (! $records_per_page || $records_per_page === 'all' || (int) $records_per_page === 0) {
            $data = $query->get();

            $listings = new LengthAwarePaginator(
                $data,
                $data->count(),
                $data->count() ?: 1,
                1,
                ['path' => request()->url(), 'query' => request()->query()]
            );
        } else {
            $listings = $query->paginate($records_per_page);
        }

        $listings->getCollection()->transform(function ($listing) {
            return [
                'id'              => $listing->id,
                'name'            => $listing->name,
                'price'           => $listing->base_price,
                'description'     => $listing->description,
                'venue'           => $listing->venue?->name,
                'duration'        => $listing->slot_duration,
                'loyalty_points'  => $listing->loyalty_points,
                'payment_options' => $listing->payment_options,
                'map_address'     => $listing->venue?->maps_url,
                'images'          => $listing->images_urls ?? [],
                'sport'           => $listing->sportsCategory?->name,
                'rating'          => round($listing->average_rating, 1),
            ];
        });

        return sendSuccess("Listings fetched successfully", compact('listings'));
    }

    public function listing($id)
    {
        $listing = $this->listingModel::with(['venue'])->findOrFail($id);

        $date    = request()->get('date', now()->toDateString());
        $venueId = $listing->venue_id;

        $specialDay = SpecialDay::with('timeBlocks')->where('venue_id', $venueId)
            ->where(function ($q) use ($listing) {
                $q->whereNull('listing_id')->orWhere('listing_id', $listing->id);
            })
            ->where('date', $date)->active()->first();

        $isHoliday = $specialDay && $specialDay->type == 'holiday';

        $listing = [
            'is_holiday'      => $isHoliday,
            'id'              => $listing->id,
            'name'            => $listing->name,
            'venue_id'        => $listing->venue_id,
            'price'           => $listing->base_price,
            'description'     => $listing->description,
            'venue'           => $listing->venue?->name,
            'duration'        => $listing->slot_duration,
            'loyalty_points'  => $listing->loyalty_points,
            'calendar_color'  => $listing->calendar_color,
            'payment_options' => $listing->payment_options,
            'amenities'       => $listing->amenities ?? [],
            'special_day'     => $specialDay?->description,
            'map_address'     => $listing->venue?->maps_url,
            'images'          => $listing->images_urls ?? [],
            'sport'           => $listing?->sportsCategory->name,
            'rating'          => round($listing->average_rating, 1),
        ];

        return sendSuccess('Listing fetched successfully', compact('listing'));
    }

    public function fetchTimeSlots()
    {

        $listing_id = request()->get('listing_id');
        $date       = request()->get('date', now()->toDateString());
        $listing    = $this->listingModel::with(['venue', 'schedules', 'pricingGroups'])->findOrFail($listing_id);

        // Prevent viewing past date slots
        if (Carbon::parse($date)->lt(now()->startOfDay())) {
            return sendError('You cannot view time slots for a past date.');
        }

        $specialDay = SpecialDay::with('timeBlocks')->where('venue_id', $listing->venue_id)
            ->where(function ($q) use ($listing) {
                $q->whereNull('listing_id')->orWhere('listing_id', $listing->id);
            })
            ->where('date', $date)->active()->first();

        $isHoliday = $specialDay && $specialDay->type == 'holiday';

        // Fetch existing bookings for that date
        $bookings = Booking::where('listing_id', $listing->id)
            ->where('date', $date)
            ->whereNotIn('status', [BookingStatus::CANCELLED])
            ->get(['start_time', 'end_time']);

        $timeSlots = collect();

        foreach ($listing->schedules as $schedule) {
            $timeSlots = collect();

            $weekday = strtolower(Carbon::parse($date)->format('l')); // monday, tuesday, etc.

            // Find schedule for this specific weekday
            $schedule = $listing->schedules->firstWhere('day', $weekday);

            // If no listing schedule, try venue schedule
            if (! $schedule) {
                $schedule = $listing->venue?->schedules?->firstWhere('day', $weekday);
            }

            // If still no open schedule, return empty
            if (! $schedule || ! $schedule->is_open) {
                return sendSuccess('No available schedule for this day', ['timeSlots' => []]);
            }

            $start        = Carbon::parse($schedule->start_time);
            $end          = Carbon::parse($schedule->end_time);
            $slotDuration = $listing->slot_duration ?? 1; // in hours
            $slotMinutes  = $slotDuration * 60;

            // Generate slots once
            while ($start->lt($end)) {
                $slotStart = $start->format('H:i');
                $slotEnd   = $start->copy()->addMinutes($slotMinutes)->format('H:i');

                if (Carbon::parse($slotEnd)->gt($end)) {
                    break;
                }

                $bookedCount = $bookings->filter(function ($b) use ($slotStart, $slotEnd) {
                    return ! (
                        strtotime($b->end_time) <= strtotime($slotStart) ||
                        strtotime($b->start_time) >= strtotime($slotEnd)
                    );
                })->count();

                $availableCount = max($listing->max_bookings_per_slot - $bookedCount, 0);
                $isAvailable    = ! $isHoliday && $availableCount > 0;

                // Base price
                $price = $listing->base_price;

                // Override with Pricing Group price
                foreach ($listing->pricingGroups as $group) {
                    if (in_array($weekday, $group->days)) {
                        $groupStart = strtotime($group->start_time);
                        $groupEnd   = strtotime($group->end_time);
                        if (strtotime($slotStart) >= $groupStart && strtotime($slotEnd) <= $groupEnd) {
                            $price = $group->price_per_hour;
                        }
                    }
                }

                // Override with Special Day price
                if ($specialDay && $specialDay->timeBlocks->count()) {
                    foreach ($specialDay->timeBlocks as $block) {
                        $blockStart = strtotime($block->start_time);
                        $blockEnd   = strtotime($block->end_time);
                        if (strtotime($slotStart) >= $blockStart && strtotime($slotEnd) <= $blockEnd) {
                            $price = $block->price_per_hour ?? $price;
                        }
                    }
                }

                $timeSlots->push([
                    'is_holiday'      => $isHoliday,
                    'available'       => $isAvailable,
                    'booked'          => $bookedCount,
                    'available_count' => $availableCount,
                    'duration'        => "{$slotDuration}h",
                    'time' => "{$slotStart} - {$slotEnd}",
                    'price'    => round($price, 2),
                    'capacity' => $listing->max_bookings_per_slot,
                ]);

                $start->addMinutes($slotMinutes);
            }
        }

        $timeSlots = $timeSlots->values();

        return sendSuccess('Listing slots fetched successfully', compact('timeSlots'));
    }

    // public function listing($id)
    // {
    //     $listing = $this->listingModel::with(['venue', 'schedules'])->findOrFail($id);

    //     $date    = request('date') ?? now()->toDateString();
    //     $venueId = $listing->venue_id;

    //     if (Carbon::parse($date)->lt(now()->startOfDay())) {
    //         return sendError('You cannot view time slots for a past date.');
    //     }

    //     $specialDay = SpecialDay::with('timeBlocks')->where('venue_id', $venueId)
    //         ->where(function ($q) use ($listing) {
    //             $q->whereNull('listing_id')->orWhere('listing_id', $listing->id);
    //         })
    //         ->where('date', $date)->active()->first();

    //     $isHoliday = $specialDay && $specialDay->type == 'holiday';

    //     $bookings = Booking::where('listing_id', $listing->id)->where('date', $date)
    //         ->whereNotIn('status', [BookingStatus::CANCELLED])->get(['start_time', 'end_time']);

    //     $timeSlots = $listing->schedules->map(function ($schedule) use ($listing, $bookings, $isHoliday, $specialDay) {
    //         $from = date('H:i', strtotime($schedule->start_time));
    //         $to   = date('H:i', strtotime($schedule->end_time));

    //         $isBooked = $bookings->contains(function ($b) use ($from, $to) {
    //             return ! (
    //                 strtotime($b->end_time) <= strtotime($from) ||
    //                 strtotime($b->start_time) >= strtotime($to)
    //             );
    //         });

    //         $price = $listing->base_price;

    //         if ($specialDay && $specialDay->timeBlocks->count()) {
    //             foreach ($specialDay->timeBlocks as $block) {
    //                 $blockStart = strtotime($block->start_time);
    //                 $blockEnd   = strtotime($block->end_time);
    //                 if (strtotime($from) >= $blockStart && strtotime($to) <= $blockEnd) {
    //                     $price = $block->price_per_hour ?? $price;
    //                 }
    //             }
    //         }

    //         return [
    //             'time' => "{$from} - {$to}",
    //             'capacity'   => $listing->capacity,
    //             'booked'     => $isBooked,
    //             'available'  => $isHoliday ? false : ! $isBooked,
    //             'is_holiday' => $isHoliday,
    //             'duration'   => $schedule->duration ?? '1h',
    //             'price'      => $price,
    //         ];
    //     });

    //     $listingData = [
    //         'id'              => $listing->id,
    //         'venue_id'        => $listing->venue_id,
    //         'name'            => $listing->name,
    //         'duration'        => $listing->duration,
    //         'price'           => $listing->base_price,
    //         'description'     => $listing->description,
    //         'sport'           => $listing->sportsCategory?->name,
    //         'venue'           => $listing->venue?->name,
    //         'loyalty_points'  => $listing->loyalty_points,
    //         'calendar_color'  => $listing->calendar_color,
    //         'payment_options' => $listing->payment_options,
    //         'amenities'       => $listing->amenities ?? [],
    //         'images'          => $listing->images_urls ?? [],
    //         'map_address'     => $listing->venue?->maps_url,
    //         'rating'          => round($listing->average_rating, 1),
    //         'is_holiday'      => $isHoliday,
    //         'special_day'     => $specialDay?->description,
    //     ];

    //     return sendSuccess('Listing fetched successfully', [
    //         'listing'   => $listingData,
    //         'timeSlots' => $timeSlots,
    //     ]);
    // }

    public function fetchSuggestions($request)
    {
        $categoryType = $request->category ?? null;

        $sportsType = SportsType::withCount('listings')->where("title", $categoryType)->first();

        $categoryIds = SportsCategory::where("sports_type_id", $sportsType->id ?? null)->pluck('id')->toArray();

        $listings = $this->listingModel::with(['venue'])
            ->when($categoryIds, function ($query, $categoryIds) {
                $query->whereNotIn('sports_category_id', $categoryIds);
            })
            ->take(3)
            ->get()
            ->map(function ($listing) {
                return [
                    'id'              => $listing->id,
                    'name'            => $listing->name,
                    'price'           => $listing->base_price,
                    'description'     => $listing->description,
                    'venue'           => $listing->venue?->name,
                    'duration'        => $listing->slot_duration,
                    'loyaltyPoints'   => $listing->loyalty_points,
                    'payment_options' => $listing->payment_options,
                    'map_address'     => $listing->venue?->maps_url,
                    'images'          => $listing->images_urls ?? [],
                    'sport'           => $listing->sportsCategory?->name,
                    'category'        => $listing->sportsCategory?->name,
                    'icon'            => $listing->sportsCategory?->sportsType?->icon,
                    'rating'          => round($listing->average_rating, 1),
                ];
            });

        return sendSuccess("Listings fetched successfully", compact('listings', 'sportsType'));
    }

    public function fetchAddons($listing_id)
    {
        $listing = $this->listingModel->with('addons')->findOrFail($listing_id);

        $addons = $listing->addons->map(function ($addon) {
            return [
                'recommended' => true,
                'id'          => $addon->id,
                'name'        => $addon->name,
                'price'       => $addon->price ?? 0,
                'description' => $addon->description,
                'icon'        => $addon->icon ?? '🧽',
                'subtitle'    => $addon->subtitle ?? '',
                //  '+1 hour extension - More time to enjoy!'
            ];
        });

        return sendSuccess('Addons fetched successfully', compact('addons'));
    }
}
