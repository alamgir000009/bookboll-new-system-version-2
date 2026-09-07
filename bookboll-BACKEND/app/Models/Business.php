<?php

namespace App\Models;

use App\Traits\LogsModelActivity;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Nnjeim\World\Models\Country;

class Business extends Model
{
    use LogsModelActivity;

    public const STATUS_ACTIVE    = 'active';
    public const STATUS_PENDING   = 'pending';
    public const STATUS_SUSPENDED = 'suspended';

    public const STATUSES = [
        self::STATUS_PENDING,
        self::STATUS_ACTIVE,
        self::STATUS_SUSPENDED,
    ];

    public const BUSINESS_TYPE_KEYS = [
        'sports_facility',
        'gym',
        'academy',
        'classes',
        'other_venue',
    ];

    protected $fillable = [
        'name',
        'status',
        'owner_id',
        'username',
        'country_id',
        'brand_name',
        'brand_accent',
        'brand_primary',
        'brand_tagline',
        'brand_enabled',
        'brand_logo_url',
        'business_types',
        'brand_subdomain',
        'brand_meta_title',
        'brand_meta_description',
        'notification_templates',
        // White-label storefront settings (see the 2026_09_07 migration)
        'hero_banners',
        'sort_space',
        'sort_venue',
        'site_pages',
        'brand_languages',
        'tracking_meta_pixel',
        'tracking_ga4',
        'tracking_gtm',
        'tracking_head',
        'brand_whitelabel',
        'plan',
        'premium_since',
    ];

    protected function casts(): array
    {
        return [
            'business_types'          => 'array',
            'brand_enabled'           => 'boolean',
            'notification_templates'  => 'array',
            'hero_banners'            => 'array',
            'site_pages'              => 'array',
            'brand_languages'         => 'array',
            'brand_whitelabel'        => 'boolean',
            'premium_since'           => 'datetime',
        ];
    }

    public function owner(): BelongsTo
    {
        return $this->belongsTo(User::class, 'owner_id');
    }

    public function country(): BelongsTo
    {
        return $this->belongsTo(Country::class, 'country_id');
    }

    public function scopePending($query)
    {
        return $query->where('status', self::STATUS_PENDING);
    }

    public function scopeActive($query)
    {
        return $query->where('status', self::STATUS_ACTIVE);
    }

    protected function activityLogName(): string
    {
        return 'business';
    }

    protected function activityLogAttributes(): array
    {
        return [
            'owner_id',
            'name',
            'username',
            'business_types',
            'country_id',
            'status',
            'brand_enabled',
            'brand_subdomain',
            'brand_name',
            'brand_tagline',
            'brand_logo_url',
            'brand_primary',
            'brand_accent',
            'brand_meta_title',
            'brand_meta_description',
            'notification_templates',
        ];
    }
}
