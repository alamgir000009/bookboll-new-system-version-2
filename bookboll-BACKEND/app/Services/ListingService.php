<?php
namespace App\Services;

use App\Models\Listing;
use App\Models\Schedule;
use App\Models\SportsCategory;
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
                        ->orWhere('sports_type', 'LIKE', "%{$search}%")
                        ->orWhere('status', 'LIKE', "%{$search}%")
                        ->orWhere('description', 'LIKE', "%{$search}%")
                        ->orWhere('base_price', 'LIKE', "%{$search}%")
                        ->orWhere('status', 'LIKE', "%{$search}%");
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
                $listing->update(['image_urls' => $imageUrls]);
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

            $listing->update($this->extractListingData($formData));

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

            if ($request->hasFile('images')) {
                $this->deleteOldImages($listing->image_urls);
                $imageUrls = $this->uploadImages($request->file('images'));
                $listing->update(['image_urls' => $imageUrls]);
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
        $listing = $this->listingModel->with(['schedules'])->findOrFail($id);
        return sendSuccess('Listing deleted successfully', compact('listing'));
    }

    public function delete($id)
    {
        $listing = $this->listingModel->findOrFail($id);

        $this->deleteOldImages($listing->image_urls);
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
            'name'                  => 'required|string|max:255',
            'venue_id'              => 'required|exists:venues,id',
            'sports_type'           => 'required',
            'status'                => 'required|in:active,inactive',
            'description'           => 'nullable|string',
            'base_price'            => 'required|numeric|min:0',
            'max_bookings_per_slot' => 'required|integer|min:1',
            'capacity'              => 'required|integer|min:1',
            'slot_duration'         => 'required|integer|min:1',
            'loyalty_points'        => 'nullable|integer|min:0',
            'calendar_color'        => 'nullable|string|regex:/^#[a-fA-F0-9]{6}$/',
            'images.*'              => 'nullable|image|mimes:jpeg,png,jpg,gif|max:4096',
            'add_ons.*'             => 'nullable|exists:add_ons,id',
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
            if (json_last_error() === JSON_ERROR_NONE) {
                $data['payment_options'] = $decoded;
            } else {
                throw ValidationException::withMessages([
                    'payment_options' => ['Invalid JSON format for payment options'],
                ]);
            }
        }

        if (isset($data['schedule']) && is_string($data['schedule'])) {
            $decoded = json_decode($data['schedule'], true);
            if (json_last_error() === JSON_ERROR_NONE) {
                $data['schedule'] = is_array($decoded) ? $decoded : [];
            } else {
                throw ValidationException::withMessages([
                    'schedule' => ['Invalid JSON format for schedule'],
                ]);
            }
        }

        if (isset($data['pricing_groups']) && is_string($data['pricing_groups'])) {
            $decoded = json_decode($data['pricing_groups'], true);
            if (json_last_error() === JSON_ERROR_NONE) {
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
            'schedule.*.from_time'               => 'required_if:schedule.*.is_open,true|date_format:H:i',
            'schedule.*.to_time'                 => 'required_if:schedule.*.from_time,*,schedule.*.is_open,true|after:schedule.*.from_time',

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
            'venue_id'              => $data['venue_id'],
            'sports_type'           => $data['sports_type'],
            'status'                => $data['status'],
            'description'           => $data['description'] ?? null,
            'base_price'            => $data['base_price'],
            'max_bookings_per_slot' => $data['max_bookings_per_slot'] ?? 1,
            'capacity'              => $data['capacity'],
            'slot_duration'         => $data['slot_duration'],
            'loyalty_points'        => $data['loyalty_points'] ?? 0,
            'calendar_color'        => $data['calendar_color'] ?? '#0000FF',
            'payment_options'       => $data['payment_options'] ?? [],
            'image_urls'            => $data['image_urls'] ?? [],
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
                    'from_time'  => $schedule['from_time'],
                    'to_time'    => $schedule['to_time'],
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
        $records_per_page = $request->records_per_page ?? 20;
        $search           = $request->search ?? null;
        $venue_id         = $request->venueId ?? null;
        $date             = $request->date ?? null;
        $time             = $request->time ?? null;

        $query = $this->listingModel::with(['venue', 'pricingGroups', 'addOns', 'resources'])
            ->when($search, function ($query, $search) {
                $query->where(function ($q) use ($search) {
                    $q->where('name', 'LIKE', "%{$search}%")
                        ->orWhere('sports_type', 'LIKE', "%{$search}%")
                        ->orWhere('description', 'LIKE', "%{$search}%")
                        ->orWhere('base_price', 'LIKE', "%{$search}%");
                });
            })
            ->when($venue_id ?? null, function ($query, $venue_id) {
                $query->whereRelation('venue', 'id', $venue_id);
            });

        $listings = $query->paginate($records_per_page);

        $listings->getCollection()->transform(function ($listing) {

            return [
                'id'              => $listing->id,
                'name'            => $listing->name,
                'venue'           => $listing->venue?->name,
                'sport'           => $listing->sports_type,
                'price'           => $listing->base_price,
                'description'     => $listing->description,
                'rating'          => round($listing->average_rating, 1),
                'images'          => $listing->image_urls ?? [],
                'duration'        => $listing->duration,
                'payment_options' => $listing->payment_options,
                'loyalty_points'  => $listing->loyalty_points,
                'map_address'     => $listing->venue->maps_url,
            ];
        });

        return sendSuccess("Listings fetched successfully", compact('listings'));
    }

    public function fetchSuggestions($request)
    {
        $category = $request->category ?? null;

        $sportCategory = SportsCategory::where("type", $category)->first();
        $listings      = $this->listingModel::with(['venue', 'pricingGroups', 'addOns', 'resources'])
            ->when($sportCategory->name ?? null, function ($query, $type) {
                $query->where('sports_type', 'NOT LIKE', $type);
            })->take(4)->get()->map(function ($listing) {

            return [
                'id'              => $listing->id,
                'name'            => $listing->name,
                'venue'           => $listing->venue?->name,
                'sport'           => $listing->sports_type,
                'price'           => $listing->base_price,
                'description'     => $listing->description,
                'rating'          => round($listing->average_rating, 1),
                'images'          => $listing->image_urls ?? [],
                'duration'        => $listing->duration,
                'payment_options' => $listing->payment_options,
                'loyaltyPoints'   => $listing->loyalty_points,
                'map_address'     => $listing->venue->maps_url,
            ];
        });

        return sendSuccess("Listings fetched successfully", compact('listings'));
    }
}
