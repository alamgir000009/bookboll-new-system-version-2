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
use Exception;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
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

        } catch (Exception $e) {
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
                $this->deleteListingAssets($listing);
                $this->uploadImagesAsAssets($listing, $request->file('images'));
            }

            unset($listingUpdateData['images_urls']);

            $listing->update($listingUpdateData);

            if (isset($formData['add_ons'])) {
                $listing->addOns()->sync($formData['add_ons']);
            } elseif (empty($formData['add_ons'])) {
                $listing->addOns()->delete();
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

        } catch (Exception $e) {
            DB::rollBack();
            return sendError('Failed to update listing: ' . $e->getMessage());
        }
    }

    public function show($id)
    {
        $listing = $this->listingModel->with(['schedules', 'addOns', 'resources', 'pricingGroups', 'assets'])->findOrFail($id);
        return sendSuccess('Listing fetched successfully', compact('listing'));
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
            'tags'                               => 'nullable|array',
            'tags.left_tag'                      => 'nullable|array',
            'tags.left_tag.text'                 => 'nullable|string|max:30',
            'tags.left_tag.color'                => 'nullable|string|regex:/^#[a-fA-F0-9]{6}$/',
            'tags.right_tag'                     => 'nullable|array',
            'tags.right_tag.text'                 => 'nullable|string|max:30',
            'tags.right_tag.color'               => 'nullable|string|regex:/^#[a-fA-F0-9]{6}$/',
            'resources'                          => 'nullable|array',
            'resources.*.id'                     => 'required|exists:resources,id',
            'resources.*.quantity'               => 'nullable|integer|min:1',
            'amenities'                          => 'array',
        ];

        if ($isUpdate) {
            $rules = $this->makeRulesOptional($rules);
        }

        $request->validate($rules,[
            'tags.left_tag.text.max' => 'The left tag text must be less than 30 characters.',
            'tags.right_tag.text.max' => 'The right tag text must be less than 30 characters.',
        ]);

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

        if (isset($data['tags']) && is_string($data['tags'])) {
            $decoded = json_decode($data['tags'], true);
            if (json_last_error() == JSON_ERROR_NONE) {
                $data['tags'] = is_array($decoded) ? $decoded : [];
            } else {
                throw ValidationException::withMessages([
                    'tags' => ['Invalid JSON format for tags'],
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
            'tags'                               => 'nullable|array',
            'tags.left_tag'                      => 'nullable|array',
            'tags.left_tag.text'                 => 'nullable|string|max:255',
            'tags.left_tag.color'                => 'nullable|string|regex:/^#[a-fA-F0-9]{6}$/',
            'tags.right_tag'                     => 'nullable|array',
            'tags.right_tag.text'                => 'nullable|string|max:255',
            'tags.right_tag.color'               => 'nullable|string|regex:/^#[a-fA-F0-9]{6}$/',
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
        $tags = [
            'left_tag'  => null,
            'right_tag' => null,
        ];

        if (isset($data['tags']) && is_array($data['tags'])) {
            // Handle new structured format with left_tag and right_tag
            if (isset($data['tags']['left_tag']) && is_array($data['tags']['left_tag'])) {
                $tags['left_tag'] = [
                    'text'  => isset($data['tags']['left_tag']['text']) ? trim($data['tags']['left_tag']['text']) : null,
                    'color' => isset($data['tags']['left_tag']['color']) ? trim($data['tags']['left_tag']['color']) : null,
                ];
                // Remove if both are empty
                if (empty($tags['left_tag']['text']) && empty($tags['left_tag']['color'])) {
                    $tags['left_tag'] = null;
                }
            }

            if (isset($data['tags']['right_tag']) && is_array($data['tags']['right_tag'])) {
                $tags['right_tag'] = [
                    'text'  => isset($data['tags']['right_tag']['text']) ? trim($data['tags']['right_tag']['text']) : null,
                    'color' => isset($data['tags']['right_tag']['color']) ? trim($data['tags']['right_tag']['color']) : null,
                ];
                // Remove if both are empty
                if (empty($tags['right_tag']['text']) && empty($tags['right_tag']['color'])) {
                    $tags['right_tag'] = null;
                }
            }

            // If both tags are null, set tags to null
            if ($tags['left_tag'] === null && $tags['right_tag'] === null) {
                $tags = null;
            }
        }

        return [
            'name'                    => $data['name'],
            'status'                  => $data['status'],
            'venue_id'                => $data['venue_id'],
            'capacity'                => $data['capacity'],
            'base_price'              => $data['base_price'],
            'slot_duration'           => $data['slot_duration'],
            'online_payment'          => $data['online_payment'],
            'amenities'               => $data['amenities'] ?? [],
            'tags'                    => $tags,
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
        $existingSchedules = $listing->schedules()->get();

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
            $listing->schedules()->whereIn('id', $idsToDelete)->delete();
        }

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
            if (Storage::disk('public')->exists($asset->path)) {
                Storage::disk('public')->delete($asset->path);
            }
            $asset->delete();
        }
    }

    public function uploadImage($request, $listingId)
    {
        $request->validate([
            'image' => 'required|image|mimes:jpeg,jpg,png,gif,webp|max:5120',
        ]);

        try {
            $listing = $this->listingModel->findOrFail($listingId);

            if ($request->hasFile('image')) {
                $uploadedPath = $request->file('image')->store('listings', 'public');

                $maxSortOrder = Asset::where('listing_id', $listingId)->max('sort_order') ?? 0;

                $asset = Asset::create([
                    'listing_id' => $listingId,
                    'path'       => $uploadedPath,
                    'type'       => 'image',
                    'sort_order' => $maxSortOrder + 1,
                ]);

                $uploadedUrl = $asset->url;

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

            $imageUrl = $request->query('url');

            if (strpos($imageUrl, '/storage/') !== false) {
                $urlPath = parse_url($imageUrl, PHP_URL_PATH);
                $path    = str_replace('/storage/', '', $urlPath);
            } elseif (strpos($imageUrl, 'storage/') === 0) {
                $path = str_replace('storage/', '', $imageUrl);
            } else {
                $path = $imageUrl;
            }

            $path = ltrim($path, '/');

            $asset = Asset::where('listing_id', $listingId)
                ->where('path', $path)
                ->first();

            if (! $asset) {
                $filename = basename($path);
                $asset    = Asset::where('listing_id', $listingId)
                    ->whereRaw("SUBSTRING_INDEX(path, '/', -1) = ?", [$filename])
                    ->first();
            }

            if (! $asset) {
                return sendError('Image not found in listing assets', [], 404);
            }

            if (Storage::disk('public')->exists($asset->path)) {
                Storage::disk('public')->delete($asset->path);
            }

            $asset->delete();

            $remainingImages = Asset::where('listing_id', $listingId)->count();

            return sendSuccess('Image deleted successfully', [
                'listing_id'       => $listing->id,
                'remaining_images' => $remainingImages,
            ]);

        } catch (\Illuminate\Database\Eloquent\ModelNotFoundException $e) {
            return sendError('Listing not found', [], 404);
        } catch (Exception $e) {
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
        $exclude_id       = $request->exclude_id ?? null;
        $records_per_page = $request->records_per_page ?? null;

        $query = $this->listingModel::with(['venue', 'schedules', 'sportsCategory.sportsType', 'addOns', 'assets'])
            ->active()
            ->whereRelation('venue', 'status', 'active')
            ->whereHas('schedules', function ($q) {
                $q->where('is_open', 1);
            })
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
            ->when($exclude_id, function ($query, $exclude_id) {
                $query->where('id', '!=', $exclude_id);
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
                'tags'                    => $listing->tags ?? [],
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
        $limit        = $request->limit ?? 3;

        $sportsType = SportsType::withCount('listings')->where("title", $categoryType)->first();

        $categoryIds = SportsCategory::where("sports_type_id", $sportsType->id ?? null)->pluck('id')->toArray();

        $listings = $this->listingModel::with(['venue', 'sportsCategory', 'assets'])
            ->when($categoryIds, function ($query, $categoryIds) {
                $query->whereNotIn('sports_category_id', $categoryIds);
            })
            ->whereRelation('venue', 'status', 'active')
            ->take($limit)
            ->get()
            ->map(function ($listing) {
                return [
                    'id'                      => $listing->id,
                    'name'                    => $listing->name,
                    'price'                   => $listing->base_price,
                    'tags'                    => $listing->tags ?? [],
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

    public function mobileListingsList($request)
    {
        $data = $request->validate([
            'search'             => 'nullable|string|max:255',
            'page'               => 'nullable|integer|min:1',
            'records_per_page'   => 'nullable|integer|min:1|max:100',
            'venue_id'           => 'nullable|exists:venues,id',
            'sports_category_id' => 'nullable|exists:sports_categories,id',
        ]);

        $recordsPerPage   = $data['records_per_page'] ?? 10;
        $search           = $data['search'] ?? null;
        $venueId          = $data['venue_id'] ?? null;
        $sportsCategoryId = $data['sports_category_id'] ?? null;

        $query = $this->listingModel::with([
            'venue:id,name,maps_url,description',
            'addOns' => function ($query) {
                $query->active()->orderBy('sort_order', 'ASC');
            },
            'sportsCategory:id,name',
            'assets',
        ])
            ->active()
            ->whereRelation('venue', 'status', 'active')
            ->whereHas('schedules', function ($q) {
                $q->where('is_open', 1);
            })
            ->orderBy('sort_order', 'ASC')
            ->when($search, function ($query, $search) {
                $query->where(function ($q) use ($search) {
                    $q->where('name', 'LIKE', "%{$search}%")
                        ->orWhere('description', 'LIKE', "%{$search}%")
                        ->orWhereHas('venue', function ($v) use ($search) {
                            $v->where('name', 'LIKE', "%{$search}%");
                        })
                        ->orWhereHas('sportsCategory', function ($sc) use ($search) {
                            $sc->where('name', 'LIKE', "%{$search}%");
                        });
                });
            })
            ->when($venueId, function ($query, $venueId) {
                $query->where('venue_id', $venueId);
            })
            ->when($sportsCategoryId, function ($query, $sportsCategoryId) {
                $query->where('sports_category_id', $sportsCategoryId);
            });

        $listings = $query->paginate($recordsPerPage);

        $listings->getCollection()->transform(function ($listing) {
            return [
                'id'                      => $listing->id,
                'name'                    => $listing->name,
                'description'             => $listing->description,
                'base_price'              => $listing->base_price,
                'slot_duration'           => $listing->slot_duration,
                'duration'                => getFormattedDuration($listing->slot_duration * 60),
                'capacity'                => $listing->capacity,
                'loyalty_points'          => $listing->loyalty_points,
                'payment_options'         => $listing->payment_options,
                'images'                  => $listing->images_urls ?? [],
                'amenities'               => $listing->amenities ?? [],
                'tags'                    => $listing->tags ?? [],
                'max_multiple_bookings'   => $listing->max_multiple_bookings,
                'allow_multiple_bookings' => $listing->allow_multiple_bookings,
                'rating'                  => round($listing->average_rating, 1),
                'total_reviews'           => $listing->total_reviews,
                'venue'                   => [
                    'id'          => $listing->venue?->id,
                    'name'        => $listing->venue?->name,
                    'maps_url'    => $listing->venue?->maps_url,
                    'description' => $listing->venue?->description,
                ],
                'addons'                  => $listing->addOns->map(function ($addon) {
                    return [
                        'id'              => $addon->id,
                        'name'            => $addon->name,
                        'image'           => $addon->image,
                        'price'           => $addon->price ?? 0,
                        'description'     => $addon->description,
                        'hours_extension' => $addon->hours_extension,
                    ];
                }),
                'sports_category'         => [
                    'id'   => $listing->sportsCategory?->id,
                    'name' => $listing->sportsCategory?->name,
                ],
            ];
        });

        return sendSuccess('Listings fetched successfully', [
            'data'       => $listings->items(),
            'pagination' => [
                'current_page' => $listings->currentPage(),
                'last_page'    => $listings->lastPage(),
                'per_page'     => $listings->perPage(),
                'total'        => $listings->total(),
                'from'         => $listings->firstItem(),
                'to'           => $listings->lastItem(),
            ],
        ]);
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
            'tags'                    => $listing->tags ?? [],
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
        $sessionId = getSessionId();

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

        $venueSchedules = $listing->venue?->schedules
            ->where('day', $weekday)
            ->where('is_open', true)
            ->whereNotNull('start_time')
            ->whereNotNull('end_time')
            ->sortBy('start_time')
            ->values();

        $listingSchedules = $listing->schedules
            ->where('day', $weekday)
            ->where('is_open', true)
            ->whereNotNull('start_time')
            ->whereNotNull('end_time')
            ->sortBy('start_time')
            ->values();

        if ($venueSchedules->isEmpty() || $listingSchedules->isEmpty()) {
            return sendSuccess('No available schedule for this day', ['timeSlots' => []]);
        }

        $specialDay = SpecialDay::with('timeBlocks')
            ->where('venue_id', $listing->venue_id)
            ->where(function ($q) use ($listing) {
                $q->whereNull('listing_id')->orWhere('listing_id', $listing->id);
            })
            ->whereDate('date', $date)
            ->active()
            ->first();

        $isHoliday = $specialDay?->type === 'holiday';

        if ($isHoliday) {
            return sendSuccess('No available schedule for this day (Holiday)', ['timeSlots' => []]);
        }

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
            $availableBlocks = $this->findOverlappingScheduleBlocks(
                $listingSchedules,
                $venueSchedules
            );
        }

        if ($availableBlocks->isEmpty()) {
            return sendSuccess('No available schedule for this day', ['timeSlots' => []]);
        }

        $bookings = Booking::where('listing_id', $listing->id)
            ->where('date', $date)
            ->whereNotIn('status', [BookingStatus::CANCELLED, BookingStatus::COMPLETED])
            ->with('resourcesAllocated')
            ->get(['id', 'start_time', 'end_time', 'status']);

        $activeLocks = SlotLock::where('listing_id', $listing->id)
            ->where('date', $date->toDateString())
            ->active()
            ->get();

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
                $isHoliday,
                $activeLocks,
                $sessionId,
                $date
            );
            $timeSlots = $timeSlots->merge($slots);
        }

        $timeSlots = $timeSlots->sortBy('start_time')->values();

        return sendSuccess('Listing slots fetched successfully', compact('timeSlots'));
    }

    protected function findOverlappingScheduleBlocks($listingSchedules, $venueSchedules)
    {
        $overlappingBlocks = collect();

        foreach ($listingSchedules as $listingSchedule) {
            $listingStart = Carbon::parse($listingSchedule->start_time);
            $listingEnd   = Carbon::parse($listingSchedule->end_time);

            if ($listingEnd->format('H:i') === '00:00') {
                $listingEnd->addDay();
            }

            foreach ($venueSchedules as $venueSchedule) {
                $venueStart = Carbon::parse($venueSchedule->start_time);
                $venueEnd   = Carbon::parse($venueSchedule->end_time);

                if ($venueEnd->format('H:i') === '00:00') {
                    $venueEnd->addDay();
                }

                if ($listingStart->lt($venueEnd) && $listingEnd->gt($venueStart)) {
                    $overlapStart = $listingStart->gt($venueStart) ? $listingStart : $venueStart;
                    $overlapEnd   = $listingEnd->lt($venueEnd) ? $listingEnd : $venueEnd;

                    if ($overlapStart->lt($overlapEnd)) {
                        $overlapEndFormatted = $overlapEnd->format('H:i');

                        if (($listingSchedule->end_time === '00:00' || $venueSchedule->end_time === '00:00') && $overlapEnd->day > $overlapStart->day) {
                            $overlapEndFormatted = '00:00';
                        }

                        $overlappingBlocks->push([
                            'start_time' => $overlapStart->format('H:i'),
                            'end_time'   => $overlapEndFormatted,
                        ]);
                    }
                }
            }
        }

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
            $currentEndTime = $current['end_time'];
            $blockStartTime = $block['start_time'];

            $shouldMerge = false;

            if ($currentEndTime === '00:00' && $blockStartTime === '00:00') {
                $shouldMerge = true;
            } elseif ($blockStartTime === '00:00') {
                $currentEnd  = Carbon::parse($currentEndTime);
                $shouldMerge = ($currentEnd->format('H') === '23');
            } elseif ($currentEndTime === '00:00') {
                $shouldMerge = false;
            } else {
                $currentEnd  = Carbon::parse($currentEndTime);
                $blockStart  = Carbon::parse($blockStartTime);
                $shouldMerge = $blockStart->lte($currentEnd);
            }

            if ($shouldMerge) {
                $currentEnd = Carbon::parse($currentEndTime);
                $blockEnd   = Carbon::parse($block['end_time']);

                if ($currentEndTime === '00:00') {
                    $currentEnd->addDay();
                }
                if ($block['end_time'] === '00:00') {
                    $blockEnd->addDay();
                }

                if ($currentEnd->gte($blockEnd)) {
                    $current['end_time'] = $currentEndTime;
                } else {
                    $current['end_time'] = $block['end_time'];
                }
            } else {
                $merged->push($current);
                $current = $block;
            }
        }

        $merged->push($current);
        return $merged;
    }

    protected function generateTimeSlots($startTime, $endTime, $slotMinutes, $listing, $weekday, $specialDay, $bookings, $isHoliday, $activeLocks = null, $sessionId = null, $date = null)
    {
        $start = Carbon::parse($startTime);
        $end   = Carbon::parse($endTime);

        if ($endTime === '00:00' || $end->lte($start)) {
            $end->addDay();
        }

        $slots = collect();

        $listingResources = $listing->resources()->get(['resources.id', 'resources.total_quantity', 'listing_resource.quantity as required_qty']);

        while ($start->lt($end)) {
            $slotStart     = $start->format('H:i');
            $slotEndCarbon = $start->copy()->addMinutes($slotMinutes);

            if ($slotEndCarbon->day > $start->day) {
                $slotEnd = '00:00';
            } else {
                $slotEnd = $slotEndCarbon->format('H:i');
            }

            if ($slotEndCarbon->gt($end)) {
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

            $isLocked = false;
            if ($activeLocks && $sessionId) {
                $isLocked = $activeLocks->contains(function ($lock) use ($slotStart, $slotEnd, $sessionId) {
                    if ($lock->session_id === $sessionId) {
                        return false;
                    }
                    return $this->slotsOverlap(
                        $slotStart,
                        $slotEnd,
                        $lock->start_time ? Carbon::parse($lock->start_time)->format('H:i') : null,
                        $lock->end_time ? Carbon::parse($lock->end_time)->format('H:i') : null
                    );
                });
            }

            $isPastSlot = false;
            if ($date) {
                $dateCarbon = $date instanceof Carbon ? $date : Carbon::parse($date);
                if ($dateCarbon->isToday()) {
                    $currentTime   = Carbon::now();
                    $slotStartTime = Carbon::parse($dateCarbon->format('Y-m-d') . ' ' . $slotStart);

                    if ($slotStartTime->lt($currentTime)) {
                        $isPastSlot = true;
                    }
                }
            }

            if ($isPastSlot) {
                $start->addMinutes($slotMinutes);
                continue;
            }

            $isAvailable = ! $isHoliday && $availableCount > 0 && $allResourcesAvailable && ! $isLocked;

            if ($isAvailable || $isLocked) {

                $price = determineSlotPrice($listing, $weekday, $slotStart, $slotEnd, $specialDay);

                $duration = getFormattedDuration($slotMinutes);

                $slots->push([
                    'start_time'          => $slotStart,
                    'end_time'            => $slotEnd,
                    'duration'            => $duration,
                    'is_holiday'          => $isHoliday,
                    'available'           => $isAvailable,
                    'is_locked'           => $isLocked,
                    'booked'              => $bookedCount,
                    'available_resources' => $availableQty,
                    'available_count'     => $availableCount,
                    'capacity'            => $listing->capacity,
                    'time'                => "{$slotStart} - {$slotEnd}",
                    'max_bookings_per_slot' => $listing->max_bookings_per_slot,
                    'price'                 => round($price, 2),
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
            'start_date' => 'required|date',
            'end_date'   => 'required|date',
            'date'       => 'required|date',
        ]);

        try {
            $listingId = $request->listing_id;
            $startTime = $request->start_time;
            $endTime   = $request->end_time;
            $startDate = Carbon::parse($request->start_date);
            $endDate   = Carbon::parse($request->end_date);
            $date      = Carbon::parse($request->date);

            $listing = $this->listingModel->with([
                'venue',
                'schedules',
                'pricingGroups',
                'venue.schedules',
            ])->findOrFail($listingId);

            if (! $listing->venue || $listing->venue->status !== 'active') {
                return sendSuccess('Recurring booked slots fetched successfully', [
                    'listing_id'            => $listingId,
                    'start_time'            => $startTime,
                    'end_time'              => $endTime,
                    'date_range'            => [
                        'start_date' => $startDate->format('Y-m-d'),
                        'end_date'   => $endDate->format('Y-m-d'),
                    ],
                    'max_bookings_per_slot' => 0,
                    'dates'                 => [],
                ]);
            }

            $maxBookingsPerSlot = $listing->max_bookings_per_slot ?? 1;

            $startDate = $startDate->lt($date) ? $date : $startDate;
            $endDate   = $endDate->lt($date) ? $date : $endDate;

            $bookings = Booking::where('listing_id', $listingId)
                ->whereBetween('date', [$startDate->toDateString(), $endDate->toDateString()])
                ->whereNotIn('status', [BookingStatus::CANCELLED, BookingStatus::COMPLETED])
                ->where(function ($q) use ($startTime, $endTime) {
                    $q->whereRaw('TIME(start_time) < ?', [$endTime])
                        ->whereRaw('TIME(end_time) > ?', [$startTime]);
                })
                ->get(['id', 'date', 'start_time', 'end_time']);

            $bookedDates = [];
            foreach ($bookings as $booking) {
                $dateStr = Carbon::parse($booking->date)->format('Y-m-d');
                if (! isset($bookedDates[$dateStr])) {
                    $bookedDates[$dateStr] = 0;
                }
                $bookedDates[$dateStr]++;
            }

            $specialDays = SpecialDay::with('timeBlocks')
                ->where('venue_id', $listing->venue_id)
                ->where(function ($q) use ($listing) {
                    $q->whereNull('listing_id')->orWhere('listing_id', $listing->id);
                })
                ->whereBetween('date', [$startDate->toDateString(), $endDate->toDateString()])
                ->active()
                ->get()
                ->keyBy(function ($day) {
                    return Carbon::parse($day->date)->format('Y-m-d');
                });

            $datesInfo   = [];
            $currentDate = $startDate->copy();

            while ($currentDate->lte($endDate)) {
                if ($currentDate->lt($date)) {
                    $currentDate->addDay();
                    continue;
                }

                $dateStr     = $currentDate->format('Y-m-d');
                $bookedCount = $bookedDates[$dateStr] ?? 0;
                $weekday     = strtolower($currentDate->format('l'));

                $venueSchedules = $listing->venue?->schedules
                    ->where('day', $weekday)
                    ->where('is_open', true)
                    ->whereNotNull('start_time')
                    ->whereNotNull('end_time')
                    ->values();

                $listingSchedules = $listing->schedules
                    ->where('day', $weekday)
                    ->where('is_open', true)
                    ->whereNotNull('start_time')
                    ->whereNotNull('end_time')
                    ->values();

                $isOpenOnDay = ! $venueSchedules->isEmpty() && ! $listingSchedules->isEmpty();

                $specialDay = $specialDays->get($dateStr);
                $isHoliday  = $specialDay && $specialDay->type === 'holiday';

                $isTimeSlotAvailable = false;
                if ($isOpenOnDay && ! $isHoliday) {
                    if ($specialDay && $specialDay->timeBlocks->isNotEmpty()) {
                        $isTimeSlotAvailable = $specialDay->timeBlocks->contains(function ($block) use ($startTime, $endTime) {
                            $blockStart = $block->start_time;
                            $blockEnd   = $block->end_time;
                            return $this->slotsOverlap($startTime, $endTime, $blockStart, $blockEnd);
                        });
                    } else {
                        $overlappingBlocks   = $this->findOverlappingScheduleBlocks($listingSchedules, $venueSchedules);
                        $isTimeSlotAvailable = $overlappingBlocks->contains(function ($block) use ($startTime, $endTime) {
                            return $this->slotsOverlap($startTime, $endTime, $block['start_time'], $block['end_time']);
                        });
                    }
                }

                $isAvailable = $isOpenOnDay && ! $isHoliday && $isTimeSlotAvailable;

                $dayPrice = determineSlotPrice($listing, $weekday, $startTime, $endTime, $specialDay);

                if (! $isAvailable) {
                    $bookedCount = $maxBookingsPerSlot;
                }

                $datesInfo[] = [
                    'date'            => $dateStr,
                    'booked_count'    => $bookedCount,
                    'max_bookings'    => $maxBookingsPerSlot,
                    'price'           => round($dayPrice, 2),
                    'is_available'    => $isAvailable && ($bookedCount < $maxBookingsPerSlot),
                    'available_slots' => max(0, $maxBookingsPerSlot - $bookedCount),
                ];

                $currentDate->addDay();
            }

            return sendSuccess('Recurring booked slots fetched successfully', [
                'listing_id'            => $listingId,
                'start_time'            => $startTime,
                'end_time'              => $endTime,
                'date_range'            => [
                    'start_date' => $startDate->format('Y-m-d'),
                    'end_date'   => $endDate->format('Y-m-d'),
                ],
                'max_bookings_per_slot' => $maxBookingsPerSlot,
                'dates'                 => $datesInfo,
            ]);

        } catch (\Illuminate\Database\Eloquent\ModelNotFoundException $e) {
            return sendError('Listing not found');
        } catch (Exception $e) {
            Log::error('Booked recurring slots error: ' . $e->getMessage(), ["Exception" => $e]);
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
        } catch (Exception $e) {
            DB::rollBack();
            Log::error('Failed to update sort order: ' . $e->getMessage(), ["Exception" => $e]);
            return sendError('Failed to update sort order.');
        }
    }

    protected function validateScheduleTimes(array $schedules): void
    {
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
                    throw ValidationException::withMessages([
                        "schedule.{$index}.end_time" => ["The schedule.{$index}.end_time field must be a date after schedule.{$index}.start_time."],
                    ]);
                }
            }
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

    public function lockSlot($request)
    {
        $request->validate([
            'listing_id' => 'required|numeric|exists:listings,id',
            'date'       => 'required|date|after_or_equal:today',
            'start_time' => 'required|date_format:H:i',
            'end_time'   => 'required|date_format:H:i',
        ], [
            'date.after_or_equal' => 'You cannot lock a slot for a past date.',
            'end_time.after'      => 'End time must be after start time.',
        ]);

        try {

            $user      = auth()->user();
            $ipAddress = $request->ip();
            $sessionId = getSessionId();
            $listingId = $request->listing_id;
            $startTime = $request->start_time;
            $endTime   = $request->end_time;
            $date      = Carbon::parse($request->date);

            $listing = $this->listingModel->with('venue')->findOrFail($listingId);

            if ($listing->status !== 'active' || ! $listing->venue || $listing->venue->status !== 'active') {
                return sendError('Listing is not available for booking');
            }

            $existingBooking = Booking::where('listing_id', $listingId)
                ->whereDate('date', $date)
                ->whereNotIn('status', [BookingStatus::CANCELLED, BookingStatus::COMPLETED])
                ->where(function ($q) use ($startTime, $endTime) {
                    $q->whereRaw('TIME(start_time) < ?', [$endTime])
                        ->whereRaw('TIME(end_time) > ?', [$startTime]);
                })
                ->count();

            if ($existingBooking >= (int) $listing->max_bookings_per_slot) {
                return sendError('This slot is already booked2');
            }

            $existingLock = SlotLock::where('listing_id', $listingId)
                ->where('date', $date->toDateString())
                ->where('start_time', $startTime)
                ->where('end_time', $endTime)
                ->where('session_id', '!=', $sessionId)
                ->active()
                ->first();

            if ($existingLock) {
                return sendError('This slot is currently locked by another user. Please try again in a moment.');
            }

            $lockDuration = intval(Setting::where('key', 'default_bookings_slot_lock_time')->value('value')) ?? 5;

            $lock = SlotLock::updateOrCreate(
                [
                    'start_time' => $startTime,
                    'end_time'   => $endTime,
                    'session_id' => $sessionId,
                    'listing_id' => $listingId,
                    'date'       => $date->toDateString(),
                ],
                [
                    'user_id'      => $user?->id,
                    'ip_address'   => $ipAddress,
                    'locked_until' => now()->addMinutes($lockDuration),
                ]
            );

            return sendSuccess('Slot locked successfully', [
                'lock_id'      => $lock->id,
                'listing_id'   => $listingId,
                'start_time'   => $startTime,
                'end_time'     => $endTime,
                'date'         => $date->toDateString(),
                'locked_until' => $lock->locked_until->toIso8601String(),
            ]);

        } catch (\Illuminate\Database\Eloquent\ModelNotFoundException $e) {
            return sendError('Listing not found', [], 404);
        } catch (Exception $e) {
            Log::error('Failed to lock slot: ' . $e->getMessage(), ['exception' => $e]);
            return sendError('Failed to lock slot: ' . $e->getMessage());
        }
    }

    public function unlockSlot($request)
    {
        $request->validate([
            'listing_id' => 'required|numeric|exists:listings,id',
            'date'       => 'required|date',
            'start_time' => 'required|date_format:H:i',
            'end_time'   => 'required|date_format:H:i',
        ]);

        try {

            $sessionId = getSessionId();
            $startTime = $request->start_time;
            $endTime   = $request->end_time;
            $listingId = $request->listing_id;
            $date      = Carbon::parse($request->date);

            $deleted = SlotLock::where('listing_id', $listingId)
                ->where('date', $date->toDateString())
                ->where('start_time', $startTime)
                ->where('end_time', $endTime)
                ->where('session_id', $sessionId)
                ->delete();

            if ($deleted) {
                return sendSuccess('Slot unlocked successfully', [
                    'listing_id' => $listingId,
                    'date'       => $date->toDateString(),
                    'start_time' => $startTime,
                    'end_time'   => $endTime,
                ]);
            }

            return sendError('Slot lock not found or already unlocked');

        } catch (Exception $e) {
            Log::error('Failed to unlock slot: ' . $e->getMessage(), ['exception' => $e]);
            return sendError('Failed to unlock slot: ' . $e->getMessage());
        }
    }

    public function unlockAllSlots($request)
    {
        $request->validate([
            'listing_id' => 'nullable|numeric|exists:listings,id',
            'date'       => 'nullable|date',
        ]);

        try {

            $sessionId = getSessionId();

            $query = SlotLock::where('session_id', $sessionId);

            if ($request->has('listing_id')) {
                $query->where('listing_id', $request->listing_id);
            }

            if ($request->has('date')) {
                $query->where('date', Carbon::parse($request->date)->toDateString());
            }

            $deletedCount = $query->delete();

            return sendSuccess('All slots unlocked successfully', [
                'unlocked_count' => $deletedCount,
            ]);

        } catch (Exception $e) {
            Log::error('Failed to unlock all slots: ' . $e->getMessage(), ['exception' => $e]);
            return sendError('Failed to unlock all slots: ' . $e->getMessage());
        }
    }

    public function extendSlotLock($request)
    {
        $request->validate([
            'listing_id' => 'required|numeric|exists:listings,id',
            'date'       => 'required|date',
            'start_time' => 'required|date_format:H:i',
            'end_time'   => 'required|date_format:H:i',
        ]);

        try {

            $sessionId = getSessionId();
            $listingId = $request->listing_id;
            $date      = Carbon::parse($request->date);
            $startTime = $request->start_time;
            $endTime   = $request->end_time;

            $lock = SlotLock::where('listing_id', $listingId)
                ->where('date', $date->toDateString())
                ->where('start_time', $startTime)
                ->where('end_time', $endTime)
                ->where('session_id', $sessionId)
                ->first();

            if (! $lock) {
                return sendError('Slot lock not found');
            }

            $lockDuration = intval(Setting::where('key', 'default_bookings_slot_lock_time')->value('value')) ?? 5;

            $lock->locked_until = now()->addMinutes($lockDuration);
            $lock->save();

            return sendSuccess('Slot lock extended successfully', [
                'lock_id'      => $lock->id,
                'start_time'   => $startTime,
                'end_time'     => $endTime,
                'listing_id'   => $listingId,
                'date'         => $date->toDateString(),
                'locked_until' => $lock->locked_until->toIso8601String(),
            ]);

        } catch (Exception $e) {
            Log::error('Failed to extend slot lock: ' . $e->getMessage(), ['exception' => $e]);
            return sendError('Failed to extend slot lock: ' . $e->getMessage(), [], 500);
        }
    }

    public function checkSlotAvailability($request)
    {
        $request->validate([
            'listing_id' => 'required|numeric|exists:listings,id',
            'date'       => 'required|date|after_or_equal:today',
            'start_time' => 'required|date_format:H:i',
            'end_time'   => 'required|date_format:H:i|after:start_time',
        ]);

        try {

            $listingId = $request->listing_id;
            $date      = Carbon::parse($request->date);
            $startTime = $request->start_time;
            $endTime   = $request->end_time;
            $sessionId = getSessionId();

            $listing = $this->listingModel->with('venue')->findOrFail($listingId);

            if ($listing->status !== 'active' || ! $listing->venue || $listing->venue->status !== 'active') {
                return sendSuccess('Slot availability checked', [
                    'available' => false,
                    'reason'    => 'Listing is not available',
                ]);
            }

            $existingBooking = Booking::where('listing_id', $listingId)
                ->where('date', $date)
                ->whereNotIn('status', [BookingStatus::CANCELLED, BookingStatus::COMPLETED])
                ->where(function ($q) use ($startTime, $endTime) {
                    $q->whereRaw('TIME(start_time) < ?', [$endTime])
                        ->whereRaw('TIME(end_time) > ?', [$startTime]);
                })
                ->exists();

            if ($existingBooking) {
                return sendSuccess('Slot availability checked', [
                    'available' => false,
                    'reason'    => 'Slot is already booked',
                ]);
            }

            $existingLock = SlotLock::where('listing_id', $listingId)
                ->where('date', $date->toDateString())
                ->where('start_time', $startTime)
                ->where('end_time', $endTime)
                ->where('session_id', '!=', $sessionId)
                ->active()
                ->first();

            if ($existingLock) {
                return sendSuccess('Slot availability checked', [
                    'available'    => false,
                    'reason'       => 'Slot is currently locked by another user',
                    'locked_until' => $existingLock->locked_until->toIso8601String(),
                ]);
            }

            return sendSuccess('Slot availability checked', [
                'available' => true,
            ]);

        } catch (\Illuminate\Database\Eloquent\ModelNotFoundException $e) {
            return sendError('Listing not found', [], 404);
        } catch (Exception $e) {
            Log::error('Failed to check slot availability: ' . $e->getMessage(), ['exception' => $e]);
            return sendError('Failed to check slot availability: ' . $e->getMessage());
        }
    }

    protected function slotsOverlap($start1, $end1, $start2, $end2)
    {
        if (! $start2 || ! $end2) {
            return false;
        }

        $start1Minutes = $this->timeToMinutes($start1);
        $end1Minutes   = $this->timeToMinutes($end1);
        $start2Minutes = $this->timeToMinutes($start2);
        $end2Minutes   = $this->timeToMinutes($end2);

        if ($end1 === '00:00') {
            $end1Minutes = 24 * 60;
        }
        if ($end2 === '00:00') {
            $end2Minutes = 24 * 60;
        }

        return $start1Minutes < $end2Minutes && $start2Minutes < $end1Minutes;
    }
}
