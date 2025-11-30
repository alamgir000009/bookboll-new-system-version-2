<?php
namespace App\Services;

use App\Models\Booking;
use App\Models\Listing;
use App\Models\Schedule;
use App\Models\Setting;
use App\Models\SlotLock;
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
            ->orderBy('sort_order', 'ASC')
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
            'allow_multiple_bookings'            => 'nullable|boolean',
            'max_multiple_bookings'              => 'nullable|integer|min:1|required_if:allow_multiple_bookings,true',
            'capacity'                           => 'required|integer|min:1',
            'slot_duration'                      => 'required|numeric|min:0',
            'loyalty_points'                     => 'nullable|integer|min:0',
            'calendar_color'                     => 'nullable|string|regex:/^#[a-fA-F0-9]{6}$/',
            'online_payment'                     => 'required|boolean',
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
            'name'                    => $data['name'],
            'status'                  => $data['status'],
            'venue_id'                => $data['venue_id'],
            'capacity'                => $data['capacity'],
            'base_price'              => $data['base_price'],
            'slot_duration'           => $data['slot_duration'],
            'online_payment'          => $data['online_payment'],
            'amenities'               => $data['amenities'] ?? [],
            'images_urls'             => $data['images_urls'] ?? [],
            'sports_category_id'      => $data['sports_category_id'],
            'loyalty_points'          => $data['loyalty_points'] ?? 0,
            'description'             => $data['description'] ?? null,
            'payment_options'         => $data['payment_options'] ?? [],
            'max_bookings_per_slot'   => $data['max_bookings_per_slot'] ?? 1,
            'calendar_color'          => $data['calendar_color'] ?? '#0000FF',
            'max_multiple_bookings'   => $data['max_multiple_bookings'] ?? null,
            'allow_multiple_bookings' => $data['allow_multiple_bookings'] ? 1 : 0,
        ];
    }

    protected function createSchedules(Listing $listing, array $scheduleData): void
    {
        $listing->schedules()->delete();

        foreach ($scheduleData as $schedule) {
            if ($schedule['is_open']) {
                Schedule::create([
                    'is_open'    => true,
                    'listing_id' => $listing->id,
                    'day'        => $schedule['day'],
                    'venue_id'   => $listing->venue_id,
                    'end_time'   => $schedule['end_time'],
                    'start_time' => $schedule['start_time'],
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

        $query = $this->listingModel::with(['venue', 'schedules', 'sportsCategory.sportsType', 'addOns'])
            ->active()
            ->whereRelation('venue', 'status', 'active')
            ->orderBy('sort_order', 'ASC')
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
            $data     = $query->get();
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
                'add_ons'         => $listing->addOns,
                'price'           => $listing->base_price,
                'description'     => $listing->description,
                'venue'           => $listing->venue?->name,
                'slot_duration'   => $listing->slot_duration,
                'loyalty_points'  => $listing->loyalty_points,
                'payment_options' => $listing->payment_options,
                'map_address'     => $listing->venue?->maps_url,
                'images'          => $listing->images_urls ?? [],
                'sport'           => $listing->sportsCategory?->name,
                'rating'          => round($listing->average_rating, 1),
                'duration'        => getFormattedDuration($listing->slot_duration * 60),
            ];
        });

        return sendSuccess("Listings fetched successfully", compact('listings'));
    }

    public function listing($id)
    {
        $date = request('date', now()->toDateString());

        $listing = $this->listingModel::with([
            'venue:id,name,maps_url',
            'sportsCategory:id,name',
        ])->findOrFail($id);

        $specialDay = SpecialDay::with('timeBlocks')
            ->where('venue_id', $listing->venue_id)
            ->where(function ($q) use ($listing) {
                $q->whereNull('listing_id')->orWhere('listing_id', $listing->id);
            })
            ->whereDate('date', $date)->active()->first();

        $isHoliday = $specialDay?->type === 'holiday';

        $listing = [
            'is_holiday'              => $isHoliday,
            'id'                      => $listing->id,
            'name'                    => $listing->name,
            'venue_id'                => $listing->venue_id,
            'price'                   => $listing->base_price,
            'description'             => $listing->description,
            'venue'                   => $listing->venue?->name,
            'addons'                  => $listing->addons ?? [],
            'duration'                => $listing->slot_duration,
            'loyalty_points'          => $listing->loyalty_points,
            'calendar_color'          => $listing->calendar_color,
            'online_payment'          => $listing->online_payment,
            'payment_options'         => $listing->payment_options,
            'amenities'               => $listing->amenities ?? [],
            'special_day'             => $specialDay?->description,
            'map_address'             => $listing->venue?->maps_url,
            'images'                  => $listing->images_urls ?? [],
            'sport'                   => $listing->sportsCategory?->name,
            'max_multiple_bookings'   => $listing->max_multiple_bookings,
            'allow_multiple_bookings' => $listing->allow_multiple_bookings,
            'rating'                  => round($listing->average_rating, 1),
        ];

        return sendSuccess('Listing fetched successfully', compact('listing'));
    }

    public function fetchTimeSlots($request)
    {
        $request->validate([
            "listing_id" => "required|numeric|exists:listings,id",
            "date"       => "required|date|after_or_equal:today",
        ], [
            "date.after_or_equal" => "You cannot view time slots for a past date.",
        ]);

        $user      = auth()->user();
        $ipAddress = $request->ip();
        $listingId = $request->listing_id;
        $date      = Carbon::parse(request('date', now()->toDateString()));
        $sessionId = $user ? 'user_' . $user->id : 'guest_' . md5($ipAddress . $request->userAgent());

        $listing = $this->listingModel::with([
            'venue',
            'schedules',
            'pricingGroups',
            'venue.schedules',
        ])->findOrFail($listingId);

        if (! $listing->venue || $listing->venue->status !== 'active') {
            return sendSuccess('No available schedule for this day');
        }

        $weekday     = strtolower($date->format('l'));
        $slotMinutes = ($listing->slot_duration ?? 1) * 60;

        $activeLock = SlotLock::where('listing_id', $listingId)
            ->where('date', $date->toDateString())
            ->where('locked_until', '>', now())
            ->where('session_id', '!=', $sessionId)
            ->exists();

        if ($activeLock) {
            return sendError('This date is currently locked by another user. Please try again later.');
        }

        // ---- Get ALL schedules for the day ----
        $venueSchedules = $listing->venue?->schedules
            ->where('day', $weekday)
            ->where('is_open', true)
            ->sortBy('start_time')
            ->values();

        $listingSchedules = $listing->schedules
            ->where('day', $weekday)
            ->where('is_open', true)
            ->sortBy('start_time')
            ->values();

        // Venue must have schedules for the day (venue must be open)
        if ($venueSchedules->isEmpty()) {
            return sendSuccess('No available schedule for this day', ['timeSlots' => []]);
        }

        // ---- Get Special Day ----
        $specialDay = SpecialDay::with('timeBlocks')
            ->where('venue_id', $listing->venue_id)
            ->where(function ($q) use ($listing) {
                $q->whereNull('listing_id')->orWhere('listing_id', $listing->id);
            })
            ->whereDate('date', $date)
            ->active()
            ->first();

        $isHoliday = $specialDay?->type === 'holiday';

        // If it's a holiday, return early with no slots
        if ($isHoliday) {
            return sendSuccess('No available schedule for this day (Holiday)', ['timeSlots' => []]);
        }

        // If special day exists and has time blocks, use those instead of normal schedule
        if ($specialDay && $specialDay->timeBlocks->isNotEmpty()) {
            $availableBlocks = $specialDay->timeBlocks
                ->sortBy('start_time')
                ->map(function ($block) {
                    return [
                        'start_time' => $block->start_time,
                        'end_time'   => $block->end_time,
                    ];
                })
                ->values();
        } else {
            // Find overlapping periods between venue and listing schedules
            $availableBlocks = $this->findOverlappingScheduleBlocks(
                $listingSchedules,
                $venueSchedules
            );
        }

        if ($availableBlocks->isEmpty()) {
            return sendSuccess('No available schedule for this day', ['timeSlots' => []]);
        }

        // ---- Get Bookings ----
        $bookings = Booking::where('listing_id', $listing->id)
            ->where('date', $date)
            ->whereNotIn('status', [BookingStatus::CANCELLED, BookingStatus::COMPLETED])
            ->with('resourcesAllocated')
            ->get(['id', 'start_time', 'end_time', 'status']);

        // ---- Generate time slots for all available blocks ----
        $timeSlots = collect();
        foreach ($availableBlocks as $block) {
            $slots = $this->generateTimeSlots(
                $block['start_time'],
                $block['end_time'],
                $slotMinutes,
                $listing,
                $weekday,
                $specialDay,
                $bookings,
                $isHoliday
            );
            $timeSlots = $timeSlots->merge($slots);
        }

        // Sort time slots by start time
        $timeSlots = $timeSlots->sortBy('start_time')->values();

        $lockDuration = intval(Setting::where('key', 'default_bookings_slot_lock_time')->value('value')) ?? 5;

        SlotLock::updateOrCreate(
            [
                'session_id' => $sessionId,
                'ip_address' => $ipAddress,
                'listing_id' => $listing->id,
                'date'       => $date->toDateString(),
            ],
            [
                'locked_until' => now()->addMinutes($lockDuration),
            ]
        );

        return sendSuccess('Listing slots fetched successfully', compact('timeSlots'));
    }

    protected function findOverlappingScheduleBlocks($listingSchedules, $venueSchedules)
    {
        $overlappingBlocks = collect();

        // If listing has no specific schedules, use venue schedules as fallback
        if ($listingSchedules->isEmpty()) {
            return $venueSchedules->map(function ($schedule) {
                return [
                    'start_time' => $schedule->start_time,
                    'end_time'   => $schedule->end_time,
                ];
            });
        }

        // Venue schedules should already be checked before calling this method
        // Find overlapping periods between listing and venue schedules
        foreach ($listingSchedules as $listingSchedule) {
            $listingStart = Carbon::parse($listingSchedule->start_time);
            $listingEnd   = Carbon::parse($listingSchedule->end_time);

            foreach ($venueSchedules as $venueSchedule) {
                $venueStart = Carbon::parse($venueSchedule->start_time);
                $venueEnd   = Carbon::parse($venueSchedule->end_time);

                // Check if schedules overlap
                if ($listingStart->lt($venueEnd) && $listingEnd->gt($venueStart)) {
                    // Calculate overlap
                    $overlapStart = $listingStart->gt($venueStart) ? $listingStart : $venueStart;
                    $overlapEnd   = $listingEnd->lt($venueEnd) ? $listingEnd : $venueEnd;

                    // Only add if there's a valid overlap (start < end)
                    if ($overlapStart->lt($overlapEnd)) {
                        $overlappingBlocks->push([
                            'start_time' => $overlapStart->format('H:i'),
                            'end_time'   => $overlapEnd->format('H:i'),
                        ]);
                    }
                }
            }
        }

        // Merge overlapping blocks if they are adjacent or overlap
        return $this->mergeAdjacentBlocks($overlappingBlocks->sortBy('start_time')->values());
    }

    protected function mergeAdjacentBlocks($blocks)
    {
        if ($blocks->isEmpty()) {
            return $blocks;
        }

        $merged  = collect();
        $current = $blocks->first();

        foreach ($blocks->skip(1) as $block) {
            $currentEnd = Carbon::parse($current['end_time']);
            $blockStart = Carbon::parse($block['start_time']);

            // If blocks are adjacent or overlapping, merge them
            if ($blockStart->lte($currentEnd)) {
                $blockEnd            = Carbon::parse($block['end_time']);
                $current['end_time'] = $currentEnd->gt($blockEnd) ? $current['end_time'] : $block['end_time'];
            } else {
                // No overlap, save current and start new
                $merged->push($current);
                $current = $block;
            }
        }

        $merged->push($current);
        return $merged;
    }

    protected function generateTimeSlots($startTime, $endTime, $slotMinutes, $listing, $weekday, $specialDay, $bookings, $isHoliday)
    {
        $start = Carbon::parse($startTime);
        $end   = Carbon::parse($endTime);
        $slots = collect();

        $listingResources = $listing->resources()->get(['resources.id', 'resources.total_quantity', 'listing_resource.quantity as required_qty']);

        while ($start->lt($end)) {
            $slotStart = $start->format('H:i');
            $slotEnd   = $start->copy()->addMinutes($slotMinutes)->format('H:i');

            if (Carbon::parse($slotEnd)->gt($end)) {
                break;
            }

            $activeBookings = $bookings->filter(fn($b) => $b->status !== BookingStatus::CANCELLED && $b->status !== BookingStatus::COMPLETED);

            $slotBookings = $activeBookings->filter(fn($b) => ! (
                strtotime($b->end_time) <= strtotime($slotStart) ||
                strtotime($b->start_time) >= strtotime($slotEnd)
            ));

            $bookedCount    = $slotBookings->count();
            $availableCount = max($listing->max_bookings_per_slot - $bookedCount, 0);

            $allResourcesAvailable = true;
            $availableQty          = null;

            foreach ($listingResources as $res) {

                $bookedQty = $slotBookings->flatMap(fn($b) => $b->resourcesAllocated)->where('id', $res->id)->sum('pivot.quantity');

                $availableQty = $res->total_quantity - $bookedQty;

                if ($availableQty < $res->required_qty) {
                    $allResourcesAvailable = false;
                    break;
                }
            }

            $isAvailable = ! $isHoliday && $availableCount > 0 && $allResourcesAvailable;

            $price = determineSlotPrice($listing, $weekday, $slotStart, $slotEnd, $specialDay);

            $duration = getFormattedDuration($slotMinutes);

            $slots->push([
                'start_time'          => $slotStart,
                'end_time'            => $slotEnd,
                'is_holiday'          => $isHoliday,
                'available'           => $isAvailable,
                'booked'              => $bookedCount,
                'available_resources' => $availableQty,
                'available_count'     => $availableCount,
                'duration'            => $duration,
                'time'                => "{$slotStart} - {$slotEnd}",
                'price'                 => round($price, 2),
                'capacity'              => $listing->capacity,
                'max_bookings_per_slot' => $listing->max_bookings_per_slot,
            ]);

            $start->addMinutes($slotMinutes);
        }

        return $slots->values();
    }

    public function fetchSuggestions($request)
    {
        $categoryType = $request->category ?? null;

        $sportsType = SportsType::withCount('listings')->where("title", $categoryType)->first();

        $categoryIds = SportsCategory::where("sports_type_id", $sportsType->id ?? null)->pluck('id')->toArray();

        $listings = $this->listingModel::with(['venue', 'sportsCategory'])
            ->when($categoryIds, function ($query, $categoryIds) {
                $query->whereNotIn('sports_category_id', $categoryIds);
            })
            ->whereRelation('venue', 'status', 'active')
            ->take(3)
            ->get()
            ->map(function ($listing) {
                return [
                    'id'              => $listing->id,
                    'name'            => $listing->name,
                    'price'           => $listing->base_price,
                    'description'     => $listing->description,
                    'venue'           => $listing->venue?->name,
                    'loyaltyPoints'   => $listing->loyalty_points,
                    'payment_options' => $listing->payment_options,
                    'map_address'     => $listing->venue?->maps_url,
                    'images'          => $listing->images_urls ?? [],
                    'sport'           => $listing->sportsCategory?->name,
                    'category'        => $listing->sportsCategory?->name,
                    'icon'            => $listing->sportsCategory?->sportsType?->icon,
                    'rating'          => round($listing->average_rating, 1),
                    'duration'        => getFormattedDuration($listing->slot_duration * 60),
                ];
            });

        return sendSuccess("Listings fetched successfully", compact('listings', 'sportsType'));
    }

    public function fetchAddons($listing_id)
    {
        $listing = $this->listingModel->with(['addons' => function ($query) {
            $query->orderBy('sort_order', 'ASC');
        }])->findOrFail($listing_id);

        $addons = $listing->addons->map(function ($addon) {
            return [
                'recommended'     => true,
                'id'              => $addon->id,
                'name'            => $addon->name,
                'image'           => $addon->image,
                'price'           => $addon->price ?? 0,
                'description'     => $addon->description,
                'hours_extension' => $addon->hours_extension,
            ];
        });

        return sendSuccess('Addons fetched successfully', compact('addons'));
    }

    public function updateSortOrder($request)
    {
        $request->validate([
            'updates'              => 'required|array|min:1',
            'updates.*.id'         => 'required|integer|exists:listings,id',
            'updates.*.sort_order' => 'required|integer|min:1',
        ]);

        $updates    = $request->input('updates');
        $listingIds = collect($updates)->pluck('id')->toArray();

        $listings = $this->listingModel::whereIn('id', $listingIds)->get(['id', 'venue_id']);

        if ($listings->isEmpty()) {
            return sendError('No listings found.');
        }

        $venueId = $listings->first()->venue_id;
        if ($listings->pluck('venue_id')->unique()->count() > 1) {
            return sendError('Listings must belong to the same venue.');
        }

        $sortOrders = collect($updates)->pluck('sort_order')->sort()->values()->toArray();
        if (count(array_unique($sortOrders)) !== count($sortOrders) || $sortOrders[0] !== 1 || end($sortOrders) !== count($sortOrders)) {
            return sendError('Sort orders must be unique, sequential integers starting from 1.');
        }

        DB::beginTransaction();

        try {
            foreach ($updates as $update) {
                $this->listingModel::where('id', $update['id'])
                    ->where('venue_id', $venueId)
                    ->update(['sort_order' => $update['sort_order']]);
            }

            DB::commit();

            return sendSuccess('Sort order updated successfully.', [
                'data' => ['updated_listings' => $listingIds],
            ]);
        } catch (\Exception $e) {
            DB::rollBack();
            \Illuminate\Support\Facades\Log::error('Failed to update sort order: ' . $e->getMessage(), ["Exception" => $e]);
            return sendError('Failed to update sort order.');
        }
    }
}
