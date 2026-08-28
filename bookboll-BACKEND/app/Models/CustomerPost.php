<?php

namespace App\Models;

use App\Traits\GeneratesUuid;
use Illuminate\Database\Eloquent\Model;

class CustomerPost extends Model
{
    use GeneratesUuid;

    protected $table = 'customer_posts';

    protected $fillable = [
        'uuid',
        'body',
        'photos',
        'author_uuid',
    ];

    protected function casts(): array
    {
        return [
            'photos' => 'array',
        ];
    }
}
