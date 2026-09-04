<?php

namespace App\Models;

use App\Traits\GeneratesUuid;
use App\Traits\LogsModelActivity;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One game in a tournament bracket. The tournament itself is a hosted_games row
 * (game_type = 'tournament'); every match points back at it and holds its own
 * court reservation.
 */
class TournamentMatch extends Model
{
    use GeneratesUuid, LogsModelActivity;

    protected $table = 'tournament_matches';

    protected $fillable = [
        'uuid',
        'tournament_id',
        'round',
        'match_index',
        'listing_id',
        'location_id',
        'booking_id',
        'match_date',
        'start_time',
        'end_time',
        'home_team',
        'home_request_id',
        'away_team',
        'away_request_id',
        'winner',
        'status',
    ];

    protected $casts = [
        'round'       => 'integer',
        'match_index' => 'integer',
        'match_date'  => 'date',
        'created_at'  => 'datetime',
        'updated_at'  => 'datetime',
    ];

    public function tournament(): BelongsTo
    {
        return $this->belongsTo(HostedGame::class, 'tournament_id');
    }

    protected function activityLogName(): string
    {
        return 'tournament_match';
    }

    protected function activityLogAttributes(): array
    {
        return [
            'id',
            'uuid',
            'tournament_id',
            'round',
            'match_index',
            'listing_id',
            'location_id',
            'booking_id',
            'match_date',
            'start_time',
            'end_time',
            'home_team',
            'away_team',
            'winner',
            'status',
        ];
    }
}
