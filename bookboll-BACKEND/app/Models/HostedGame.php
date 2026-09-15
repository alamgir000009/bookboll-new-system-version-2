<?php

namespace App\Models;


use App\Traits\GeneratesUuid;
use App\Traits\LogsModelActivity;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class HostedGame extends Model
{
    use GeneratesUuid, LogsModelActivity;

    protected $table = 'hosted_games';

    protected $fillable = [
        'uuid',
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
        'published_at',
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
        'updated_at',
    ];

    protected $casts = [
        'game_date'            => 'date',
        'price_per_person'     => 'decimal:2',
        'max_players'          => 'integer',
        'num_teams'            => 'integer',
        'prize_pot'            => 'decimal:2',
        'hide_location'        => 'boolean',
        'images'               => 'array',
        'published_at'         => 'datetime',
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
        'updated_at'           => 'datetime',
    ];

    /**
     * A game is only real once the host has paid for the court. Hosting creates
     * the row first and sends the host to Stripe, so an unpaid game exists in the
     * table with published_at NULL — it must stay out of every feed, every
     * listing, the moderation queue and the analytics until the money lands.
     *
     * Gating it here rather than at each query means a read path added later is
     * safe by default. The fulfilment and abandon paths, which must see these
     * rows, opt back in with HostedGame::withUnpublished().
     */
    protected static function booted(): void
    {
        static::addGlobalScope('published', function (Builder $query) {
            $query->whereNotNull($query->getModel()->getTable().'.published_at');
        });
    }

    /** Query including games still awaiting the host's payment. */
    public static function withUnpublished(): Builder
    {
        return static::withoutGlobalScope('published');
    }

    public function isPublished(): bool
    {
        return $this->published_at !== null;
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
            'uuid',
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
            'published_at',
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
            'updated_at',
        ];
    }
}
