<?php

namespace App\Models;


use App\Traits\LogsModelActivity;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Str;

class HostedGame extends Model
{
    use LogsModelActivity;

    public $incrementing = false;

    public $timestamps = false;

    protected $keyType = 'string';

    protected $table = 'hosted_games';

    protected $fillable = [
        'id',
        'host_customer_id',
        'host_name',
        'host_phone',
        'host_whatsapp',
        'location_id',
        'listing_id',
        'booking_id',
        'city_id',
        'country_id',
        'title',
        'sport',
        'game_type',
        'game_date',
        'start_time',
        'end_time',
        'price_per_person',
        'currency',
        'max_players',
        'skill_level',
        'description',
        'status',
        'num_teams',
        'format',
        'prize_pot',
        'prize_note',
        'custom_location_name',
        'custom_location_address',
        'hide_location',
        'images',
        'moderation_status',
        'moderation_reason',
        'moderated_at',
        'moderated_by',
        'signup_form',
        'request_deadline',
        'approval_mode',
        'charge',
        'community_id',
        'lock_minutes_before',
        'play_format',
        'courts_count',
        'rounds',
        'community_public',
        'allow_waitlist',
        'created_at',
    ];

    protected $casts = [
        'game_date'            => 'date',
        'price_per_person'     => 'decimal:2',
        'max_players'          => 'integer',
        'num_teams'            => 'integer',
        'prize_pot'            => 'decimal:2',
        'hide_location'        => 'boolean',
        'images'               => 'array',
        'moderated_at'         => 'datetime',
        'signup_form'          => 'array',
        'request_deadline'     => 'datetime',
        'charge'               => 'array',
        'lock_minutes_before'  => 'integer',
        'courts_count'         => 'integer',
        'rounds'               => 'array',
        'community_public'     => 'boolean',
        'allow_waitlist'       => 'boolean',
        'created_at'           => 'datetime',
    ];

    protected static function booted(): void
    {
        static::creating(function (HostedGame $row) {
            if (! $row->id) {
                $row->id = (string) Str::uuid();
            }
            if (! $row->created_at) {
                $row->created_at = now();
            }
        });
    }

    public function joinRequests(): HasMany
    {
        return $this->hasMany(GameJoinRequest::class, 'game_id');
    }

    protected function activityLogName(): string
    {
        return 'hosted_game';
    }

    protected function activityLogAttributes(): array
    {
        return [
            'id',
            'host_customer_id',
            'host_name',
            'host_phone',
            'host_whatsapp',
            'location_id',
            'listing_id',
            'booking_id',
            'city_id',
            'country_id',
            'title',
            'sport',
            'game_type',
            'game_date',
            'start_time',
            'end_time',
            'price_per_person',
            'currency',
            'max_players',
            'skill_level',
            'description',
            'status',
            'num_teams',
            'format',
            'prize_pot',
            'prize_note',
            'custom_location_name',
            'custom_location_address',
            'hide_location',
            'images',
            'moderation_status',
            'moderation_reason',
            'moderated_at',
            'moderated_by',
            'signup_form',
            'request_deadline',
            'approval_mode',
            'charge',
            'community_id',
            'lock_minutes_before',
            'play_format',
            'courts_count',
            'rounds',
            'community_public',
            'allow_waitlist',
            'created_at',
        ];
    }
}
