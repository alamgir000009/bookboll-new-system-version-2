<?php
namespace App\Models;

use App\Traits\LogsModelActivity;

use Illuminate\Database\Eloquent\Model;

class Contact extends Model
{
    use LogsModelActivity;

    protected $fillable = [
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