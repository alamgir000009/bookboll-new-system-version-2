<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class StreetCred extends Model
{
    protected $table = 'street_cred';

    protected $fillable = [
        'game_uuid',
        'giver_uuid',
        'receiver_uuid',
        'badge',
    ];

    public const BADGE_LABELS = [
        'great_sport'    => ['label' => 'Great sport', 'emoji' => '🤝'],
        'clutch'         => ['label' => 'Clutch', 'emoji' => '⚡'],
        'hustle'         => ['label' => 'Hustler', 'emoji' => '🔥'],
        'wall'           => ['label' => 'The Wall', 'emoji' => '🧱'],
        'organizer_star' => ['label' => 'Star organizer', 'emoji' => '⭐'],
    ];
}
