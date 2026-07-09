<?php
namespace App\Models;

use App\Traits\LogsModelActivity;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Cache;

class Setting extends Model
{
    use LogsModelActivity;

    protected $table = 'settings';

    protected $fillable = [
        'key',
        'type',
        'value',
        'encrypted',
        'description',
    ];

    protected $guarded = ['id'];

    protected $casts = [
        'encrypted' => 'boolean',
        'value'     => 'string',
    ];

    public function scopeActive($query)
    {
        return $query->whereNotNull('value');
    }

    public static function exists(string $key): bool
    {
        return static::where('key', $key)->exists();
    }
    protected function activityLogName(): string
    {
        return 'setting';
    }

    protected function activityLogAttributes(): array
    {
        return [
            'key',
            'type',
            'value',
            'encrypted',
            'description',
        ];
    }

}