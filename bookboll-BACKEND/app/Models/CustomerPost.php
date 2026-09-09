<?php

namespace App\Models;

use App\Traits\LogsModelActivity;
use App\Traits\GeneratesUuid;
use Illuminate\Database\Eloquent\Model;

class CustomerPost extends Model
{
    use LogsModelActivity;
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

    protected function activityLogName(): string
    {
        return 'customer_post';
    }

    protected function activityLogAttributes(): array
    {
        return [
            'uuid',
            'author_uuid',
            'body',
        ];
    }
}
