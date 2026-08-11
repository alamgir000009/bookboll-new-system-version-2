<?php
namespace App\Models;

use App\Traits\LogsModelActivity;
use App\Traits\TracksCreator;

use Illuminate\Database\Eloquent\Model;

class Contact extends Model
{
    use LogsModelActivity, TracksCreator;

    protected $fillable = [
        'created_by',
        'name',
        'email',
        'subject',
        'message',
    ];
    protected function activityLogName(): string
    {
        return 'contact';
    }

    protected function activityLogAttributes(): array
    {
        return [
            'name',
            'email',
            'subject',
            'message',
        ];
    }

}