<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class CustomerLoyalty extends Model
{
    protected $table = 'customer_loyalty';

    protected $fillable = [
        'customer_id',
        'user_id',
        'country_id',
        'points',
        'lifetime_points',
    ];

    protected function casts(): array
    {
        return [
            'points'          => 'integer',
            'lifetime_points' => 'integer',
            'country_id'      => 'integer',
        ];
    }

    public static function ownerColumn(): string
    {
        static $column;
        if ($column === null) {
            $column = \Illuminate\Support\Facades\Schema::hasColumn('customer_loyalty', 'customer_id')
                ? 'customer_id'
                : 'user_id';
        }

        return $column;
    }

    public function customer(): BelongsTo
    {
        return $this->belongsTo(User::class, self::ownerColumn());
    }

    public function user(): BelongsTo
    {
        return $this->customer();
    }
}
