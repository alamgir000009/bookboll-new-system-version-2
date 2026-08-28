<?php

namespace App\Models;

use App\Traits\GeneratesUuid;
use Illuminate\Database\Eloquent\Model;

class EntryPass extends Model
{
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
}
