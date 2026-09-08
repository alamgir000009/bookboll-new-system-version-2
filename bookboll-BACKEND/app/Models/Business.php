<?php

namespace App\Models;

use App\Traits\LogsModelActivity;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
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
        'whatsapp_config',
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
        'brand_show_venues',
        'brand_show_events',
        'brand_show_programs',
        'brand_custom_domain',
        'brand_domain_status',
        'brand_domain_paid_at',
    ];

    protected function casts(): array
    {
        return [
            'business_types'          => 'array',
            'brand_enabled'           => 'boolean',
            'notification_templates'  => 'array',
            'whatsapp_config'         => 'array',
            'hero_banners'            => 'array',
            'site_pages'              => 'array',
            'brand_languages'         => 'array',
            'brand_whitelabel'        => 'boolean',
            'premium_since'           => 'datetime',
            'brand_show_venues'       => 'boolean',
            'brand_show_events'       => 'boolean',
            'brand_show_programs'     => 'boolean',
            'brand_domain_paid_at'    => 'datetime',
        ];
    }

    public function owner(): BelongsTo
    {
        return $this->belongsTo(User::class, 'owner_id');
    }

    /** Every Premium subscription this business has ever had, newest first. */
    public function subscriptions(): HasMany
    {
        return $this->hasMany(BusinessSubscription::class)->latest('id');
    }

    /**
     * The subscription that governs the plan right now — the newest one that still
     * entitles the business, or simply the newest if none does (so a cancelled or
     * abandoned one can still be shown on the billing page).
     */
    public function currentSubscription(): HasOne
    {
        return $this->hasOne(BusinessSubscription::class)->latestOfMany();
    }

    public function activeSubscription(): HasOne
    {
        return $this->hasOne(BusinessSubscription::class)
            ->whereIn('status', BusinessSubscription::ENTITLING)
            ->latestOfMany();
    }

    /** Custom-domain fee payments, newest first. */
    public function domainPurchases(): HasMany
    {
        return $this->hasMany(BusinessDomainPurchase::class)->latest('id');
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
