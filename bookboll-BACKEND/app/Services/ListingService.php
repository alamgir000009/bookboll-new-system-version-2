<?php
namespace App\Services;

use App\Models\Asset;
use App\Models\Booking;
use App\Models\Listing;
use App\Models\Resource;
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
            'addOns',
            'assets',
            'resources',
            'pricingGroups',
            'venue'     => function ($q) {
                $q->orderBy('sort_order', 'ASC');
            },
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
                $this->uploadImagesAsAssets($listing, $request->file('images'));
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
                'addOns',
                'assets',
                'resources',
                'pricingGroups',
                'schedules' => function ($q) {$q->where('is_open', true);},
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
                // Delete old assets
                $this->deleteListingAssets($listing);
                // Upload new images as assets
                $this->uploadImagesAsAssets($listing, $request->file('images'));
            }

            // Remove images_urls from update data as we're using Asset model now
            unset($listingUpdateData['images_urls']);

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
        $listing = $this->listingModel->with(['schedules', 'addOns', 'resources', 'pricingGroups', 'assets'])->findOrFail($id);
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
            'max_multiple_bookings'              => 'nullable|integer|min:1|required_if:allow_multiple_bookings,true|lte:max_bookings_per_slot',
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
            'schedule.*.end_time'                => 'required_if:schedule.*.is_open,true|date_format:H:i',
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

        if (isset($parsedData['schedule'])) {
            $this->validateScheduleTimes($parsedData['schedule']);
        }

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
            'schedule.*.end_time'                => 'required_if:schedule.*.is_open,true|date_format:H:i',

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

        $validator->after(function ($validator) use ($data) {
            if (! empty($data['resources']) && is_array($data['resources'])) {
                foreach ($data['resources'] as $index => $resourceData) {
                    if (! isset($resourceData['id']) || ! isset($resourceData['quantity'])) {
                        continue;
                    }

                    $resource = Resource::find($resourceData['id']);

                    if ($resource && $resourceData['quantity'] > $resource->total_quantity) {
                        $validator->errors()->add(
                            "resources.$index.quantity",
                            "The selected resource requirements quantity ({$resourceData['quantity']}) exceeds the resource total stock ({$resource->total_quantity})."
                        );
                    }
                }
            }
        });

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
        // Load existing schedules
        $existingSchedules = $listing->schedules()->get();

        // Normalize new schedules for comparison (only open schedules)
        $normalizedNewSchedules = collect($scheduleData)
            ->filter(function ($schedule) {
                return $schedule['is_open'] ?? false;
            })
            ->map(function ($schedule) {
                return [
                    'day'        => $schedule['day'],
                    'is_open'    => true,
                    'start_time' => $schedule['start_time'] ?? null,
                    'end_time'   => $schedule['end_time'] ?? null,
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
            $listing->schedules()->whereIn('id', $idsToDelete)->delete();
        }

        // Create new schedules
        foreach ($schedulesToCreate as $schedule) {
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

    protected function uploadImagesAsAssets(Listing $listing, array $images): void
    {
        $sortOrder = Asset::where('listing_id', $listing->id)->max('sort_order') ?? 0;

        foreach ($images as $image) {
            if ($image->isValid()) {
                $uploadedPath = $image->store('listings', 'public');

                Asset::create([
                    'listing_id' => $listing->id,
                    'path'       => $uploadedPath,
                    'type'       => 'image',
                    'sort_order' => ++$sortOrder,
                ]);
            }
        }
    }

    protected function deleteListingAssets(Listing $listing): void
    {
        $assets = Asset::where('listing_id', $listing->id)->get();

        foreach ($assets as $asset) {
            // Delete file from storage
            if (Storage::disk('public')->exists($asset->path)) {
                Storage::disk('public')->delete($asset->path);
            }
            // Delete asset record
            $asset->delete();
        }
    }

    public function uploadImage($request, $listingId)
    {
        $request->validate([
            'image' => 'required|image|mimes:jpeg,jpg,png,gif,webp|max:5120', // 5MB max
        ]);

        try {
            $listing = $this->listingModel->findOrFail($listingId);

            // Upload the new image
            if ($request->hasFile('image')) {
                $uploadedPath = $request->file('image')->store('listings', 'public');

                // Get the current max sort_order to append at the end
                $maxSortOrder = Asset::where('listing_id', $listingId)->max('sort_order') ?? 0;

                // Create asset record
                $asset = Asset::create([
                    'listing_id' => $listingId,
                    'path'       => $uploadedPath,
                    'type'       => 'image',
                    'sort_order' => $maxSortOrder + 1,
                ]);

                // Get the full URL for the uploaded image
                $uploadedUrl = $asset->url;

                // Get total images count
                $totalImages = Asset::where('listing_id', $listingId)->count();

                return sendSuccess('Image uploaded successfully', [
                    'listing_id'   => $listing->id,
                    'image_url'    => $uploadedUrl,
                    'image_path'   => $uploadedPath,
                    'asset_id'     => $asset->id,
                    'total_images' => $totalImages,
                ]);
            }

            return sendError('No image file provided', [], 400);

        } catch (\Illuminate\Database\Eloquent\ModelNotFoundException $e) {
            return sendError('Listing not found', [], 404);
        } catch (\Exception $e) {
            return sendError('Failed to upload image: ' . $e->getMessage(), [], 500);
        }
    }

    public function deleteImage($request, $listingId)
    {
        $request->validate([
            'url' => 'required|string',
        ]);

        try {
            $listing = $this->listingModel->findOrFail($listingId);

            // Extract the file path from the URL (passed as query parameter)
            $imageUrl = $request->query('url');

            // Handle both full URL and relative path
            if (strpos($imageUrl, '/storage/') !== false) {
                // Extract path from URL: /storage/listings/filename.jpg -> listings/filename.jpg
                $urlPath = parse_url($imageUrl, PHP_URL_PATH);
                $path    = str_replace('/storage/', '', $urlPath);
            } elseif (strpos($imageUrl, 'storage/') === 0) {
                // Handle storage/listings/filename.jpg
                $path = str_replace('storage/', '', $imageUrl);
            } else {
                // If it's already a relative path like "listings/filename.jpg"
                $path = $imageUrl;
            }

            // Remove leading slash if present
            $path = ltrim($path, '/');

            // Find asset by path
            $asset = Asset::where('listing_id', $listingId)
                ->where('path', $path)
                ->first();

            // If not found by exact path, try to find by filename
            if (! $asset) {
                $filename = basename($path);
                $asset    = Asset::where('listing_id', $listingId)
                    ->whereRaw("SUBSTRING_INDEX(path, '/', -1) = ?", [$filename])
                    ->first();
            }

            if (! $asset) {
                return sendError('Image not found in listing assets', [], 404);
            }

            // Check if file exists before deleting
            if (Storage::disk('public')->exists($asset->path)) {
                // Delete the file from storage
                Storage::disk('public')->delete($asset->path);
            }

            // Delete the asset record
            $asset->delete();

            // Get remaining images count
            $remainingImages = Asset::where('listing_id', $listingId)->count();

            return sendSuccess('Image deleted successfully', [
                'listing_id'       => $listing->id,
                'remaining_images' => $remainingImages,
            ]);

        } catch (\Illuminate\Database\Eloquent\ModelNotFoundException $e) {
            return sendError('Listing not found', [], 404);
        } catch (\Exception $e) {
            return sendError('Failed to delete image: ' . $e->getMessage(), [], 500);
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

        $query = $this->listingModel::with(['venue', 'schedules', 'sportsCategory.sportsType', 'addOns', 'assets'])
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
                'id'                      => $listing->id,
                'name'                    => $listing->name,
                'add_ons'                 => $listing->addOns,
                'price'                   => $listing->base_price,
                'description'             => $listing->description,
                'venue'                   => $listing->venue?->name,
                'slot_duration'           => $listing->slot_duration,
                'loyalty_points'          => $listing->loyalty_points,
                'payment_options'         => $listing->payment_options,
                'map_address'             => $listing->venue?->maps_url,
                'images'                  => $listing->images_urls ?? [],
                'sport'                   => $listing->sportsCategory?->name,
                'max_multiple_bookings'   => $listing->max_multiple_bookings,
                'allow_multiple_bookings' => $listing->allow_multiple_bookings,
                'rating'                  => round($listing->average_rating, 1),
                'duration'                => getFormattedDuration($listing->slot_duration * 60),
            ];
        });

        return sendSuccess("Listings fetched successfully", compact('listings'));
    }

    public function fetchSuggestions($request)
    {
        $categoryType = $request->category ?? null;

        $sportsType = SportsType::withCount('listings')->where("title", $categoryType)->first();

        $categoryIds = SportsCategory::where("sports_type_id", $sportsType->id ?? null)->pluck('id')->toArray();

        $listings = $this->listingModel::with(['venue', 'sportsCategory', 'assets'])
            ->when($categoryIds, function ($query, $categoryIds) {
                $query->whereNotIn('sports_category_id', $categoryIds);
            })
            ->whereRelation('venue', 'status', 'active')
            ->take(3)
            ->get()
            ->map(function ($listing) {
                return [
                    'id'                      => $listing->id,
                    'name'                    => $listing->name,
                    'price'                   => $listing->base_price,
                    'description'             => $listing->description,
                    'venue'                   => $listing->venue?->name,
                    'loyaltyPoints'           => $listing->loyalty_points,
                    'payment_options'         => $listing->payment_options,
                    'map_address'             => $listing->venue?->maps_url,
                    'images'                  => $listing->images_urls ?? [],
                    'sport'                   => $listing->sportsCategory?->name,
                    'category'                => $listing->sportsCategory?->name,
                    'max_multiple_bookings'   => $listing->max_multiple_bookings,
                    'allow_multiple_bookings' => $listing->allow_multiple_bookings,
                    'icon'                    => $listing->sportsCategory?->sportsType?->icon,
                    'rating'                  => round($listing->average_rating, 1),
                    'duration'                => getFormattedDuration($listing->slot_duration * 60),
                ];
            });

        return sendSuccess("Listings fetched successfully", compact('listings', 'sportsType'));
    }

    public function fetchAddons($listing_id)
    {
        $listing = $this->listingModel->with([
            'addons' => function ($query) {
                $query->orderBy('sort_order', 'ASC');
            },
            'assets',
        ])->findOrFail($listing_id);

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

    public function listing($id)
    {
        $date = request('date', now()->toDateString());

        $listing = $this->listingModel::with([
            'venue:id,name,maps_url',
            'sportsCategory:id,name',
            'assets',
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
            'capacity'                => $listing->capacity,
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
            'assets',
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

            if ($isAvailable) {

                $price = determineSlotPrice($listing, $weekday, $slotStart, $slotEnd, $specialDay);

                $duration = getFormattedDuration($slotMinutes);

                $slots->push([
                    'start_time'          => $slotStart,
                    'end_time'            => $slotEnd,
                    'duration'            => $duration,
                    'is_holiday'          => $isHoliday,
                    'available'           => $isAvailable,
                    'booked'              => $bookedCount,
                    'available_resources' => $availableQty,
                    'available_count'     => $availableCount,
                    'capacity'            => $listing->capacity,
                    'time'                => "{$slotStart} - {$slotEnd}",
                    'price'                 => round($price, 2),
                    'max_bookings_per_slot' => $listing->max_bookings_per_slot,
                ]);
            }

            $start->addMinutes($slotMinutes);
        }

        return $slots->values();
    }

    public function fetchRecurringBookedSlots($request)
    {
        $request->validate([
            'listing_id' => 'required|numeric|exists:listings,id',
            'start_time' => 'required|date_format:H:i',
            'end_time'   => 'required|date_format:H:i',
            'date'       => 'required|date',
            'start_date' => 'required|date',
            'end_date'   => 'required|date',
        ]);

        try {
            $listingId = $request->listing_id;
            $startTime = $request->start_time;
            $endTime   = $request->end_time;
            $date      = Carbon::parse($request->date);
            $startDate = Carbon::parse($request->start_date);
            $endDate   = Carbon::parse($request->end_date);

            // Verify listing exists
            $listing = $this->listingModel->findOrFail($listingId);

            // Find bookings that match:
            // 1. Same listing
            // 2. Same time slot (start_time and end_time)
            // 3. Date between start_date and end_date
            // 4. Not cancelled or completed
            $bookedDates = Booking::where('listing_id', $listingId)
                ->where('start_time', $startTime)
                ->where('end_time', $endTime)
                ->whereBetween('date', [$startDate->toDateString(), $endDate->toDateString()])
                ->whereNotIn('status', [BookingStatus::CANCELLED, BookingStatus::COMPLETED])
                ->pluck('date')
                ->map(function ($date) {
                    return Carbon::parse($date)->format('Y-m-d');
                })
                ->unique()
                ->values()
                ->toArray();

            return sendSuccess('Recurring booked slots fetched successfully', [
                'listing_id'    => $listingId,
                'start_time'    => $startTime,
                'end_time'      => $endTime,
                'selected_date' => $date->format('Y-m-d'),
                'date_range'    => [
                    'start_date' => $startDate->format('Y-m-d'),
                    'end_date'   => $endDate->format('Y-m-d'),
                ],
                'booked_dates'  => $bookedDates,
                'total_booked'  => count($bookedDates),
            ]);

        } catch (\Illuminate\Database\Eloquent\ModelNotFoundException $e) {
            return sendError('Listing not found', [], 404);
        } catch (\Exception $e) {
            return sendError('Failed to fetch recurring booked slots: ' . $e->getMessage(), [], 500);
        }
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

    /**
     * Validate schedule times - handles 24-hour operation and wrap-around times
     *
     * @param array $schedules
     * @return void
     * @throws ValidationException
     */
    protected function validateScheduleTimes(array $schedules): void
    {
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
                    throw ValidationException::withMessages([
                        "schedule.{$index}.end_time" => ["The schedule.{$index}.end_time field must be a date after schedule.{$index}.start_time."],
                    ]);
                }
            }
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
}
