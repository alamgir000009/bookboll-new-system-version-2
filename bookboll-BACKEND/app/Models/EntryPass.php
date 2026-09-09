<?php

namespace App\Models;

use App\Traits\LogsModelActivity;
use App\Traits\GeneratesUuid;
use Illuminate\Database\Eloquent\Model;

class EntryPass extends Model
{
    use LogsModelActivity;
    use GeneratesUuid;

    protected $table = 'entry_passes';

    protected $fillable = [
        'uuid',
        'kind',
        'ref_id',
        'owner_user_id',
        'customer_id',
        'customer_email',
        'code',
    ];

    protected function activityLogName(): string
    {
        return 'entry_pass';
    }

    protected function activityLogAttributes(): array
    {
        return [
            'uuid',
            'kind',
            'ref_id',
            'owner_user_id',
            'customer_id',
            'customer_email',
        ];
    }
}
